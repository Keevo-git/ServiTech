# ServiTech Privacy Incident Response Runbook

Effective date: 2026-06-13

This runbook supports readiness under the Philippine Data Privacy Act of 2012 and its IRR. It is an operational checklist, not legal advice.

## 1. Detect and Triage

- Record the first report time, reporter, affected page/API, and suspected data involved.
- Preserve relevant application logs, database audit/status history, server access logs, and screenshots.
- Do not delete evidence while assessing the incident.
- Classify whether the event is a security incident, a personal data breach, or both.

## 2. Contain

- Disable affected accounts, tokens, endpoints, credentials, or integrations as needed.
- Remove public access to exposed files or directories.
- Rotate compromised secrets such as database, SMTP, Supabase, Google, or hosting credentials.
- Keep queue/order service continuity where possible, but prioritize stopping further exposure.

## 3. Assess Scope and Risk

- Identify affected data subjects.
- Identify affected data types: names, email addresses, phone numbers, queue/order details, payment references, uploaded files, notifications, admin notes, or logs.
- Determine whether sensitive personal information or government-issued identifiers may be involved in uploaded files.
- Determine the exposure window, access method, and whether files were downloaded or modified.

## 4. Notify and Escalate

- Notify the project owner/operator and the assigned privacy contact immediately.
- Decide whether affected users must be notified.
- Decide whether notification to the National Privacy Commission is required.
- Prepare notification content with known facts, likely effects, containment actions, remediation, and contact details.

## 5. Remediate

- Patch the vulnerable code, configuration, policy, or process.
- Validate access control, file download restrictions, retention cleanup, and log coverage.
- Restore service only after exposure is contained.

## 6. Document Closure

- Write an incident report covering facts, timeline, affected data, root cause, remedial action, and follow-up controls.
- Keep written reports for incidents and personal data breaches so they are available for review.
- Add recurring prevention tasks where needed, such as access reviews, backup checks, retention-job monitoring, or admin training.
