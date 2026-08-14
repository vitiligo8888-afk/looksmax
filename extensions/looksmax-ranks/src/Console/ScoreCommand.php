<?php

namespace Local\Ranks\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Economy\Ledger;
use Symfony\Component\Console\Input\InputOption;

/**
 * Progression driven by whether anyone actually read the thing.
 *
 * This is the part of the system that no other forum can copy, because it needs
 * an event stream that records dwell time and scroll depth per reader per
 * thread — `flarum-analytics` emits `discussion.dwell` with `read_pct` and
 * `dwell_ms`, and almost nothing else in the Flarum ecosystem has that.
 *
 * Why it matters: a post count rewards volume, a like count rewards agreement,
 * and neither can distinguish a thread that 200 people read to the bottom from
 * one that 200 people bounced off in four seconds. Both look identical to
 * `fof/gamification`. Here they do not.
 *
 * Three award paths, each keyed on a ref so re-running is free:
 *
 *   post.read_through  a distinct reader reached >= 85% of your thread
 *   thread.held        a reader spent >= 180s in your thread
 *   streak.day         you posted on a day you had not posted before
 *
 * Self-reads are excluded, because rereading your own thread is not readership.
 */
class ScoreCommand extends AbstractCommand
{
    private const DEEP_PCT = 85;
    private const HELD_SECONDS = 180;

    public function __construct(
        protected ConnectionInterface $db,
        protected Ledger $ledger
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('identity:score')
            ->setDescription('Award progression from real reading behaviour in the analytics stream')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'only events newer than this many hours', '168')
            ->addOption('streaks', null, InputOption::VALUE_NONE, 'also award posting streaks')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'report, write nothing');
    }

    protected function fire()
    {
        $dry = (bool) $this->input->getOption('dry-run');
        $since = date('Y-m-d H:i:s', time() - max(1, (int) $this->input->getOption('since')) * 3600);

        $deep = $this->db->table('analytics_events AS a')
            ->join('discussions AS d', 'd.id', '=', 'a.discussion_id')
            ->where('a.type', 'discussion.dwell')
            ->where('a.created_at', '>', $since)
            ->whereNotNull('d.user_id')
            // reading your own thread is not readership
            ->whereRaw('(a.user_id IS NULL OR a.user_id <> d.user_id)')
            ->whereRaw("CAST(JSON_VALUE(a.props, '$.read_pct') AS UNSIGNED) >= " . self::DEEP_PCT)
            ->selectRaw('d.user_id AS author, a.discussion_id AS did, a.session AS sess')
            ->distinct()
            ->get();

        $held = $this->db->table('analytics_events AS a')
            ->join('discussions AS d', 'd.id', '=', 'a.discussion_id')
            ->where('a.type', 'discussion.dwell')
            ->where('a.created_at', '>', $since)
            ->whereNotNull('d.user_id')
            ->whereRaw('(a.user_id IS NULL OR a.user_id <> d.user_id)')
            ->whereRaw("CAST(JSON_VALUE(a.props, '$.dwell_ms') AS UNSIGNED) >= " . self::HELD_SECONDS * 1000)
            ->selectRaw('d.user_id AS author, a.discussion_id AS did, a.session AS sess')
            ->distinct()
            ->get();

        $readAwards = 0;
        $heldAwards = 0;
        $points = 0;

        foreach ($deep as $row) {
            if ($dry) {
                $readAwards++;

                continue;
            }
            $p = $this->ledger->award((int) $row->author, 'post.read_through', 'read:' . $row->did . ':' . substr((string) $row->sess, 0, 16));
            if ($p) {
                $readAwards++;
                $points += $p;
            }
        }

        foreach ($held as $row) {
            if ($dry) {
                $heldAwards++;

                continue;
            }
            $p = $this->ledger->award((int) $row->author, 'thread.held', 'held:' . $row->did . ':' . substr((string) $row->sess, 0, 16));
            if ($p) {
                $heldAwards++;
                $points += $p;
            }
        }

        $streaks = 0;
        if ($this->input->getOption('streaks')) {
            $streaks = $this->awardStreaks($dry, $points);
        }

        $this->info('window                     : since ' . $since);
        $this->info('deep-read events considered: ' . $deep->count());
        $this->info('read-through awards written: ' . $readAwards);
        $this->info('held-attention awards      : ' . $heldAwards);
        $this->input->getOption('streaks') && $this->info('streak days awarded        : ' . $streaks);
        $this->info('points credited            : ' . number_format($points));

        if ($deep->isEmpty() && $held->isEmpty()) {
            $this->info('');
            $this->info('note: no dwell events in the window. The analytics client fires');
            $this->info('discussion.dwell on pagehide, so this stays at zero until real');
            $this->info('readers have been through the forum. That is an empty input, not a failure.');
        }

        return 0;
    }

    /**
     * One award per distinct day a user posted, plus a bonus on every seventh.
     *
     * Deliberately not "consecutive days": a streak that punishes a week off is
     * a mechanic that makes a forum feel like a job, and the accounts it selects
     * for are the ones posting filler to protect a number.
     */
    private function awardStreaks(bool $dry, int &$points): int
    {
        $rows = $this->db->table('posts')
            ->whereNotNull('user_id')->whereNull('hidden_at')
            ->selectRaw('user_id, DATE(created_at) d')
            ->distinct()->get();

        $n = 0;
        $perUser = [];
        foreach ($rows as $r) {
            $perUser[$r->user_id][] = $r->d;
        }

        foreach ($perUser as $uid => $days) {
            sort($days);
            foreach ($days as $i => $day) {
                if ($dry) {
                    $n++;

                    continue;
                }
                $p = $this->ledger->award((int) $uid, 'streak.day', 'day:' . $day);
                if ($p) {
                    $n++;
                    $points += $p;
                }
                if (($i + 1) % 7 === 0) {
                    $points += $this->ledger->award((int) $uid, 'streak.week', 'week:' . $day);
                }
            }
        }

        return $n;
    }
}
