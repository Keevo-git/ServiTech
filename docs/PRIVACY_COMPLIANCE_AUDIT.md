# ServiTech Privacy Compliance Audit

Audit date: 2026-06-13

Scope: local PHP/XAMPP ServiTech application, including customer registration, profile, queue/order flows, upload handling, admin queue/order/customer tools, public legal pages, retention scripts, and security controls. This is a technical and operational audit aid, not a legal opinion.

## Regulatory Baseline

The audit used the Philippine Data Privacy Act of 2012 and the NPC IRR as the baseline for transparency, legitimate purpose, proportionality, lawful processing, security measures, data subject rights, breach documentation/notification, retention/disposal, and processor controls.

## Data Inventory

| Data type | Collection point | Purpose | Storage | Access | Retention/disposal |
| --- | --- | --- | --- | --- | --- |
| Full name, email, phone | Registration, Google sign-in profile sync, profile edit | Account identity, contact, queue/order ownership | `users` | Customer owner, admins | Retained with account/service history; correction via profile page; deletion requires manual review |
| Password/auth data | Registration, login, reset password, Supabase Auth when enabled | Authentication and account recovery | `users.password_hash`, Supabase session/auth data, reset/verification token hashes | System/auth provider; admins do not see plaintext passwords | Password hashes retained while account exists; reset/verification tokens expire or are cleared |
| Consent timestamp/version | Registration | Proof of policy acknowledgment | `users.consent_accepted_at`, `users.consent_version` | Admin/system database access | Retained with account record |
| Queue/order details | Customer service forms, admin updates | Service delivery, status tracking, pricing, send-back edits | `queues.details`, `queues`, `queue_status_history` | Customer owner, admins | Retained for service history; attached file content expires separately |
| Uploaded files and metadata | Upload endpoints and upload-enabled service forms | Fulfill printing/rush ID/document services | Private upload storage; `uploads` metadata; details JSON references | Customer owner, admins through protected endpoints | Active request files retained while needed; Done/Cancelled files 30 days; orphan/abandoned uploads 24 hours; cleanup job marks deleted and removes file content |
| Payment details | Online print order/payment forms; admin payment update | Payment review and service processing | `payments`, selected details JSON | Customer owner, admins | Retained for service history; business/legal review needed for exact record retention limit |
| Notifications/messages | System status events, admin customer messages | Customer/admin communication and workflow status | `notifications` | Recipient customer/admin; admin notification tools | Soft delete supported; broader retention period requires business decision |
| Login throttling records | Failed login flow | Security monitoring and brute-force throttling | `login_attempts` hashed identifiers | System/admin DB access | Old attempts cleared by login throttle cleanup |
| Logs/diagnostics | Error paths and admin diagnostics | Troubleshooting and security review | PHP/server logs, `logs/` | Operators/admins with server access | Retention depends on hosting/server configuration; policy now flags need for organizational controls |

## Implemented Controls Observed

- Customer and admin role gates are centralized through `components/auth_guard.php` and `pages/admin/_includes/admin_auth.php`.
- Customer APIs checked in this audit filter by current `user_id` for queue list, cancellation, queue detail updates, upload ownership, print order draft/create, and upload download.
- Admin pages are role-gated and use CSRF checks on state-changing endpoints.
- Passwords are hashed; failed login throttling stores hashed email/IP identifiers.
- Uploads use private storage keys, upload tokens, file type/size checks, active/private status checks, and protected download endpoints.
- Upload retention cleanup exists for temporary orphan files and closed-request files.
- Public footer links expose Privacy Policy and Terms of Service.

## Findings

### Critical

No critical code-level exposure was confirmed in this static audit after the direct legacy-upload web access fix below. Live hosting, database grants, backups, and server logs were not inspected.

### High

