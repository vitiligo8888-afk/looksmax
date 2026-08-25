<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * La ruleta. Un giro gratis al día, y más giros pagando con los mismos puntos
 * que dan las misiones.
 *
 * ── Quién decide el premio ──────────────────────────────────────────────────
 *
 * EL SERVIDOR, siempre. `spin()` sortea aquí, cobra aquí, acredita aquí, y
 * devuelve el ÍNDICE del segmento para que la animación aterrice donde ya se
 * decidió. Una ruleta que sortea en el navegador se gana con la consola
 * abierta.
 *
 * ── Los dos tipos de giro ───────────────────────────────────────────────────
 *
 * El gratuito usa ref "wheel:<AAAA-MM-DD>". Los de pago, "wheel:<fecha>:p<n>".
 * Esa distinción no es cosmética: `economy_transactions` tiene un índice único
 * sobre (user_id, reason, ref), así que el ref ES el candado. El gratuito no
 * puede cobrarse dos veces el mismo día, y dos peticiones simultáneas que
 * calculen el mismo n de pago chocan en el índice y una se rechaza limpiamente
 * en vez de pagar dos premios por un cobro.
 *
 * ── Por qué cuesta más de lo que devuelve ───────────────────────────────────
 *
 * Valor esperado ≈ 21.6 puntos. A 25 el giro, devuelve ~86%. La ruleta es un
 * SUMIDERO: la economía reparte puntos por publicar, por reaccionar, por rachas
 * y por misiones, y no tenía casi nada que los sacara de circulación. Un
 * sumidero que devolviera el 100% no drena nada, y uno al 50% se siente a robo.
 *
 * El día es UTC vía Quests::dailyPeriod(), para que "diario" signifique lo
 * mismo aquí que en las misiones y no haya dos medianoches distintas.
 */
class Wheel
{
    /**
     * Los segmentos, en el orden en que se dibujan. `weight` es la probabilidad
     * relativa; no tienen que sumar nada en concreto.
     *
     * Viven en código y no en ajustes a propósito: cambiar un peso mueve el
     * valor esperado, y con él la relación entre el coste del giro y lo que la
     * economía drena. Eso no debe poder tocarse desde un formulario sin volver
     * a hacer la cuenta.
     */
    public const PRIZES = [
        ['points' => 5,   'weight' => 26],
        ['points' => 10,  'weight' => 22],
        ['points' => 15,  'weight' => 17],
        ['points' => 20,  'weight' => 13],
        ['points' => 30,  'weight' => 10],
        ['points' => 50,  'weight' => 7],
        ['points' => 100, 'weight' => 4],
        ['points' => 250, 'weight' => 1],
    ];

    public const REASON = 'wheel.spin';
    public const REASON_COST = 'wheel.cost';

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

    private function freeRef(): string
    {
        return 'wheel:' . Quests::dailyPeriod();
    }

    private function paidRef(int $n): string
    {
        return 'wheel:' . Quests::dailyPeriod() . ':p' . $n;
    }

    /** El giro gratuito de hoy, o null si aún no lo ha usado. */
    private function freeRow(int $userId): ?object
    {
        $row = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON)
            ->where('ref', $this->freeRef())
            ->first();

