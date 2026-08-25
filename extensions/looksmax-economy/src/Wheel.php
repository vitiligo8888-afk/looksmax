<?php

namespace Local\Economy;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * La ruleta diaria. Un giro por cuenta y día, premio en puntos.
 *
 * ── Quién decide el premio ──────────────────────────────────────────────────
 *
 * EL SERVIDOR, siempre. El cliente no elige nada y no puede: `spin()` sortea
 * aquí, acredita aquí, y devuelve el ÍNDICE del segmento para que la animación
 * aterrice donde ya se decidió. Una ruleta que sortea en el navegador es una
 * ruleta que se gana con la consola abierta.
 *
 * ── Por qué no hay tabla nueva ──────────────────────────────────────────────
 *
 * `economy_transactions` ya tiene un índice único sobre (user_id, reason, ref)
 * —`econ_once`, creado con las tablas de la economía— y `Ledger::credit()`
 * captura el fallo de inserción y devuelve 0. Con ref = "wheel:<AAAA-MM-DD>"
 * eso ES el candado del giro diario: dos peticiones simultáneas del mismo día
 * no pueden pagar dos veces, sin bloqueos y sin una tabla que mantener. La
 * misma fila guarda cuánto se ganó, así que el estado del día se reconstruye
 * leyendo el libro mayor en vez de duplicando el dato.
 *
 * El día es UTC vía Quests::dailyPeriod(), para que "diario" signifique lo
 * mismo aquí que en las misiones y no haya dos medianoches distintas.
 */
class Wheel
{
    /**
     * Los segmentos, en el orden en que se dibujan. `weight` es la
     * probabilidad relativa; no tienen que sumar nada en concreto.
     *
     * Valor esperado ≈ 21.6 puntos por día, deliberadamente por debajo de lo
     * que pagan las cuatro misiones diarias juntas (24): la ruleta es un
     * empujón por aparecer, no la vía principal para subir de rango.
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

    /** ref del giro de hoy para este usuario. */
    private function ref(): string
    {
        return 'wheel:' . Quests::dailyPeriod();
    }

    /** La fila del giro de hoy, o null si todavía no ha girado. */
    private function todayRow(int $userId): ?object
    {
        $row = $this->db->table('economy_transactions')
            ->where('user_id', $userId)
            ->where('reason', self::REASON)
            ->where('ref', $this->ref())
            ->first();

        return $row ?: null;
    }

    /**
     * Estado para pintar la ruleta: los segmentos, si puede girar, y qué sacó
     * si ya giró (para que recargar la página no borre el resultado).
     */
    public function state(int $userId): array
    {
        $prizes = array_map(fn ($p) => ['points' => (int) $p['points']], self::PRIZES);

        if (! $this->enabled()) {
            return ['enabled' => false, 'prizes' => $prizes, 'canSpin' => false, 'won' => null, 'resetsIn' => 0];
        }

        $row = $userId > 0 ? $this->todayRow($userId) : null;
        $won = $row ? (int) $row->delta : null;

        return [
            'enabled' => true,
            'prizes' => $prizes,
            'canSpin' => $userId > 0 && $row === null,
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
     * El primer segmento que paga esa cantidad. Se usa solo para volver a
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
     * Gira. Devuelve ['index' => int, 'points' => int] o null si no procede
     * (desactivada, invitado, o ya giró hoy).
     */
    public function spin(int $userId): ?array
    {
        if (! $this->enabled() || $userId <= 0) {
            return null;
        }

        if ($this->todayRow($userId) !== null) {
            return null;
        }

        $index = $this->draw();
        $points = (int) self::PRIZES[$index]['points'];

        // countsForRank: false. Los puntos de la ruleta gastan pero NO suben de
        // rango: el rango mide lo que aportaste al foro, y la suerte no aporta.
        $paid = $this->ledger->credit($userId, $points, self::REASON, $this->ref(), false);

        if ($paid === 0) {
            // Otra petición ganó la carrera dentro del mismo día. El índice
            // sorteado aquí ya no vale nada: manda lo que se cobró de verdad.
            $row = $this->todayRow($userId);
            if ($row === null) {
                return null;
            }
            $already = (int) $row->delta;

            return ['index' => $this->indexOfPoints($already) ?? 0, 'points' => $already, 'duplicate' => true];
        }

        return ['index' => $index, 'points' => $points];
    }

    /** Sorteo ponderado sobre PRIZES. */
    private function draw(): int
    {
        $total = 0;
        foreach (self::PRIZES as $p) {
            $total += (int) $p['weight'];
        }

        // random_int, no mt_rand: es dinero del foro y no cuesta nada usar el
        // generador criptográfico.
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