1. Legacy upload directories could be directly served by Apache if files existed under `/uploads`.
   - Location: `uploads/`, `uploads/printing/`, legacy paths handled by `api/legacy_upload_download.php`.
   - Risk: uploaded documents/images could bypass ownership checks through a direct URL.
   - Fix implemented: added `.htaccess` deny rules to `uploads/.htaccess` and `uploads/printing/.htaccess`.
   - Remaining: verify production server honors `.htaccess`; if using Nginx or object storage, add equivalent deny/private bucket rules.

2. Public/privacy consent text had a contact placeholder in the registration modal.
   - Location: `auth/regis.php`.
   - Risk: consent was less informed because the privacy contact mechanism was incomplete at the point of consent.
   - Fix implemented: registration modal now uses configured ServiTech contact email/phone.
   - Remaining: business/privacy owner should confirm the official privacy contact and address.

3. Breach readiness existed mostly as implicit logs/status history, not as an internal process.
   - Location: project documentation/process.
   - Risk: delayed containment, evidence preservation, or required notification decisions.
   - Fix implemented: added `docs/PRIVACY_INCIDENT_RESPONSE.md`.
   - Remaining: assign a responsible privacy contact and test the process.

### Medium

1. Public policy was less complete than actual behavior and the registration modal.
   - Location: `legal/privacy-policy.php`.
   - Risk: users may not receive clear disclosure of legal basis, processors, incident handling, file privacy, and rights limitations.
   - Fix implemented: expanded the public policy to disclose collection sources, legal basis/consent, service providers, security measures, retention, rights, and incident handling.
   - Remaining: legal/privacy review required before claiming compliance.

2. Consent version default did not match the current policy effective date.
   - Location: `config/account.php`.
   - Risk: weak traceability between accepted policy and displayed policy.
   - Fix implemented: default consent version updated to `2026-06-13`.
   - Remaining: if production uses `AUTH_CONSENT_VERSION`, update that environment variable too.

3. Admin access is broad and single-role.
   - Location: queue/order/customer/admin pages.
   - Risk: all admins can see customer contact data, payment references, attached files, and history even if their operational task is narrower.
   - Recommended fix: introduce scoped admin roles or documented access procedures if the business needs least-privilege separation.
   - Status: not changed to avoid breaking admin workflows.

4. Account/data deletion and data portability are not self-service.
   - Location: customer profile and admin tooling.
   - Risk: policy rights depend on manual operator procedures.
   - Recommended fix: add an admin privacy-request workflow or export/delete runbook after legal/business retention decisions.
   - Status: documented as manual review.

### Low

1. Uploaded original filenames are retained and shown to owners/admins.
   - Location: `uploads.original_name`, queue/order details.
   - Risk: filenames may contain personal data.
   - Recommended fix: keep random storage keys, avoid exposing filenames outside owner/admin views, and remind users not to include unnecessary personal data in filenames.
   - Status: policy now warns users; storage keys already avoid filename-based public paths.

2. Logs and backup retention are not defined in code.
   - Location: hosting/server operations.
   - Risk: logs/backups may contain personal data longer than necessary.
   - Recommended fix: define log/backup retention and access rules outside application code.
   - Status: remaining operational item.

## Fixes Implemented In This Audit

- Added Apache deny rules for legacy upload folders.
- Updated default account consent version to `2026-06-13`.
- Removed privacy contact placeholders from registration policy modal.
- Expanded public Privacy Policy to match current system behavior.
- Added this audit artifact.
- Added a privacy incident response runbook.

## Items Requiring Legal/Business Review

- Confirm the exact legal basis for each processing category and whether consent should be only acknowledgment for service delivery.
- Confirm official privacy contact, business/school address, and responsible privacy owner or DPO if required.
- Define retention for account records, queue/order history, payment references, notifications, logs, backups, and exported reports.
- Review processor agreements/terms for hosting, Supabase, Google, and email services.
- Decide whether scoped admin roles are required beyond the current `admin`/`customer` split.
- Decide whether to build self-service privacy export/deletion or an admin privacy-request workflow.