        return $row ?: null;
    }

    /** Cuántos giros de pago lleva hoy. Se cuentan por el COBRO, no por el
     *  premio: si algo fallara entre cobrar y pagar, el giro ya está gastado. */
    private function paidCount(int $userId): int
    {
        return (int) $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON_COST)
            ->where('ref', 'like', 'wheel:' . Quests::dailyPeriod() . ':p%')
            ->count();
    }

    private function balance(int $userId): int
    {
        return (int) ($this->db->table('users')->where('id', $userId)->value('points') ?? 0);
    }

    /** El último premio de hoy, sea del giro gratis o de uno de pago. */
    private function lastWin(int $userId): ?int
    {
        $row = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON)
            ->where('ref', 'like', 'wheel:' . Quests::dailyPeriod() . '%')
            ->orderBy('id', 'desc')
            ->first();

        return $row ? (int) $row->delta : null;
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

        if (! $this->enabled()) {
            return [
                'enabled' => false, 'prizes' => $prizes,
                'canSpin' => false, 'freeAvailable' => false, 'canSpinPaid' => false,
                'cost' => $cost, 'paidToday' => 0, 'paidMax' => $max,
                'balance' => 0, 'won' => null, 'wonIndex' => null, 'resetsIn' => 0,
            ];
        }

        if ($userId <= 0) {
            return [
                'enabled' => true, 'prizes' => $prizes,
                'canSpin' => false, 'freeAvailable' => false, 'canSpinPaid' => false,
                'cost' => $cost, 'paidToday' => 0, 'paidMax' => $max,
                'balance' => 0, 'won' => null, 'wonIndex' => null,
                'resetsIn' => $this->secondsToReset(),
            ];
        }

        $free = $this->freeRow($userId) === null;
        $paid = $this->paidCount($userId);
        $balance = $this->balance($userId);
        $won = $this->lastWin($userId);

        $canPaid = ! $free && $paid < $max && $cost > 0 && $balance >= $cost;

        return [
            'enabled' => true,
            'prizes' => $prizes,
            'freeAvailable' => $free,
            'canSpinPaid' => $canPaid,
            'canSpin' => $free || $canPaid,
            'cost' => $cost,
            'paidToday' => $paid,
            'paidMax' => $max,
            'balance' => $balance,
            'won' => $won,
            'wonIndex' => $won === null ? null : $this->indexOfPoints($won),
            'resetsIn' => $this->secondsToReset(),
        ];
    }

    /** Segundos hasta la próxima medianoche UTC. */
    private function secondsToReset(): int
    {
        return max(0, strtotime(gmdate('Y-m-d') . ' 23:59:59 UTC') + 1 - time());
    }

    /**
     * El primer segmento que paga esa cantidad. Solo se usa para volver a
     * señalar un premio ya ganado; si dos segmentos pagaran lo mismo daría el
     * primero, que visualmente es indistinguible.
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
     * Al revés, un sorteo malo seguido de un cobro fallido dejaría al usuario
     * viendo un premio que no se le pagó, y uno bueno seguido de fallo sería
     * peor todavía.
     */
    public function spin(int $userId): ?array
    {
        if (! $this->enabled() || $userId <= 0) {
            return null;
        }

        // 1) El gratuito del día, si queda.
        if ($this->freeRow($userId) === null) {
            $index = $this->draw();
            $points = (int) self::PRIZES[$index]['points'];

            // countsForRank: false. Los puntos de la ruleta gastan pero NO suben
            // de rango: el rango mide lo que aportaste al foro, y la suerte no
            // aporta nada.
            $paid = $this->ledger->credit($userId, $points, self::REASON, $this->freeRef(), false);

            if ($paid === 0) {
                // Otra petición ganó la carrera. Manda lo que se cobró de verdad.
                $row = $this->freeRow($userId);
                if ($row === null) {
                    return null;
                }
                $already = (int) $row->delta;

                return ['index' => $this->indexOfPoints($already) ?? 0, 'points' => $already, 'paid' => false, 'cost' => 0];
            }

            return ['index' => $index, 'points' => $points, 'paid' => false, 'cost' => 0];
        }

        // 2) Uno de pago.
        $cost = $this->cost();
        $n = $this->paidCount($userId) + 1;

        if ($cost <= 0 || $n > $this->paidMax()) {
            return null;
        }

        // spend() bloquea la fila del usuario dentro de una transacción y
        // devuelve 0 si no alcanza el saldo O si el ref ya existe. Lo segundo es
        // la carrera: dos pestañas que calculen el mismo n, una cobra y la otra
        // se va de vacío sin haber pagado.
        $charged = $this->ledger->spend($userId, $cost, self::REASON_COST, $this->paidRef($n));

        if ($charged === 0) {
            return null;
        }

        $index = $this->draw();
        $points = (int) self::PRIZES[$index]['points'];

        $this->ledger->credit($userId, $points, self::REASON, $this->paidRef($n), false);

        return ['index' => $index, 'points' => $points, 'paid' => true, 'cost' => $cost];
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
