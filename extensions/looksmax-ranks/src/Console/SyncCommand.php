<?php

namespace Local\Ranks\Console;

use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;
use Local\Ranks\Catalog;
use Local\Ranks\Standing;
use Symfony\Component\Console\Input\InputOption;

/**
 * Reconcile every denormalised identity column against the tables that own it.
 *
 * The serializer that runs on every user in every API response reads columns,
 * not joins — that is what keeps a 50-user discussion listing at zero extra
 * queries. The cost of that decision is that the columns can drift, so this is
 * the thing that makes drift a scheduled event rather than a bug report:
 *
 *   rank_slug     <- lifetime_points, through the ladder
 *   tier_slug     <- identity_memberships, expiring the lapsed ones
 *   group_user    <- tier_slug, so core permission checks agree with the badge
 *   name_style    <- unequipped if no longer owned or no longer permitted
 *   avatar_frame  <- same
 *   badge_count   <- identity_badges
 *
 * Run it after a backfill, after a bulk grant, and on a timer. --check makes it
 * report drift and write nothing, which is what belongs in a health probe.
 */
class SyncCommand extends AbstractCommand
{
    public function __construct(
        protected ConnectionInterface $db,
        protected Standing $standing
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('identity:sync')
            ->setDescription('Reconcile ranks, tiers, groups and equipped cosmetics against their source tables')
            ->addOption('check', null, InputOption::VALUE_NONE, 'report drift, write nothing');
    }

    protected function fire()
    {
        $check = (bool) $this->input->getOption('check');
        $drift = ['rank' => 0, 'tier' => 0, 'style' => 0, 'frame' => 0, 'badges' => 0, 'groups' => 0];

        // ---------------------------------------------------------- ranks
        $ladder = Catalog::RANKS;
        foreach ($this->db->table('users')->select('id', 'lifetime_points', 'rank_slug')->cursor() as $u) {
            $want = Catalog::rankFor((int) $u->lifetime_points)['slug'];
            if ($u->rank_slug !== $want) {
                $drift['rank']++;
                if (!$check) {
                    $this->db->table('users')->where('id', $u->id)->update(['rank_slug' => $want]);
                }
            }
        }

        // ---------------------------------------------------------- tiers
        // Only accounts that hold a membership row need looking at; the other
        // 1,400 are standard by definition and a full pass over them is waste.
        $withMembership = $this->db->table('identity_memberships')->distinct()->pluck('user_id');
        $now = date('Y-m-d H:i:s');

        foreach ($withMembership as $uid) {
            $before = $this->db->table('users')->where('id', $uid)->value('tier_slug');
            if ($check) {
                $rows = $this->db->table('identity_memberships')
                    ->where('user_id', $uid)->where('active', 1)
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
                    ->pluck('tier');
                $best = 'standard';
                $bestRank = 0;
                foreach ($rows as $t) {
                    if (Catalog::tier($t)['rank'] > $bestRank) {
                        $bestRank = Catalog::tier($t)['rank'];
                        $best = $t;
                    }
                }
                if ($before !== $best) {
                    $drift['tier']++;
                }

                continue;
            }

            $after = $this->standing->refreshTier((int) $uid)['slug'];
            if ($before !== $after) {
                $drift['tier']++;
            }
        }

        // A NULL tier and a 'standard' tier mean the same thing to every reader
        // of this data, which means two code paths for one state. Normalise.
        $this->db->table('users')->whereNull('tier_slug')->update(['tier_slug' => 'standard']);

        // Anyone with no membership rows at all must read as standard, not as a
        // stale tier left behind by a revoked grant.
        $stale = $this->db->table('users')
            ->whereNotNull('tier_slug')->where('tier_slug', '!=', 'standard')
            ->whereNotIn('id', $withMembership->all() ?: [0]);
        $drift['tier'] += (clone $stale)->count();
        if (!$check) {
            $stale->update(['tier_slug' => 'standard', 'tier_expires_at' => null]);
        }

        // ------------------------------------------------- equipped cosmetics
        // This is the check that lets the hot path skip the ownership lookup.
        $equipped = $this->db->table('users')
            ->where(fn ($q) => $q->whereNotNull('name_style')->orWhereNotNull('avatar_frame'))
            ->get(['id', 'name_style', 'avatar_frame', 'tier_slug', 'tier_expires_at']);

        foreach ($equipped as $u) {
            $tier = Catalog::tier(
                ($u->tier_expires_at && strtotime((string) $u->tier_expires_at) < time()) ? 'standard' : $u->tier_slug
            );

            if ($u->name_style) {
                $style = Catalog::style($u->name_style);
                $ok = $style
                    && ($style['kind'] === 'rank' || $this->standing->owns((int) $u->id, 'style', $u->name_style));
                if (!$ok) {
                    $drift['style']++;
                    if (!$check) {
                        $this->db->table('users')->where('id', $u->id)->update(['name_style' => null]);
                    }
                }
            }

            if ($u->avatar_frame) {
                $ok = Catalog::frame($u->avatar_frame) && $this->standing->owns((int) $u->id, 'frame', $u->avatar_frame);
                if (!$ok) {
                    $drift['frame']++;
                    if (!$check) {
                        $this->db->table('users')->where('id', $u->id)->update(['avatar_frame' => null]);
                    }
                }
            }
        }

        // --------------------------------------------------------- badges
        $wrong = $this->db->select(
            'SELECT COUNT(*) c FROM users u WHERE u.badge_count <> (SELECT COUNT(*) FROM identity_badges b WHERE b.user_id = u.id)'
        );
        $drift['badges'] = (int) ($wrong[0]->c ?? 0);
        if (!$check) {
            $this->db->statement(
                'UPDATE users u SET badge_count = (SELECT COUNT(*) FROM identity_badges b WHERE b.user_id = u.id)'
            );
        }

        // --------------------------------------------------------- groups
        $names = array_filter(array_column(Catalog::TIERS, 'group'));
        $groupIds = $this->db->table('groups')->whereIn('name_singular', $names)->pluck('id', 'name_singular');
        foreach (Catalog::TIERS as $t) {
            if (!$t['group'] || !isset($groupIds[$t['group']])) {
                continue;
            }
            $gid = $groupIds[$t['group']];

            $should = $this->db->table('users')->where('tier_slug', $t['slug'])->pluck('id');
            $has = $this->db->table('group_user')->where('group_id', $gid)->pluck('user_id');

            $add = array_diff($should->all(), $has->all());
            $remove = array_diff($has->all(), $should->all());
            $drift['groups'] += count($add) + count($remove);

            if (!$check) {
                foreach (array_chunk($add, 500) as $chunk) {
                    $this->db->table('group_user')->insertOrIgnore(
                        array_map(fn ($id) => ['user_id' => $id, 'group_id' => $gid], $chunk)
                    );
                }
                if ($remove) {
                    $this->db->table('group_user')->where('group_id', $gid)->whereIn('user_id', $remove)->delete();
                }
            }
        }

        $total = array_sum($drift);
        foreach ($drift as $k => $v) {
            $this->info(sprintf('  %-8s %s%d', $k, $check ? 'drift ' : 'fixed ', $v));
        }
        $this->info(($check ? 'drift total: ' : 'reconciled: ') . $total . ' across ' . count($ladder) . ' ranks and ' . count(Catalog::TIERS) . ' tiers');

        // A --check that cannot go red is not a check. Non-zero exit on drift is
        // what makes this usable from a health probe.
        return $check && $total > 0 ? 1 : 0;
    }
}
