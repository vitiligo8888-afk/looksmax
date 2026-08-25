<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * La ruleta. Un giro gratis al día, y más pagando con los mismos puntos que
 * dan las misiones.
 *
 * ── Quién decide el premio ──────────────────────────────────────────────────
 *
 * EL SERVIDOR, siempre. `spin()` sortea aquí, cobra aquí, acredita aquí, y
 * devuelve el ÍNDICE del segmento para que la animación aterrice donde ya se
 * decidió. Una ruleta que sortea en el navegador se gana con la consola
 * abierta.
 *
 * ── La forma de los premios: pocos aciertos, premios gordos ─────────────────
 *
 * 62% de los giros no dan nada. A cambio el bote son 2500 puntos: cincuenta
 * veces lo que cuesta el giro, 1 de cada 200. Esa es la petición explícita del
 * operador, y es también lo que hace que la ruleta valga la pena mirarla — una
 * que siempre paga algo pequeño no emociona a nadie y además drena poco.
 *
 * Valor esperado 33.25 sobre un coste de 50: devuelve el 66%. La economía
 * reparte puntos por publicar, por reaccionar, por rachas y por misiones, y no
 * tenía casi nada que los sacara de circulación; esto es el SUMIDERO. Al 100%
 * no drenaría nada y al 30% se sentiría a estafa.
 *
 * ── Por qué existe wheel.roll ───────────────────────────────────────────────
 *
 * Antes el candado del giro gratis era la fila del premio. Con segmentos que
 * pagan 0 eso se rompe: un premio de cero no escribe fila, no deja candado, y
 * se podría girar gratis una y otra vez hasta acertar. Así que cada giro
 * escribe SIEMPRE una fila `wheel.roll` de delta 0, que es a la vez el candado
 * (índice único sobre user_id+reason+ref) y la prueba de que el giro ocurrió.
 * El premio, si lo hay, va aparte en `wheel.spin`.
 *
 * El día es UTC vía Quests::dailyPeriod(), para que "diario" signifique lo
 * mismo aquí que en las misiones y no haya dos medianoches distintas.
 */
class Wheel
{
    /**
     * Los segmentos, EN EL ORDEN EN QUE SE DIBUJAN. Los ceros van intercalados
     * a propósito: agrupados se leerían como media ruleta muerta, repartidos
     * hacen que cada premio quede rodeado de vacío, que es como se ve una
     * ruleta de verdad.
     *
     * `weight` es la probabilidad relativa; suman 1000 para que cada peso se
     * lea directamente como décimas de por ciento.
     *
     * Viven en código y no en ajustes: cambiar un peso mueve el valor esperado,
     * y con él la relación entre lo que cuesta girar y lo que la economía drena.
     * Eso no debe poder tocarse desde un formulario sin rehacer la cuenta.
     */
    public const PRIZES = [
        ['points' => 0,    'weight' => 104],
        ['points' => 10,   'weight' => 150],
        ['points' => 0,    'weight' => 104],
        ['points' => 150,  'weight' => 40],
        ['points' => 0,    'weight' => 103],
        ['points' => 25,   'weight' => 110],
        ['points' => 0,    'weight' => 103],
        ['points' => 500,  'weight' => 15],
        ['points' => 0,    'weight' => 103],
        ['points' => 50,   'weight' => 60],
        ['points' => 0,    'weight' => 103],
        ['points' => 2500, 'weight' => 5],
    ];

    public const REASON = 'wheel.spin';
    public const REASON_COST = 'wheel.cost';
    public const REASON_ROLL = 'wheel.roll';

    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function enabled(): bool
    {
        return (bool) Config::get($this->settings, 'wheel.enabled');
    }

    public function cost(): int
    {
        return max(0, (int) Config::get($this->settings, 'wheel.cost'));
    }

    public function paidMax(): int
    {
        return max(0, (int) Config::get($this->settings, 'wheel.paid_max'));
    }

