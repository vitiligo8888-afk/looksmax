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
