# ServiTech Security Migration Implementation Report

Date: June 12, 2026

## 1. Backup and Export

Implemented `scripts/backup_supabase.ps1` to create:

- Full custom-format PostgreSQL dump
- Schema-only SQL dump
- Data-only SQL dump
- `pg_restore --list` validation output
- SHA-256 manifest
- Checksummed Hostinger upload inventory
- Supabase Auth service configuration when a temporary Management API token is supplied

Production backup is **not yet confirmed**. The configured direct database hostname did not
resolve, and the exact Supabase pooler URL has not been supplied. Production migration remains
blocked until the backup script succeeds and a disposable restore is tested.

## 2. Migration Scripts

Added:

- `database/migrations/20260612_add_supabase_auth_rls_foundation.sql`
- `scripts/audit_supabase_catalog.sql`

The foundation migration contains no drop, delete, truncate, rename, or function replacement.

## 3. Tables Kept

Kept without renaming:

- `users`
- `queues`
- `payments`
- `notifications`
- `services`
- `announcements`
- `queue_status_history`
- `uploads`
- `login_attempts`

Any legacy `customers` table is preserved but denied `anon`/`authenticated` access after
Supabase Auth activation. `users` is the canonical profile table.

## 4. Proposed Removals

Nothing was removed. Future approval candidates:

- Legacy password/reset/email-verification columns
- Legacy upload copies and compatibility endpoint
- `login_attempts`
- Unused Supabase Storage buckets found by the live audit
- A legacy `customers` table after row-by-row reconciliation

## 5. Renames and Restructuring

No production object was renamed. Existing integer IDs and operational foreign keys remain.
Auth identities link through `users.auth_user_id`.

## 6. Column Changes

Additive migration changes:

- `users.auth_user_id`
- Upload purpose, visibility, status, uploader, payment relation, and update timestamp
- Payment verification timestamp, verifier, and notes

No column was removed or renamed.

## 7. RLS

Added RLS foundations for profiles, queues, payments, notifications, services,
announcements, queue history, uploads, and login attempts.

- Anonymous users see only active services and announcements.
- Customers see their own operational records.
- Admins receive required operational access.
- Field-protection triggers block customer changes to roles, Auth linkage, statuses,
  payment verification, and other administrative fields.
- Security-definer functions narrowly support queue numbering, customer cancellation,
  and operational notifications without exposing other users' records.

Live role tests are pending the pooler connection and staging deployment.

## 8. Auth and Roles

- Added server-side Supabase signup, password login, refresh, logout, recovery, and Google ID-token exchange.
- Removed the former first-login bridge. Supabase Auth is now the only password
  verifier in Auth mode; legacy accounts use controlled invitation/recovery and
  reviewed `auth_user_id` mapping.
- Legacy hashes are nulled one account at a time only after successful Auth creation and linkage.
- Roles remain `customer` and `admin`.
- Registration never accepts an admin role.
- Email confirmation remains disabled for the requested testing phase.
- Legacy verification endpoints are inactive when Supabase Auth is enabled.

## 9. Upload Handling

- Strict mode requires an absolute path ending in `ServiTech_Uploads`.
- The path is rejected if it is inside `DOCUMENT_ROOT`.
- Executables, traversal keys, spoofed MIME types, invalid OOXML files, locked documents,
  oversized files, and duplicate upload entries are rejected.
- Random storage keys prevent filename collisions.
- Downloads remain authenticated PHP responses with ownership/admin checks.
- Legacy upload references remain read-compatible and were not moved or deleted.
- Customer notification Realtime falls back to authenticated PHP polling so Auth tokens remain server-side.

## 10. Hostinger Folder Confirmation

Code now enforces the intended folder contract. Physical production confirmation is pending
creation and permission checks for the Hostinger path outside `public_html`.

## 11. Supabase Upload Metadata

The `uploads` table supports:

- Existing ID, owner, queue, original name, storage key, extension, MIME type, size, checksum, and timestamps
- Upload purpose
- Visibility
- Upload status
- Uploaded-by profile
- Related payment

Only metadata is stored in Supabase; file bytes remain on Hostinger.

## 12. Website Files Updated

Updated central database/session configuration, all authentication handlers, profile updates,
queue compatibility helpers, cancellation/resubmission flows, upload handlers/downloads,
admin logout, and destructive service/announcement actions.

Admin service and announcement “delete” actions now archive records by setting `active = false`.

## 13. Manual Hostinger Steps

1. Create a private `/ServiTech_Uploads` directory outside `public_html`.
2. Give the PHP process read/write access and deny direct web serving.
3. Set `SERVITECH_PRIVATE_UPLOAD_DIR` to its absolute path.
4. Keep `.env`, the service-role key, database password, logs, backups, and scripts private.
5. Schedule upload-retention cleanup after staging verification.

## 14. Manual Supabase Steps

1. Supply the exact session-pooler URL.
2. Run and restore-test the backup.
3. Run the live catalog audit.
4. Apply the additive migration to staging.
5. Keep Confirm Email disabled.
6. Configure Google Auth and the password recovery redirect URL.
7. Bootstrap/link an admin Auth identity.
8. Run the full role test matrix.
9. Enable `SUPABASE_AUTH_ENABLED=1` and `SERVITECH_DB_ENFORCE_RLS=1` together.

## 15. Tests and Results

Passed:

- Syntax check for every project PHP file
- JWT parsing checks
- Rejection of malformed tokens
- Rejection of upload directories inside the web root
- Acceptance of the required private directory shape
- Path traversal rejection
- PHP executable upload rejection
- Static check for runtime schema DDL
- Static check for destructive statements in the new migration
- Static client-asset service-role scan

Not run:

- Production backup/restore
- SQL execution/lint against the live project
- Live anonymous/customer/customer/admin RLS tests
- End-to-end SMTP, Google, queue, payment, and upload flows on Hostinger

## 16. Known Issues and Recommendations

- Email confirmation is intentionally disabled; test accounts should not use another person's email.
- Password recovery works only for addresses with a controlled inbox.
- Existing Auth identities that already collide with unlinked legacy emails require manual review.
- The direct database password remains highly privileged and must stay server-only.
- Future work should migrate more multi-table operations into audited database RPCs.
- After all active accounts migrate, prepare a separate destructive-change approval packet for legacy columns and files.