    private function today(): string
    {
        return Quests::dailyPeriod();
    }

    private function freeRef(): string
    {
        return 'wheel:' . $this->today();
    }

    private function paidRef(int $n): string
    {
        return 'wheel:' . $this->today() . ':p' . $n;
    }

    /**
     * Marca el giro. Devuelve false si ese ref ya existía, que es exactamente
     * la carrera: dos peticiones simultáneas, una entra y la otra se va sin
     * premio y sin haber cobrado nada.
     */
    private function lockSpin(int $userId, string $ref): bool
    {
        try {
            $this->db->table('economy_transactions')->insert([
                'user_id' => $userId,
                'delta' => 0,
                'reason' => self::REASON_ROLL,
                'ref' => $ref,
                'actor_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * ¿Ya usó el giro gratis de hoy?
     *
     * Mira el marcador `wheel.roll` Y, por compatibilidad, la fila de premio con
     * el ref del día: antes de que existieran los segmentos sin premio el
     * candado ERA la fila del premio, y quien hubiera girado el mismo día del
     * despliegue habría recibido un segundo giro gratis. Medido en producción
     * al desplegar esto, no en teoría.
     */
    private function freeUsed(int $userId): bool
    {
        return $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->whereIn('reason', [self::REASON_ROLL, self::REASON])
            ->where('ref', $this->freeRef())
            ->exists();
    }

    /** Cuántos giros de pago lleva hoy. Se cuentan por el COBRO: si algo
     *  fallara entre cobrar y sortear, el giro ya está gastado igual. */
    private function paidCount(int $userId): int
    {
        return (int) $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON_COST)
            ->where('ref', 'like', 'wheel:' . $this->today() . ':p%')
            ->count();
    }

    private function balance(int $userId): int
    {
        return (int) ($this->db->table('users')->where('id', $userId)->value('points') ?? 0);
    }

    /**
     * El resultado del último giro de hoy: la cantidad, o 0 si ese giro no
     * pagó nada. null solo cuando todavía no ha girado.
     *
     * Se busca primero el ROLL más reciente y después su premio, no al revés:
     * un giro sin premio no tiene fila en `wheel.spin`, y mirar solo los
     * premios devolvería el de hace tres giros como si fuera el último.
     */
    private function lastResult(int $userId): ?int
    {
        $roll = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON_ROLL)
            ->where('ref', 'like', 'wheel:' . $this->today() . '%')
            ->orderBy('id', 'desc')
            ->first();

        if (! $roll) {
            return null;
        }

        $prize = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON)
            ->where('ref', $roll->ref)
            ->value('delta');

        return (int) ($prize ?? 0);
    }

    /**
     * Estado para pintar la ruleta. Distingue "puedes girar gratis" de "puedes
     * pagar por otro", porque el botón no dice lo mismo en los dos casos y
     * cobrar sin avisar sería lo peor que podría hacer esta pantalla.
     */
    public function state(int $userId): array
    {
        $prizes = array_map(fn ($p) => ['points' => (int) $p['points']], self::PRIZES);
        $cost = $this->cost();
        $max = $this->paidMax();

        $base = [
            'enabled' => $this->enabled(),
            'prizes' => $prizes,
            'jackpot' => $this->jackpot(),
            'cost' => $cost,
            'paidMax' => $max,
            'paidToday' => 0,
            'balance' => 0,
            'freeAvailable' => false,
            'canSpinPaid' => false,
            'canSpin' => false,
            'won' => null,
            'wonIndex' => null,
            'resetsIn' => $this->secondsToReset(),
        ];

        if (! $base['enabled'] || $userId <= 0) {
            return $base;
        }

        $free = ! $this->freeUsed($userId);
        $paid = $this->paidCount($userId);
        $balance = $this->balance($userId);
        $won = $this->lastResult($userId);

        $canPaid = ! $free && $paid < $max && $cost > 0 && $balance >= $cost;

        return array_merge($base, [
            'freeAvailable' => $free,
            'canSpinPaid' => $canPaid,
            'canSpin' => $free || $canPaid,
            'paidToday' => $paid,
            'balance' => $balance,
            'won' => $won,
            'wonIndex' => $won === null ? null : $this->indexOfPoints($won),
        ]);
    }

    /** El premio mayor de la tabla, para poder anunciarlo sin repetir el número. */
    public function jackpot(): int
    {
        $max = 0;
        foreach (self::PRIZES as $p) {
            $max = max($max, (int) $p['points']);
        }

        return $max;
    }

    /** Segundos hasta la próxima medianoche UTC. */
    private function secondsToReset(): int
    {
        return max(0, strtotime(gmdate('Y-m-d') . ' 23:59:59 UTC') + 1 - time());
    }

    /**
     * El primer segmento que paga esa cantidad. Solo se usa para volver a
     * señalar un resultado ya conocido; con seis segmentos a cero, un giro sin
     * premio apunta al primero de ellos, que es indistinguible del resto.
     */
    private function indexOfPoints(int $points): ?int
    {
        foreach (self::PRIZES as $i => $p) {
            if ((int) $p['points'] === $points) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Gira. Devuelve ['index','points','paid','cost'] o null si no procede.
     *
     * Orden deliberado en el giro de pago: primero se COBRA, después se sortea.
     * Al revés, un cobro que falla justo después de un buen premio dejaría al
     * usuario viendo algo que no se le pagó.
     */
    public function spin(int $userId): ?array
    {
        if (! $this->enabled() || $userId <= 0) {
            return null;
        }

        // 1) El gratuito del día, si queda. El candado es el propio roll: si
        //    otra petición entró primero, esta se va sin sortear.
        if (! $this->freeUsed($userId)) {
            if (! $this->lockSpin($userId, $this->freeRef())) {
                return null;
            }

            return $this->settle($userId, $this->freeRef(), false, 0);
        }

        // 2) Uno de pago.
        $cost = $this->cost();
        $n = $this->paidCount($userId) + 1;

        if ($cost <= 0 || $n > $this->paidMax()) {
            return null;
        }

        // spend() bloquea la fila del usuario dentro de una transacción y
        // devuelve 0 si no alcanza el saldo O si el ref ya existe. Lo segundo
        // es la carrera: dos pestañas que calculen el mismo n, una cobra y la
        // otra se va de vacío sin haber pagado.
        if ($this->ledger->spend($userId, $cost, self::REASON_COST, $this->paidRef($n)) === 0) {
            return null;
        }

        $this->lockSpin($userId, $this->paidRef($n));

        return $this->settle($userId, $this->paidRef($n), true, $cost);
    }

    /** Sortea y paga, si hay algo que pagar. */
    private function settle(int $userId, string $ref, bool $paid, int $cost): array
    {
        $index = $this->draw();
        $points = (int) self::PRIZES[$index]['points'];

        if ($points > 0) {
            // countsForRank: false. Los puntos de la ruleta gastan pero NO suben
            // de rango: el rango mide lo que aportaste al foro, y la suerte no
            // aporta nada.
            $this->ledger->credit($userId, $points, self::REASON, $ref, false);
        }

        return ['index' => $index, 'points' => $points, 'paid' => $paid, 'cost' => $cost];
    }

    /** Sorteo ponderado sobre PRIZES. */
    private function draw(): int
    {
        $total = 0;
        foreach (self::PRIZES as $p) {
            $total += (int) $p['weight'];
        }

        // random_int, no mt_rand: es dinero del foro y el generador
        // criptográfico no cuesta nada aquí.
        $roll = random_int(1, max(1, $total));

        $acc = 0;
        foreach (self::PRIZES as $i => $p) {
            $acc += (int) $p['weight'];
            if ($roll <= $acc) {
                return $i;
            }
        }

        return count(self::PRIZES) - 1;
    }
}
