# Email on looksmax.lat — current state and how to finish it

_Last verified 2026-08-25._

## Where things stand

Outbound email **cannot leave this server**, and it never could. `mail_driver`
was `mail`, PHP's `sendmail_path` is `/usr/sbin/sendmail`, and in this image
that is a symlink to **busybox**. Swiftmailer invokes it with `-bs`; busybox
rejects the option and throws. That exception fired *inside* the registration
event, so signups did not merely miss their confirmation mail — the request
threw after the user row was written.

Two changes make the forum usable in that state:

| Setting | Value | Why |
|---|---|---|
| `mail_driver` | `log` | Mail is written to the log instead of attempted. Nothing throws, and the message is still readable. |
| `welcome.autoConfirm` | `1` | Confirms the address at registration. An unconfirmed account never joins Member and so cannot post. |

**Registration works today.** Password reset does not — the mail is written to
the log rather than delivered.

## Password reset while email is down

The reset link is in the log, so a locked-out member can still be recovered by
hand:

```bash
ssh looksmax
docker exec flarum-app sh -c 'grep -i "reset" /flarum/app/storage/logs/flarum-$(date +%F).log | tail -5'
```

Send that link to the member out of band. This is a stopgap for a handful of
people, not a process that scales.

## Finishing it properly (~3 minutes of your time)

Any transactional mail provider works — Resend, Mailgun, Brevo, SendGrid — and
a forum this size fits inside their free tiers. A consumer Gmail account is a
poor choice: it rate-limits and flags quickly.

1. **Create the account** and add `looksmax.lat` as a sending domain.
2. **Add the DNS records the provider gives you** (SPF, DKIM, usually DMARC) in
   Cloudflare, where this domain's DNS lives, then hit verify in their
   dashboard. Do not skip this. Without SPF and DKIM the mail is delivered to
   spam or refused, and "SMTP configured but unverified" is barely better than
   the current state.
3. **Enter the credentials** at `looksmax.lat/admin` → **Email**:
   - Driver: `SMTP`
   - Host / Port (usually `587`) / Username / Password / Encryption `tls`
   - Save, then use the built-in **Send test mail** button.

Enter them there rather than pasting them into a chat or a file: the admin
panel writes them straight to the database from your browser.

4. **Turn the stopgap off** once mail is confirmed working, so real verification
   resumes:

```sql
UPDATE settings SET value='0' WHERE `key`='welcome.autoConfirm';
```

`welcome.autoConfirm` defaults to `false` in code and is only switched on in
this install's database, so this is a settings change and never a deploy.

## Verifying it works

```bash
# should print nothing about sendmail/busybox, and the mail should arrive
docker exec flarum-app php -r '
require "/flarum/app/vendor/autoload.php"; $s=require "/flarum/app/site.php"; $s->bootApp();
$m=Illuminate\Container\Container::getInstance()->make(Illuminate\Contracts\Mail\Mailer::class);
$m->raw("prueba", function($x){ $x->to("TU-CORREO@example.com")->subject("Prueba"); });
echo "sent\n";'
```

Then register a throwaway account and confirm the mail actually lands in an
inbox — not just that the command exits cleanly.

## The self-hosted alternative

A local MTA (Postfix + OpenDKIM) delivering straight to recipient mail servers
avoids the signup, but it is the worse option here: this is a Contabo VPS IP
with no reverse DNS set, and large providers treat unauthenticated VPS ranges
harshly. It still needs the same SPF/DKIM records plus a PTR record set in the
Contabo panel, so it trades a three-minute signup for more setup, ongoing
maintenance, and unreliable delivery to Gmail and Outlook. Only worth it if
using a third-party relay is genuinely off the table.

---

## Verified 2026-08-25: the Resend key works, the account is still gated

Tested directly against the live API from the server.

| Test | Result |
|---|---|
| `POST /emails`, from `onboarding@resend.dev` → owner's inbox | **accepted and delivered** (id `88832a3d…`) |
| `POST /emails` → `delivered@resend.dev` | accepted (id `f7cc6dad…`) |
| `POST /emails`, from `noreply@looksmax.lat` | **403** — domain not verified |
| `POST /emails` → any non-owner mailbox | **403** — "You can only send testing emails to your own email address" |
| SMTP `smtp.resend.com:587` auth | **succeeded** (rejection was 550 domain, not 535 auth) |

So the credential and both transports are proven. The account is in Resend's
testing mode, which permits mail only to the account owner. **Forum mail to
members is impossible until `looksmax.lat` is verified** — that gate is on
Resend's side and no transport or code change gets around it.

The credentials are stored in settings, but `mail_driver` is deliberately left
on `log`. Switching it to `smtp` now would make every member-facing send return
403 and throw, which is exactly the failure that used to break registration.

**Remaining steps (both need dashboard access):**

1. resend.com/domains → Add Domain → `looksmax.lat`.
2. Copy the SPF / DKIM / DMARC records it shows into Cloudflare DNS, then press
   Verify. Those records are public by design — unlike the API key, they are
   safe to share.

There is no Cloudflare API credential on this host (only a tunnel-scoped
`/etc/cloudflared/looksmax.env`), so the DNS records cannot be added from here.

Once verified, flipping it on is two settings:

```sql
UPDATE settings SET value='smtp' WHERE `key`='mail_driver';
UPDATE settings SET value='0'    WHERE `key`='welcome.autoConfirm';
```

---

## LIVE 2026-08-25: email is working

`looksmax.lat` was verified in Resend, and the forum was switched onto it.

| Setting | Value |
|---|---|
| `mail_driver` | `smtp` |
| `mail_host` / `mail_port` | `smtp.resend.com` / `587` (tls) |
| `mail_from` | `noreply@looksmax.lat` |
| `welcome.autoConfirm` | `0` — real email verification restored |

Verified end to end:

- `POST /emails` from `noreply@looksmax.lat` now returns an id where it
  previously returned 403.
- Flarum's own mailer sends over SMTP without throwing.
- A registration through the real path completes with **no exception** and
  leaves the account `is_email_confirmed = 0`, awaiting its confirmation mail —
  which is the correct behaviour now that mail can actually be delivered.

The auto-confirm stopgap is off. It stays in the codebase (default `false`) as a
switch for the next time delivery breaks.

### One gotcha found while testing

Writing a setting straight into the `settings` table is **not** enough — Flarum
caches settings, so the running process keeps serving the old value. A test
registration auto-confirmed even with `autoConfirm = 0` in the database, purely
because the cached `1` was still live. Always follow a direct settings write
with `php flarum cache:clear`, or the change silently does nothing.

### Outstanding: the domain cannot receive mail

There are **no MX records** on `looksmax.lat`, so `admin@`, `chris@` and
`equipo@looksmax.lat` can send but never receive. Members are unaffected — they
register with real mailboxes — but a password reset for a staff account would go
nowhere. Two ways to close it:

- Point the staff accounts at a mailbox that exists (a personal address), or
- Turn on Cloudflare Email Routing (free) and forward `@looksmax.lat` to one.

### Still to do

Rotate the API key that was pasted into a chat transcript, then put the
replacement in `looksmax.lat/admin` → Email rather than in a message.
