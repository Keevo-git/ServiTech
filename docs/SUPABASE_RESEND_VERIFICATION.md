# Supabase + Resend email verification

## Audit findings (2026-06-25)

- The live Supabase Auth settings endpoint reports that signup is enabled, email/password auth is enabled, and email auto-confirm is disabled. A password signup is therefore expected to create an unconfirmed Auth user and send a confirmation email.
- A live signup through the same `/auth/v1/signup` path used by the application failed with `Error sending confirmation email`. Supabase rolled that signup back; an admin cleanup check found no temporary Auth user. This is a failed signup, not a successfully created account with a pending email.
- Public DNS is present for Resend: `resend._domainkey.servitech.store` has a DKIM record and `send.servitech.store` has the Resend/SES SPF and MX records.
- The local Resend settings use `smtp.resend.com`, port `587`, username `resend`, an `re_...` API key, and `no-reply@servitech.store`. A TLS SMTP test authenticated successfully and Resend accepted a test message from that sender.
- Because the same domain, sender, port, and local Resend credential work outside Supabase, the remaining delivery fault is the private Custom SMTP configuration saved in Supabase: a stale/incorrect API key or username, or Custom SMTP changes that were not saved/enabled.
- A historical Supabase configuration backup from 2026-06-12 has `site_url` set to `http://localhost:3000` and an empty redirect allow-list. The current private URL settings could not be read through the public Auth settings endpoint, so they must be checked in the dashboard.

## Required Supabase dashboard values

In **Authentication → Emails → SMTP Settings**, enable Custom SMTP and save:

| Setting | Value |
| --- | --- |
| Sender email | `no-reply@servitech.store` |
| Sender name | `ServiTech` |
| Host | `smtp.resend.com` |
| Port | `587` |
| Username | `resend` |
| Password | An active Resend API key beginning with `re_` |
| Minimum interval per user | `60` seconds |

Use a dedicated Resend API key for Supabase Auth. Do not put the key in source control or this document. If there is any doubt about the value currently saved in Supabase, create a new Resend sending key, replace the Supabase password, and save again.

In **Authentication → URL Configuration**, save:

| Setting | Value |
| --- | --- |
| Site URL | `https://servitech.store` |
| Redirect URL | `https://servitech.store/auth/verification_callback.php` |

In **Authentication → Email Templates → Confirm signup**, keep the confirmation action linked to `{{ .ConfirmationURL }}`. A template that omits or rewrites that value can deliver an unusable link.

## How to isolate a future delivery problem

1. Run `php scripts/audit_resend_smtp.php --send-test`. This verifies the application-side Resend credentials without printing the password.
2. Run `php scripts/audit_supabase_verification_delivery.php --run-live-test` once. It uses one Supabase Auth email-rate-limit slot and attempts to remove its temporary Auth user after a successful signup.
3. Check **Supabase → Logs → Auth**. `Error sending confirmation email` means Supabase could not hand the message to the configured SMTP server.
4. Check **Resend → Logs**:
   - No matching event means the request never authenticated to Resend; re-enter the Supabase SMTP username/API key.
   - A rejected event points to sender/domain/key permissions.
   - A delivered event means the message left Resend; check the recipient's Spam/Junk/Promotions folders and recipient-provider filtering.
5. Wait at least 60 seconds between signup/resend attempts for the same address.

## Acceptance checks

- **A — Register:** signup returns a user without an access/refresh-token session and Supabase/Resend logs show the confirmation message accepted. If SMTP rejects the message, the page must explain that no account was created and return the user to registration; resend cannot work without an Auth user.
- **B — Pending page:** the page shows the masked address, three clear next steps, spam guidance, resend, login, and change-email actions.
- **C — Resend:** a successful request returns to the pending page with accurate accepted feedback; provider errors and rate limits return actionable messages.
- **D — Confirm:** the email link lands on `/auth/verification_callback.php`, clears token-bearing browser history, and sends the user to Login with an “Email verified” message. Password login then succeeds.
- **E — Delayed email:** the pending page stays useful, explains folders/delay, and enables resend after the configured cooldown.

Inbox delivery cannot be proved from source code or an SMTP `250` response alone. Final production acceptance requires a real recipient inbox plus matching Supabase and Resend log events.
