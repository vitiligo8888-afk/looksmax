<?php

namespace Local\Reactions\Console;

use Flarum\Console\AbstractCommand;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Builder;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create (or repair) the Chris admin account.
 *
 *   php flarum lmx:chris --password=... [--face=rich] [--style=obsidian]
 *
 * Three things, each of which has a wrong easy way:
 *
 * 1. ADMIN. Real membership of group 1, not a permission grant that looks like
 *    admin until something checks isAdmin().
 *
 * 2. AVATAR. Flarum stores `users.avatar_url` as a BARE FILENAME and serves it
 *    from /assets/avatars/. Writing a URL there produces a 404 that renders as
 *    the generated-initials avatar, which looks like it worked. The file goes
 *    through the `flarum-avatars` disk so it lands wherever that disk points,
 *    and the command re-reads the row afterwards and prints the resolved URL.
 *
 * 3. THE NAME TREATMENT, in two layers, on purpose:
 *
 *    a. A real grant in looksmax-ranks' own tables — a permanent
 *       identity_memberships row for the tier, a permanent identity_inventory
 *       row for the style, and the denormalised users.tier_slug/name_style the
 *       hot path actually reads. All three are needed: `php flarum
 *       identity:sync` NULLs name_style when there is no inventory row and
 *       resets tier_slug when there is no membership row, so writing only the
 *       users columns produces a treatment that silently vanishes on the next
 *       sync. This layer means Chris renders correctly in all six surfaces the
 *       ranks decorator paints (post headers, listings, user cards, shoutbox,
 *       index sidebar, leaderboards) with no help from this extension.
 *
 *    b. A bespoke treatment shipped from THIS extension's LESS
 *       (less/chris.less, class .lmxr-vip), which layers a richer animated
 *       gradient, a glow and a shimmer sweep over the top. The catalogue in
 *       looksmax-ranks is owned by another lane and is not editable from here,
 *       so a genuinely new treatment has to come from outside it — and it is
 *       keyed on the username so it needs no cooperation from that extension.
 *
 *    Layer (a) is the floor and survives this extension being disabled;
 *    layer (b) is the ceiling. Neither depends on the other.
 *
 * The password is never written into the repo. Pass --password, or set
 * LMX_CHRIS_PASSWORD, or let it generate one — in which case it is printed
 * once and written to --credentials-file, which lives outside the repo.
 */
class ChrisCommand extends AbstractCommand
{
    protected $signature = 'lmx:chris';

