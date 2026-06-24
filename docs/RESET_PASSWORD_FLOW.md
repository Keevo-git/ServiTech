# Reset password flow

## Application flow

1. `/auth/forgot_password.php` sends the recovery request through Supabase Auth and explicitly sets `https://servitech.store/auth/reset_password.php` as the redirect.
2. Supabase verifies the email link and returns either:
   - its default recovery session in the URL fragment, or
   - `token_hash` plus `type=recovery` when a custom server-side email template is used.
3. `/auth/reset_password.php` validates either callback with Supabase, removes credentials from browser history, stores the short-lived recovery context in the server-side PHP session, and then renders the reset form.
4. The form submits New Password and Confirm New Password with CSRF protection. The backend calls Supabase Auth's user update endpoint with the recovery access token.
5. A successful update clears recovery and application sessions, then sends the user to Login with: “Your password has been reset successfully.”
6. Missing, malformed, used, or expired links show a clear error and a link back to Forgot Password.

The local-auth fallback still uses the existing one-time, hashed database token and revokes all remember-me tokens after a successful reset.

## Required Supabase dashboard values

These settings live in Supabase and cannot be changed by the PHP deployment. In **Authentication → URL Configuration**, save:

| Setting | Required value |
| --- | --- |
| Site URL | `https://servitech.store` |
| Redirect URL | `https://servitech.store/auth/reset_password.php` |
| Redirect URL | `https://servitech.store/auth/verification_callback.php` |

The June 12 configuration backup recorded `http://localhost:3000` as Site URL and an empty redirect allow-list. If those values are still active, Supabase will reject or replace the application's reset redirect and the email link will not reach the reset form.

Keep the **Reset password** email template's action linked to `{{ .ConfirmationURL }}`. If using a custom direct-to-application template, its URL must include `token_hash={{ .TokenHash }}&type=recovery`; never put the raw OTP or access token in application logs.

## Production acceptance checks

- Request a reset for a real verified password account and confirm a recovery email is accepted in Supabase Auth/SMTP logs.
- Open the newest email link in a private window on desktop and mobile. Confirm the reset fields appear automatically.
- Submit mismatched passwords and confirm the form explains the mismatch.
- Submit a valid new password and confirm the Login page shows the success message.
- Confirm the old password is rejected and the new password signs in.
- Reopen the used link, and test an intentionally expired link, confirming both show the recovery message and a route back to Forgot Password.
- Re-run login, registration, email verification/resend, and Google sign-in smoke tests.

Run the local regression checks with:

```powershell
php tests/reset_password_flow_test.php
php tests/email_verification_flow_test.php
```