    // Nothing exotic in this constructor, on purpose. Flarum builds EVERY
    // registered console command at console boot, before it knows which one you
    // asked for, so an unresolvable dependency here does not break `lmx:chris`
    // — it breaks `php flarum migrate`, `cache:clear` and `info` too, which is
    // how this lane turned a one-command problem into an un-deployable
    // extension twice in a row. Two attempts, both fatal at boot:
    //
    //   Flarum\Filesystem\FilesystemManager  -> BindingResolutionException:
    //       unresolvable primitive $diskLocalConfig (it is a plain array)
    //   Flarum\Filesystem\FilesystemFactory  -> does not exist in 1.8 at all
    //
    // The filesystem is bound under the STRING key 'filesystem'
    // (core/src/Filesystem/FilesystemServiceProvider.php:61), so it is resolved
    // lazily inside fire() where a failure is this command's problem alone.
    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct();
    }

    private function avatars()
    {
        return resolve('filesystem')->disk('flarum-avatars');
    }

    private function schema(): Builder
    {
        return $this->db->getSchemaBuilder();
    }

    protected function configure(): void
    {
        $this->setName('lmx:chris')
            ->setDescription('Create or repair the Chris admin account, avatar and name treatment')
            ->addOption('username', null, InputOption::VALUE_REQUIRED, '', 'Chris')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, '', 'chris@looksmax.lat')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, '', null)
            ->addOption('face', null, InputOption::VALUE_REQUIRED,
                'Which chrigger face to use as the avatar', 'rich')
            ->addOption('style', null, InputOption::VALUE_REQUIRED,
                'looksmax-ranks name style slug', 'obsidian')
            ->addOption('tier', null, InputOption::VALUE_REQUIRED,
                'looksmax-ranks tier slug (must be high enough for the style)', 'founder')
            ->addOption('credentials-file', null, InputOption::VALUE_REQUIRED,
                'Where to write a generated password', '/flarum/app/storage/chris-credentials.txt');
    }

    protected function fire(): int
    {
        $username = (string) $this->input->getOption('username');
        $email = (string) $this->input->getOption('email');
        $face = (string) $this->input->getOption('face');

        $password = $this->input->getOption('password')
            ?: getenv('LMX_CHRIS_PASSWORD')
            ?: null;
        $generated = false;
        if (!$password) {
            $password = bin2hex(random_bytes(12));
            $generated = true;
        }

        $user = User::query()->where('username', $username)->first();
        if (!$user) {
            $user = User::register($username, $email, $password);
            $user->activate();
            $user->save();
            $this->info("created user #{$user->id} $username");
        } else {
            $user->changePassword($password);
            $user->changeEmail($email);
            $user->activate();
            $user->save();
            $this->info("updated existing user #{$user->id} $username");
        }

        // ---- admin, for real
        if (!$user->groups->contains(Group::ADMINISTRATOR_ID)) {
            $user->groups()->attach(Group::ADMINISTRATOR_ID);
            $user->refresh();
        }
        $isAdmin = $user->fresh()->isAdmin() ? 'yes' : 'NO';
        $this->info("admin: $isAdmin (groups: "
            . $user->fresh()->groups->pluck('id')->implode(',') . ')');

        // ---- avatar
        $src = __DIR__ . "/../../assets/chrigger/256/$face.png";
        if (!is_readable($src)) {
            $this->error("no such face asset: $src");

            return 1;
        }
        $filename = $user->id . '.png';
        $this->avatars()->put($filename, file_get_contents($src));
        $this->db->table('users')->where('id', $user->id)->update(['avatar_url' => $filename]);
        $resolved = $this->avatars()->url($filename);
        $this->info("avatar: $face -> $filename -> $resolved");

        // ---- name treatment, layer (a): a real grant in looksmax-ranks
        $style = (string) $this->input->getOption('style');
        $tier = (string) $this->input->getOption('tier');
        $granted = $this->grantIdentity((int) $user->id, $style, $tier);
        $this->info('ranks grant: ' . ($granted ?: 'skipped (looksmax-ranks tables absent)'));

        // ---- name treatment, layer (b) is pure CSS in less/chris.less and
        //      needs no data at all; report the selector so it is greppable
        $this->info('bespoke treatment: less/chris.less, a[href$="/u/' . $username . '"] .lmxr-vip');

        if ($generated) {
            $file = (string) $this->input->getOption('credentials-file');
            @file_put_contents($file, "username=$username\npassword=$password\n");
            @chmod($file, 0600);
            $this->info("generated password written to $file (mode 0600, outside the repo)");
            $this->output->writeln('');
            $this->output->writeln("  username: $username");
            $this->output->writeln("  password: $password");
            $this->output->writeln('');
        } else {
            $this->info('password set from the supplied value; not echoed and not written anywhere');
        }

        return 0;
    }

    /**
     * Write the three rows looksmax-ranks needs. Direct DB writes rather than
     * a call into Local\Ranks\Standing: that class belongs to another lane and
     * calling it would make this extension fail to load whenever that one is
     * absent. Every write is guarded on the table/column existing, so this
     * degrades to a no-op instead of a fatal.
     */
    private function grantIdentity(int $userId, string $style, string $tier): string
    {
        $done = [];
        $now = date('Y-m-d H:i:s');

        if ($this->schema()->hasTable('identity_memberships')) {
            $this->db->table('identity_memberships')
                ->where('user_id', $userId)->where('tier', $tier)->delete();
            $this->db->table('identity_memberships')->insert([
                'user_id' => $userId, 'tier' => $tier, 'source' => 'grant',
                'paid' => 0, 'started_at' => $now, 'expires_at' => null, 'active' => 1,
            ]);
            $done[] = "membership($tier)";
        }

        if ($this->schema()->hasTable('identity_inventory')) {
            $this->db->table('identity_inventory')
                ->where('user_id', $userId)->where('type', 'style')->where('item', $style)->delete();
            $this->db->table('identity_inventory')->insert([
                'user_id' => $userId, 'type' => 'style', 'item' => $style,
                'source' => 'award', 'paid' => 0, 'acquired_at' => $now, 'expires_at' => null,
            ]);
            $done[] = "inventory(style:$style)";
        }

        $cols = [];
        foreach (['tier_slug' => $tier, 'name_style' => $style, 'tier_expires_at' => null] as $c => $v) {
            if ($this->schema()->hasColumn('users', $c)) {
                $cols[$c] = $v;
            }
        }
        if ($cols) {
            $this->db->table('users')->where('id', $userId)->update($cols);
            $done[] = 'users(' . implode(',', array_keys($cols)) . ')';
        }

        return implode(' + ', $done);
    }
}
