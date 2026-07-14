import html
import os
import zipfile
from datetime import date


OUT_PATH = os.path.join("documents", "SERVITECH_Software_Requirements_Specification.docx")


def esc(value):
    return html.escape(str(value), quote=True)


def text_runs(text, bold=False, italic=False):
    props = ""
    if bold or italic:
        props = "<w:rPr>"
        if bold:
            props += "<w:b/>"
        if italic:
            props += "<w:i/>"
        props += "</w:rPr>"
    parts = str(text).split("\n")
    out = []
    for idx, part in enumerate(parts):
        if idx:
            out.append("<w:r><w:br/></w:r>")
        out.append(f"<w:r>{props}<w:t xml:space=\"preserve\">{esc(part)}</w:t></w:r>")
    return "".join(out)


def paragraph(text="", style=None, align=None, bold=False, italic=False, page_break=False, keep_next=False):
    ppr = []
    if style:
        ppr.append(f"<w:pStyle w:val=\"{esc(style)}\"/>")
    if align:
        ppr.append(f"<w:jc w:val=\"{esc(align)}\"/>")
    if keep_next:
        ppr.append("<w:keepNext/>")
    ppr_xml = f"<w:pPr>{''.join(ppr)}</w:pPr>" if ppr else ""
    body = ""
    if page_break:
        body += "<w:r><w:br w:type=\"page\"/></w:r>"
    if text:
        body += text_runs(text, bold=bold, italic=italic)
    return f"<w:p>{ppr_xml}{body}</w:p>"


def heading(text, level=1):
    return paragraph(text, style=f"Heading{level}", keep_next=True)


def bullet(text):
    return paragraph(text, style="ListBullet")


def numbered(text):
    return paragraph(text, style="ListNumber")


def cell(content, bold=False, shade=False):
    if isinstance(content, list):
        inner = "".join(content)
    else:
        inner = paragraph(str(content), bold=bold)
    shd = "<w:shd w:fill=\"D9EAF7\"/>" if shade else ""
    return (
        "<w:tc><w:tcPr>"
        "<w:tcW w:w=\"2400\" w:type=\"dxa\"/>"
        "<w:tcMar><w:top w:w=\"80\" w:type=\"dxa\"/><w:left w:w=\"80\" w:type=\"dxa\"/>"
        "<w:bottom w:w=\"80\" w:type=\"dxa\"/><w:right w:w=\"80\" w:type=\"dxa\"/></w:tcMar>"
        f"{shd}</w:tcPr>{inner}</w:tc>"
    )


def table(headers, rows):
    tbl_pr = (
        "<w:tblPr><w:tblStyle w:val=\"TableGrid\"/>"
        "<w:tblW w:w=\"0\" w:type=\"auto\"/>"
        "<w:tblLook w:firstRow=\"1\" w:noHBand=\"0\" w:noVBand=\"1\"/></w:tblPr>"
    )
    xml = [f"<w:tbl>{tbl_pr}"]
    xml.append("<w:tr>" + "".join(cell(h, bold=True, shade=True) for h in headers) + "</w:tr>")
    for row in rows:
        xml.append("<w:tr>" + "".join(cell(item) for item in row) + "</w:tr>")
    xml.append("</w:tbl>")
    return "".join(xml)


def toc_field():
    return (
        "<w:p><w:pPr><w:pStyle w:val=\"TOCHeading\"/></w:pPr>"
        "<w:r><w:t>Table of Contents</w:t></w:r></w:p>"
        "<w:p><w:fldSimple w:instr=\"TOC \\o &quot;1-3&quot; \\h \\z \\u\">"
        "<w:r><w:t>Right-click and update field in Microsoft Word to refresh page numbers.</w:t></w:r>"
        "</w:fldSimple></w:p>"
    )


def page_footer_xml():
    return (
        "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>"
        "<w:ftr xmlns:w=\"http://schemas.openxmlformats.org/wordprocessingml/2006/main\">"
        "<w:p><w:pPr><w:jc w:val=\"center\"/></w:pPr>"
        "<w:r><w:t>Page </w:t></w:r>"
        "<w:r><w:fldChar w:fldCharType=\"begin\"/></w:r>"
        "<w:r><w:instrText xml:space=\"preserve\">PAGE</w:instrText></w:r>"
        "<w:r><w:fldChar w:fldCharType=\"separate\"/></w:r>"
        "<w:r><w:t>1</w:t></w:r>"
        "<w:r><w:fldChar w:fldCharType=\"end\"/></w:r>"
        "</w:p></w:ftr>"
    )


def section_properties():
    return (
        "<w:sectPr>"
        "<w:footerReference w:type=\"default\" r:id=\"rIdFooter1\"/>"
        "<w:pgSz w:w=\"12240\" w:h=\"15840\"/>"
        "<w:pgMar w:top=\"1440\" w:right=\"1440\" w:bottom=\"1440\" w:left=\"1440\" "
        "w:header=\"720\" w:footer=\"720\" w:gutter=\"0\"/>"
        "</w:sectPr>"
    )


def styles_xml():
    return """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Aptos" w:hAnsi="Aptos"/><w:sz w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="120" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>
  <w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
  <w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="34"/></w:rPr><w:pPr><w:jc w:val="center"/><w:spacing w:after="180"/></w:pPr></w:style>
  <w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/><w:basedOn w:val="Normal"/><w:rPr><w:sz w:val="24"/></w:rPr><w:pPr><w:jc w:val="center"/></w:pPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:outlineLvl w:val="0"/><w:spacing w:before="360" w:after="160"/></w:pPr><w:rPr><w:b/><w:color w:val="0F4C81"/><w:sz w:val="30"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:outlineLvl w:val="1"/><w:spacing w:before="260" w:after="120"/></w:pPr><w:rPr><w:b/><w:color w:val="1F5E7A"/><w:sz w:val="26"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:outlineLvl w:val="2"/><w:spacing w:before="180" w:after="80"/></w:pPr><w:rPr><w:b/><w:color w:val="333333"/><w:sz w:val="23"/></w:rPr></w:style>
  <w:style w:type="paragraph" w:styleId="TOCHeading"><w:name w:val="TOC Heading"/><w:basedOn w:val="Heading1"/></w:style>
  <w:style w:type="paragraph" w:styleId="ListBullet"><w:name w:val="List Bullet"/><w:basedOn w:val="Normal"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:style>
  <w:style w:type="paragraph" w:styleId="ListNumber"><w:name w:val="List Number"/><w:basedOn w:val="Normal"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:style>
  <w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4" w:color="8A8A8A"/><w:left w:val="single" w:sz="4" w:color="8A8A8A"/><w:bottom w:val="single" w:sz="4" w:color="8A8A8A"/><w:right w:val="single" w:sz="4" w:color="8A8A8A"/><w:insideH w:val="single" w:sz="4" w:color="8A8A8A"/><w:insideV w:val="single" w:sz="4" w:color="8A8A8A"/></w:tblBorders></w:tblPr></w:style>
</w:styles>"""


def document_relationships_xml():
    return """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rIdSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>
  <Relationship Id="rIdFooter1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>
</Relationships>"""


def root_relationships_xml():
    return """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>"""


def content_types_xml():
    return """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
  <Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
</Types>"""


def settings_xml():
    return """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:updateFields w:val="true"/>
</w:settings>"""


fr_rows = [
    ("FR-001", "Display public landing information", "Show public system entry points, service categories, announcements, store contact details, legal pages, and authentication links.", "Guest/Visitor", "High", "The system displays public pages without requiring a session and routes protected actions to login."),
    ("FR-002", "Register customer account", "Allow a guest to create a customer account using full name, email address, Philippine mobile number, password, confirmation password, and privacy consent.", "Guest/Visitor", "High", "The system validates required fields, contact format, email format, password rules, duplicate email, and stores consent metadata."),
    ("FR-003", "Verify or prepare account email", "Support email verification through Supabase Auth when enabled or local token verification when configured.", "Customer", "High", "Unverified accounts are directed to a verification-pending flow when verification is required."),
    ("FR-004", "Log in by role-specific context", "Authenticate customers, admins, and super admins through the correct login context.", "Customer, Admin/Employee, Super Admin", "High", "Wrong-role login attempts are blocked and recorded in activity logs."),
    ("FR-005", "Remember local login session", "Support remember-me persistence for local password authentication through hashed selector/token records.", "Customer, Admin/Employee", "Medium", "A valid remember token can restore a session; expired or invalid tokens are cleared."),
    ("FR-006", "Reset password", "Allow users to request password recovery and set a new password through Supabase Auth or local reset-token fallback.", "Customer, Admin/Employee", "High", "Expired, missing, malformed, or used recovery links are rejected with a clear message."),
    ("FR-007", "Google sign-in", "Allow customer login through Google ID token exchange when Google client configuration is present.", "Customer", "Medium", "Google-linked customers with missing contact or password data are redirected to account completion."),
    ("FR-008", "Customer dashboard", "Provide authenticated customers with quick access to service status, queue monitor, join queue, profile editing, notifications, and service categories.", "Customer", "High", "The customer dashboard loads only for customer sessions and redirects internal users to their dashboards."),
    ("FR-009", "Edit customer profile", "Allow customers to update editable profile information subject to validation.", "Customer", "Medium", "Profile updates are restricted to the authenticated user's own account."),
    ("FR-010", "View customer notifications", "Allow customers to view, mark, and receive queue and service-related notifications.", "Customer", "High", "Only notifications belonging to the signed-in customer are shown."),
    ("FR-011", "View queue monitor", "Allow customers to monitor current queue records and status changes.", "Customer", "High", "The system filters queue data to relevant operational records and current-day views where implemented."),
    ("FR-012", "Join queue with service selection", "Allow customers to submit printing, repair, installation, and supported print-related service requests.", "Customer", "High", "The system creates a queue record with generated queue code, category, lifecycle stage, details, and initial status."),
    ("FR-013", "Validate store availability before queueing", "Check shop status, hours, holidays, cutoff time, and regular queue availability before accepting regular queue requests.", "Customer", "High", "Unavailable regular services are rejected with the configured store message."),
    ("FR-014", "Allow document printing during closed queue periods", "Permit Document Printing while regular queueing is closed only when GCash is available and required.", "Customer", "Medium", "The system forces GCash for this scenario and rejects it if GCash is disabled."),
    ("FR-015", "Validate service availability", "Check global service closure and individual service manual closure before accepting requests.", "Customer", "High", "Closed services return a service-unavailable message and are not submitted."),
    ("FR-016", "Validate payment method availability", "Check cash and GCash payment controls before accepting requests that require payment.", "Customer", "High", "Disabled payment methods cannot be selected; at least one method must remain enabled."),
    ("FR-017", "Create online document printing draft", "Collect document printing options, files, page counts, quantity, color option, notes, and payment method before final submission.", "Customer", "High", "The system stores a temporary draft and requires the final payment step before creating the queue."),
    ("FR-018", "Submit document printing request", "Create a Document Printing queue record and payment record from a valid draft.", "Customer", "High", "The request receives a print queue code, linked uploads, calculated price, notification, and admin alert."),
    ("FR-019", "Analyze uploaded printing files", "Analyze uploaded document files for page/image counts where supported.", "Customer", "Medium", "File analysis metadata is stored in queue details for pricing and review."),
    ("FR-020", "Submit Rush ID request", "Allow Rush ID requests with catalog options and exactly one JPG, JPEG, or PNG upload.", "Customer", "High", "The system rejects missing, duplicate, excessive, or invalid Rush ID files."),
    ("FR-021", "Submit photocopy/xerox/laminating/scanning requests", "Allow catalog-driven print-related requests where active service options and pricing rules exist.", "Customer", "Medium", "The system requires catalog option values and applies configured pricing."),
    ("FR-022", "Submit repair request", "Allow customers to submit repair service requests including device type, repair type, notes, and assessment details.", "Customer", "High", "Repair requests are submitted without immediate payment and marked for staff assessment."),
    ("FR-023", "Submit installation request", "Allow customers to submit installation service requests using configured installation options.", "Customer", "High", "Installation requests are submitted without immediate payment and marked for staff assessment."),
    ("FR-024", "Upload customer files securely", "Allow supported service forms to upload files to private storage with metadata records.", "Customer", "High", "Files receive random storage keys, upload tokens, MIME/type validation, checksum, owner link, and private download URL."),
    ("FR-025", "Cancel temporary uploads", "Allow customers to cancel orphan uploads before the request is completed.", "Customer", "Medium", "The system marks owned orphan uploads deleted and removes physical files when possible."),
    ("FR-026", "Download authorized uploads", "Serve uploaded files through an authenticated endpoint.", "Customer, Admin/Employee, Super Admin", "High", "Only the owner or authorized internal user can download the file."),
    ("FR-027", "Create GCash service payment draft", "Redirect GCash queue submissions to a payment confirmation page before queue creation.", "Customer", "High", "Only one pending service payment draft is allowed per session."),
    ("FR-028", "Validate GCash reference number", "Require a valid 13-digit reference number for GCash submissions.", "Customer", "High", "Invalid or missing references are rejected before queue/order creation."),
    ("FR-029", "Record payments", "Store payment method, amount, reference number, and review status for requests that require payment.", "Customer, Admin/Employee", "High", "Payment rows are linked to queue and customer records."),
    ("FR-030", "Review GCash payment", "Allow authorized staff to approve or cancel online payment requests as part of status transition or payment update.", "Admin/Employee, Super Admin", "High", "Approved GCash payments move to APPROVED; completed orders mark payment as PAID."),
    ("FR-031", "Admin dashboard", "Provide internal dashboard access for queue/order statistics, notifications, and operational links.", "Admin/Employee, Super Admin", "High", "Only admin or super admin sessions can access internal dashboards."),
    ("FR-032", "Queue management by category", "Display active queue records for printing, repair, installation, and walk-in scopes.", "Admin/Employee, Super Admin", "High", "Admins can inspect queue details, customer contact data, files, status, and notes."),
    ("FR-033", "Update queue status", "Allow admins to move requests through valid status transitions.", "Admin/Employee, Super Admin", "High", "Invalid transitions are rejected; successful transitions update status history, analytics timestamps, lifecycle stage, payment state, and notifications."),
    ("FR-034", "Require cancellation reason", "Require staff notes when cancelling a queue or order.", "Admin/Employee, Super Admin", "High", "Cancellation without reason is rejected."),
    ("FR-035", "Send request back to customer", "Allow admins to mark pending or approved records as requiring customer edits with a message.", "Admin/Employee, Super Admin", "Medium", "The customer receives a notification and the record shows customer_edit_required until resolved or advanced."),
    ("FR-036", "Order management", "Display completed, cancelled, and operational order records by print, repair, and installation categories.", "Admin/Employee, Super Admin", "High", "Order screens list status, payment summary, submitted date, files, customer details, and actions."),
    ("FR-037", "Recycle order records", "Allow authorized users to move order records to a recycle bin instead of immediate deletion.", "Super Admin", "Medium", "Deleted orders are soft-deleted and can be reviewed through recycle-bin screens."),
    ("FR-038", "Export order reports", "Allow admins to export filtered print, repair, and installation order records to CSV.", "Admin/Employee, Super Admin", "Medium", "Exports honor filters and create an activity log entry."),
    ("FR-039", "Manage service catalog", "Allow Super Admin to update existing service descriptions, active state, sort order, options, and pricing rules.", "Super Admin", "High", "Top-level new service creation and deletion are not supported from the editor; inactive services are hidden from customers."),
    ("FR-040", "Public service catalog", "Expose active services and customer-available catalog rules to customer pages.", "Guest/Visitor, Customer", "High", "Only active and available catalog options appear in customer workflows."),
    ("FR-041", "Manage customers", "Allow admins to view customer list, contact details, request history, and send customer messages.", "Admin/Employee, Super Admin", "High", "Customer tools are role-gated and messages are recorded as notifications."),
    ("FR-042", "Admin notification center", "Show internal notifications for new customers, new orders, payment review, cancellations, and related events.", "Admin/Employee, Super Admin", "Medium", "Admins can count, view, mark, and delete notifications through internal endpoints."),
    ("FR-043", "Manage announcements", "Allow internal users to manage service announcements; inactive records are archived rather than removed.", "Admin/Employee, Super Admin", "Medium", "Public pages show active announcements only."),
    ("FR-044", "Manage store availability", "Allow authorized internal users to configure store status, queue cutoff, regular hours, and holidays.", "Admin/Employee, Super Admin", "High", "Configured values affect customer queue access immediately."),
    ("FR-045", "Manage operational controls", "Allow Super Admin to close all services, close individual services, and enable or disable payment methods.", "Super Admin", "High", "The system enforces closures and prevents both cash and GCash from being disabled simultaneously."),
    ("FR-046", "Manage employee accounts", "Allow Super Admin to create, edit, activate, deactivate, reset, and force password change for employee admin accounts.", "Super Admin", "High", "Temporary passwords are sent to Supabase Auth and are not stored in ServiTech tables."),
    ("FR-047", "Employee first-time setup", "Require employee admins with incomplete profiles or forced password change to complete setup before using admin pages.", "Admin/Employee", "High", "Pending setup redirects to the first-time setup page and denied attempts are logged."),
    ("FR-048", "Super Admin dashboard", "Provide owner-level access to employee accounts, activity logs, analytics, service controls, system settings, and diagnostics.", "Super Admin", "High", "Only super_admin sessions can open Super Admin pages."),
    ("FR-049", "System settings", "Provide Super Admin access to system-level settings pages and SMTP diagnostics.", "Super Admin", "Medium", "Settings screens are role-gated and secrets are not displayed in diagnostics."),
    ("FR-050", "Activity logging", "Record significant authentication, employee, queue, payment, service, report, and unauthorized-access events.", "Admin/Employee, Super Admin", "High", "Logs contain actor, role, action type, module, target, old/new values, status, IP, user agent, and timestamp."),
    ("FR-051", "View employee activity logs", "Allow Super Admin to review employee and system activity logs.", "Super Admin", "High", "Activity log pages display recorded actions for audit and accountability."),
    ("FR-052", "Analytics center", "Allow Super Admin to review operations, service queue performance, workflow, staff, quality, notifications, availability, and completion analytics.", "Super Admin", "Medium", "Analytics use queue timestamps, queue status events, cycles, snapshots, and export logs when schema is ready."),
    ("FR-053", "Monthly analytics cycles", "Maintain analytics cycles and monthly snapshots for reporting periods.", "Super Admin", "Medium", "The system creates an active cycle for the current month and supports snapshot records."),
    ("FR-054", "Lifecycle upload cleanup", "Remove orphan temporary uploads after the configured threshold and closed request file contents after retention.", "System", "High", "Default policy removes temporary uploads after 24 hours and closed request files after 30 days."),
    ("FR-055", "Lifecycle archive closed records", "Archive closed DONE/CANCELLED queues and orders after the configured retention period.", "System", "Medium", "Default policy archives closed records after 60 days without deleting service history."),
    ("FR-056", "Lifecycle cleanup notifications and auth data", "Delete aged soft-deleted/read notifications and temporary authentication records according to configured policy.", "System", "Medium", "Maintenance jobs record run status, report, and errors in data_lifecycle_runs."),
]


nfr_rows = [
    ("NFR-001", "Performance", "Common customer and admin pages should load within acceptable local hosting limits; indexed database queries shall be used for queue, order, notification, upload, and analytics lookups."),
    ("NFR-002", "Security", "The system shall enforce authentication, role checks, CSRF validation, same-origin validation, secure session cookies, password hashing, and authorization checks for protected actions."),
    ("NFR-003", "Usability", "Customer workflows shall use clear validation messages, confirmation pages, searchable/filterable admin tables, and accessible modal behavior for operational screens."),
    ("NFR-004", "Reliability", "State-changing operations shall use transactions where multiple records must be consistent, especially queue creation, payment creation, upload linking, employee creation, and status transitions."),
    ("NFR-005", "Availability", "The system shall be deployable on PHP/XAMPP or PHP hosting with Supabase database connectivity, with store availability controls used for planned service restrictions."),
    ("NFR-006", "Maintainability", "Reusable configuration, guards, API helpers, migrations, and tests shall be maintained in organized folders such as config, api, pages, components, database, scripts, and tests."),
    ("NFR-007", "Compatibility", "The system shall support modern browsers, PHP with PDO PostgreSQL, PHPMailer, Supabase database/Auth integration, and standard CSV export consumers."),
    ("NFR-008", "Scalability", "The design shall support increased records through indexed tables, lifecycle archiving, catalog configuration, and separation of customer, admin, and super admin modules."),
    ("NFR-009", "Backup and Recovery", "Database backup scripts and migration runbooks shall be used before production migrations; private upload storage and logs shall be protected and recoverable through operator procedures."),
]


data_rows = [
    ("users", "Account identity, profile, role, status, consent, email verification, Auth linkage, employee setup fields.", "Customer, Admin, Super Admin", "Owner, authorized internal users, Supabase Auth where enabled."),
    ("queues", "Service requests, queue code, category, status, details JSON, lifecycle stage, sequence, timestamps, send-back, archive/delete metadata.", "Customer request and admin operation data", "Customer owner and authorized internal users."),
    ("payments", "Payment method, amount, GCash reference, review/payment status, verifier fields where migrated.", "Payment review and service completion", "Customer owner and authorized internal users."),
    ("notifications", "Customer/admin messages, type, reference, read/deleted state, event key.", "Operational communication", "Recipient and authorized internal users."),
    ("services", "Service category, name, description, active flag, sort order, price range, pricing JSON.", "Service catalog", "Public active read; Super Admin management."),
    ("service_option_groups / service_option_values / service_pricing_rules", "Catalog options, selectable values, fixed or assessment pricing rules.", "Customer service forms and pricing", "Public active read; Super Admin management."),
    ("uploads", "Upload token, owner, queue link, original name, random storage key, extension, MIME, size, checksum, timestamps, deletion state.", "Private file handling", "Owner and authorized internal download only."),
    ("queue_status_history", "Status transition history, admin actor, notes, action type.", "Audit and customer status tracking", "Authorized internal users; customer-visible status effects."),
    ("activity_logs", "Actor, role, action, module, target, old/new values, IP, user agent, success/failed status.", "Security and accountability", "Super Admin review."),
    ("store_availability_settings / store_hours / store_holidays", "Store status, cutoff time, operating hours, holidays.", "Queue access control", "Public read; authorized internal management."),
    ("operational_control_settings / operational_service_settings / operational_payment_method_settings", "Global closures, per-service closure, payment method availability.", "Operational control", "Public read; Super Admin management."),
    ("remember_tokens / login_attempts", "Hashed remember-me tokens and hashed login throttling identifiers.", "Authentication support", "System only."),
    ("queue_status_events / analytics_cycles / analytics_monthly_snapshots / analytics_export_logs", "Workflow timestamps, analytics periods, snapshots, export metadata.", "Reporting and analytics", "Super Admin reporting."),
    ("data_lifecycle_runs", "Maintenance mode, dry-run flag, status, policy, report, errors.", "Lifecycle audit", "System and internal review."),
]


roles_rows = [
    ("Guest/Visitor", "Public", "Can view landing information, public services/announcements, legal pages, login, registration, and store status. Cannot submit requests or view protected data."),
    ("Customer", "Authenticated customer", "Registers/logs in, manages own profile, joins queues, uploads files, submits printing/repair/installation requests, provides GCash references, views own notifications and service status."),
    ("Admin/Employee", "Internal operations", "Views dashboards, queues, orders, customer list, files, payments, notifications, reports, announcements, and updates operational request statuses. Employee setup may be required before full access."),
    ("Super Admin", "Owner/system administration", "Has all admin capabilities plus employee account management, service catalog management, operational controls, activity logs, analytics, system settings, SMTP diagnostics, and recycle-bin oversight."),
]


interface_rows = [
    ("User Interface", "Responsive PHP-rendered pages for public, customer, admin, and super admin modules; forms, dashboards, tables, modals, status filters, and notification centers."),
    ("Hardware Interface", "No special hardware integration is implemented. Users require client devices with browsers; the server requires PHP hosting with access to private upload storage."),
    ("Software Interface", "PHP, PDO PostgreSQL, Supabase database/Auth APIs when enabled, PHPMailer SMTP, Google Identity sign-in when configured, CSV export, and local scripts for backup/lifecycle jobs."),
    ("Communication Interface", "HTTPS is expected in production. SMTP or Supabase email delivery sends verification and recovery messages. AJAX/JSON endpoints support uploads, queue creation, payment drafts, notifications, and admin actions."),
    ("Database Interface", "Supabase PostgreSQL stores application records. RLS foundation migrations exist for Auth-enabled deployment; the app also uses privileged server-side PDO where required."),
    ("File Interface", "Uploaded files are stored outside the web root or in protected private storage, with metadata in the uploads table and downloads through PHP authorization endpoints."),
]


business_rules = [
    ("BR-001", "Customers must log in before joining a queue or submitting any request."),
    ("BR-002", "Registration requires full name, valid email, valid Philippine mobile number, password confirmation, and privacy consent."),
    ("BR-003", "Registration never accepts an admin or super admin role from public input."),
    ("BR-004", "Role-specific login contexts must reject accounts with a different role."),
    ("BR-005", "Required service form fields and catalog options must be completed before request submission."),
    ("BR-006", "Regular queue requests are blocked when the store is closed, paused, fully booked, on holiday, outside hours, or past cutoff."),
    ("BR-007", "Document Printing may remain available outside regular queue availability only with GCash, unless GCash is disabled."),
    ("BR-008", "GCash requests must include a valid 13-digit reference number before final queue/order creation."),
    ("BR-009", "Repair and installation requests are submitted for staff assessment and do not require immediate payment."),
    ("BR-010", "Rush ID requests require exactly one valid image upload."),
    ("BR-011", "Only supported, unlocked, valid files may be uploaded; executable files and traversal paths are rejected."),
    ("BR-012", "Customers may access only their own queues, payments, notifications, and uploaded files."),
    ("BR-013", "Admins may update request status only through allowed status transitions."),
    ("BR-014", "Cancellation requires a reason and cannot occur after restricted final stages."),
    ("BR-015", "Only Pending or Approved records may be sent back for customer editing."),
    ("BR-016", "Only Super Admin users may manage employee accounts, service catalog settings, operational controls, system settings, owner analytics, and activity logs."),
    ("BR-017", "At least one payment method, Cash or GCash, must remain available."),
    ("BR-018", "Service deletion from the service editor is not supported; services are disabled through active/inactive or closure controls."),
    ("BR-019", "Closed request file contents and orphan uploads are removed according to lifecycle policy while service history remains."),
    ("BR-020", "Sensitive configuration values, service role keys, SMTP passwords, backups, logs, and private uploads must remain outside public web access."),
]


use_cases = [
    ("UC-001", "Customer Registration", "Guest/Visitor", "A visitor creates a customer account.", "The visitor is not logged in and has access to the registration page.", "1. Visitor opens registration. 2. Visitor enters full name, contact number, email, password, confirmation, and consent. 3. System validates input. 4. System creates the customer profile and sends verification when required. 5. System redirects to verification pending or login.", "If validation fails, the system returns an error and does not create the account. If the email already exists, the user is redirected to login guidance.", "A customer account exists or a verification workflow is pending."),
    ("UC-002", "Customer Login", "Customer", "A customer signs in to access protected workflows.", "Customer has an existing active account and uses the customer login context.", "1. Customer enters email and password. 2. System validates credentials and role. 3. System establishes session and optionally remember-me persistence. 4. System redirects to customer dashboard.", "Wrong role, unverified email, inactive account, or invalid password causes login rejection and logging.", "Customer session is active."),
    ("UC-003", "Joining a Queue", "Customer", "A customer submits a supported service request.", "Customer is logged in; store and service are available; required options are selected.", "1. Customer selects service. 2. System checks availability and payment method controls. 3. Customer completes required details. 4. System calculates price where applicable. 5. System creates queue record, status history, notifications, and payment record if needed.", "If service is unavailable, input is incomplete, payment draft exists, or validation fails, the request is rejected.", "A queue record with a generated queue code is created."),
    ("UC-004", "Submitting Document Printing Request", "Customer", "A customer submits an uploaded document for printing.", "Customer is logged in and has prepared the print order draft with valid uploads.", "1. Customer uploads file(s). 2. System validates files and analyzes pages where supported. 3. Customer selects paper size, color option, quantity, notes, and payment method. 4. Customer provides GCash reference if required. 5. System creates print queue and payment record.", "Missing file, invalid option, disabled payment method, or invalid GCash reference prevents submission.", "Document Printing request is queued and admins are notified."),
    ("UC-005", "Submitting Repair Request", "Customer", "A customer submits a repair request for assessment.", "Customer is logged in; repair service is available.", "1. Customer selects repair category/options. 2. Customer enters notes and device details. 3. System validates required catalog options. 4. System creates repair queue with payment assessment status.", "If store or service is unavailable, submission is rejected.", "Repair request appears in admin repair queue/order screens."),
    ("UC-006", "Updating Request Status", "Admin/Employee", "An admin updates a queue or order status.", "Admin is logged in and has access to queue/order management.", "1. Admin opens queue/order details. 2. Admin selects allowed status. 3. System validates transition. 4. System updates queue, payment status if applicable, history, analytics, activity log, and customer notification.", "Invalid transition or missing cancellation reason is rejected.", "Request status and related records are updated consistently."),
    ("UC-007", "Managing Services", "Super Admin", "The owner updates service catalog availability and pricing options.", "Super Admin is logged in; service catalog schema exists.", "1. Super Admin opens service management. 2. Super Admin selects existing service. 3. Super Admin updates description, active state, sort order, options, or pricing rules. 4. System validates catalog payload. 5. System saves changes and logs activity.", "New top-level services and service deletion from editor are rejected.", "Updated service catalog affects customer service forms."),
    ("UC-008", "Viewing Analytics", "Super Admin", "The owner reviews operational reports and analytics.", "Super Admin is logged in; analytics schema is available.", "1. Super Admin opens analytics center. 2. System loads current cycle and related metrics. 3. User selects analytics category or export view. 4. System displays reports or export options.", "If analytics schema is unavailable, the system displays an unavailable warning.", "Super Admin can review operational performance data."),
    ("UC-009", "Managing Employee Accounts", "Super Admin", "The owner creates and administers employee admin accounts.", "Super Admin is logged in; Supabase Auth and employee setup migration are ready.", "1. Super Admin opens Employee Accounts. 2. Super Admin creates or selects an employee account. 3. System validates employee details and temporary password. 4. System creates/updates Auth and profile records. 5. System sends verification if required and logs activity.", "Duplicate email, missing service role key, unready schema, invalid password, or email delivery failure produces an error or warning.", "Employee admin account is created or updated."),
]


acceptance_rows = [
    ("AC-001", "Public visitors can open landing, legal, login, and registration pages without viewing protected records."),
    ("AC-002", "A new customer cannot register without required fields, valid contact/email, valid password, and privacy consent."),
    ("AC-003", "Public registration always creates a customer role only."),
    ("AC-004", "Customer, Admin, and Super Admin accounts are routed to their correct dashboards and rejected from wrong login contexts."),
    ("AC-005", "A logged-in customer can submit available printing, repair, and installation requests with generated queue codes."),
    ("AC-006", "Store closure, holidays, outside hours, and cutoff controls prevent regular queue submissions."),
    ("AC-007", "Document Printing outside regular availability requires GCash and fails if GCash is disabled."),
    ("AC-008", "GCash submissions fail unless the reference number is exactly 13 digits."),
    ("AC-009", "Uploaded files are validated, linked to the owner, and unavailable through direct public paths."),
    ("AC-010", "Admins can view queues/orders and perform only valid status transitions."),
    ("AC-011", "Cancellation requires a reason and records customer notification, status history, and activity log entries."),
    ("AC-012", "Super Admin can manage employee accounts only when Auth linkage and service role configuration are ready."),
    ("AC-013", "Service catalog updates are reflected in customer forms without allowing unsupported top-level deletion."),
    ("AC-014", "Order CSV exports include filtered order data and create an activity log record."),
    ("AC-015", "Lifecycle scripts can run in dry-run mode and record maintenance reports without deleting active customer accounts."),
]


trace_rows = [
    ("Authentication and Roles", "FR-001 to FR-007, FR-046 to FR-048", "NFR-002", "UC-001, UC-002, UC-009", "AC-001 to AC-004, AC-012"),
    ("Customer Requests", "FR-008 to FR-030", "NFR-001, NFR-003, NFR-004", "UC-003 to UC-005", "AC-005 to AC-009"),
    ("Admin Operations", "FR-031 to FR-038, FR-041 to FR-043", "NFR-001, NFR-004, NFR-006", "UC-006", "AC-010, AC-011, AC-014"),
    ("Service and Store Controls", "FR-039, FR-040, FR-044, FR-045", "NFR-003, NFR-005, NFR-006", "UC-007", "AC-006, AC-007, AC-013"),
    ("Analytics, Logs, Lifecycle", "FR-050 to FR-056", "NFR-006, NFR-008, NFR-009", "UC-008", "AC-014, AC-015"),
]


def build_body():
    body = []
    body.append(paragraph("SERVITECH: A Centralized Web-Based System for Managing Print, Repair, and Installation Requests in Microbusiness Operations", style="Title"))
    body.append(paragraph("Software Requirements Specification", style="Subtitle"))
    body.append(paragraph("Project Type: Capstone System", align="center"))
    body.append(paragraph("Prepared for: JC Repair Shop / Microbusiness Operations", align="center"))
    body.append(paragraph("Proponents/Developers: SERVITECH Development Team (names to be supplied by the proponents)", align="center"))
    body.append(paragraph("Institution: To be supplied by the proponents", align="center"))
    body.append(paragraph("Adviser: To be supplied by the proponents", align="center"))
    body.append(paragraph("Date: July 4, 2026", align="center"))
    body.append(paragraph("Version: 1.0", align="center"))
    body.append(paragraph(page_break=True))

    body.append(toc_field())
    body.append(paragraph(page_break=True))

    body.append(heading("Document Control", 1))
    body.append(table(["Item", "Description"], [
        ("Document Title", "Software Requirements Specification"),
        ("System Title", "SERVITECH: A Centralized Web-Based System for Managing Print, Repair, and Installation Requests in Microbusiness Operations"),
        ("Prepared For", "Capstone documentation and panel review"),
        ("Basis of Content", "Existing SERVITECH PHP/Supabase codebase, migrations, tests, scripts, and documentation as inspected on July 4, 2026."),
        ("Important Note", "Names of proponents, institution, and adviser are placeholders because they are not present in the repository."),
    ]))
    body.append(table(["Version", "Date", "Description", "Author"], [
        ("1.0", "July 4, 2026", "Initial SRS generated from the implemented SERVITECH system.", "SERVITECH Development Team"),
    ]))

    body.append(heading("1. Introduction", 1))
    body.append(heading("1.1 Purpose of the Document", 2))
    body.append(paragraph("This Software Requirements Specification defines the functional, non-functional, data, security, interface, and operational requirements for SERVITECH. It is intended to support academic evaluation, implementation review, maintenance, and future enhancement planning for the capstone system."))
    body.append(heading("1.2 Purpose of the System", 2))
    body.append(paragraph("SERVITECH centralizes request handling for a microbusiness environment such as JC Repair Shop. The system supports customer registration, queue joining, online document printing requests, repair and installation request submission, queue and order management, GCash and cash payment handling, customer and staff notifications, service catalog management, store availability controls, analytics, and employee account administration."))
    body.append(heading("1.3 Intended Readers", 2))
    for item in ["Capstone panel members and evaluators.", "Project proponents and developers.", "Adviser and documentation reviewers.", "Microbusiness owner, administrator, and employee users.", "Future maintainers responsible for deployment, security, and data lifecycle tasks."]:
        body.append(bullet(item))
    body.append(heading("1.4 Project Background", 2))
    body.append(paragraph("Small service-oriented shops often manage printing, repair, installation, and customer follow-up through manual logs, walk-in queues, social messages, and informal payment records. SERVITECH addresses these operational issues by giving customers a web-based request channel and giving staff an internal dashboard for request tracking, status updates, payments, reports, service configuration, and auditability."))
    body.append(heading("1.5 Document Conventions", 2))
    body.append(paragraph("Functional requirements use IDs in the format FR-001. Non-functional requirements use NFR-001. Business rules use BR-001. Use cases use UC-001. Priorities are High, Medium, or Low. Unless marked as proposed or limitation, requirements describe implemented or directly supported repository features."))

    body.append(heading("2. Scope of the System", 1))
    body.append(paragraph("SERVITECH is a centralized web-based system for managing service requests in print, repair, and installation operations. It supports a public entry point, authenticated customer workflows, internal employee/admin operations, and owner-level Super Admin management. In its current implementation, SERVITECH is a PHP application using Supabase PostgreSQL through PDO, optional Supabase Auth/RLS migration support, PHPMailer SMTP, Google sign-in configuration, private upload storage, and scheduled maintenance scripts."))
    body.append(paragraph("The system is in scope for customer request intake, queue monitoring, document printing with uploads, Rush ID image upload, repair and installation assessment requests, payments through Cash or GCash reference submission, notifications, order tracking, reports, service catalog configuration, store availability and cutoff controls, employee account administration, activity logs, and lifecycle maintenance."))
    body.append(paragraph("Out of scope or not implemented as self-service features are direct printer hardware control, automated GCash API payment verification, inventory management, accounting ledger integration, self-service account deletion/data portability, and guaranteed production cutover of all Supabase Auth/RLS settings without operator execution of the documented migration runbook."))

    body.append(heading("3. Overall Description", 1))
    body.append(heading("3.1 Product Perspective", 2))
    body.append(paragraph("SERVITECH is a web application organized into public/authentication routes, customer pages, admin pages, super admin pages, shared components, API endpoints, configuration modules, migrations, tests, scripts, private storage, and static assets. It is designed to replace fragmented request logging with a single operational database and role-based workflow."))
    body.append(heading("3.2 Product Functions", 2))
    for item in [
        "Register and authenticate customers, admins, and super admins.",
        "Accept customer queue requests for printing, repair, installation, and related printing services.",
        "Support document printing and Rush ID file uploads through private storage.",
        "Record Cash and GCash payment selections and payment references.",
        "Track request status from Pending through completion or cancellation.",
        "Notify customers and admins about submissions, status updates, payment review, send-back messages, and cancellations.",
        "Provide admin queue, order, customer, notification, announcement, and report tools.",
        "Provide Super Admin management for employees, services, analytics, activity logs, operational controls, system settings, and diagnostics.",
        "Maintain data lifecycle policies for uploads, archived records, notifications, and temporary authentication records.",
    ]:
        body.append(bullet(item))
    body.append(heading("3.3 User Classes and Characteristics", 2))
    body.append(table(["User Class", "Technical Skill", "Primary Need"], [
        ("Guest/Visitor", "Basic web navigation", "Learn about the service and register or log in."),
        ("Customer", "Basic web and form usage", "Submit service requests, upload files, track status, and receive notifications."),
        ("Admin/Employee", "Operational staff familiarity", "Process queues, update statuses, review payment references, manage customers, and export reports."),
        ("Super Admin", "Owner or system administrator", "Manage employees, service catalog, operational controls, analytics, activity logs, and system settings."),
    ]))
    body.append(heading("3.4 Operating Environment", 2))
    body.append(paragraph("The repository indicates a PHP web application runnable in XAMPP or PHP hosting, using Supabase PostgreSQL over PDO. It includes assets for browser-based customer and admin pages, PHPMailer for SMTP, optional Google Identity sign-in, optional Supabase Auth with RLS, private upload directories outside public web access, and command-line scripts for backup, analytics, and lifecycle maintenance."))
    body.append(heading("3.5 Design and Implementation Constraints", 2))
    for item in [
        "The application depends on PHP, PDO PostgreSQL, Supabase configuration, and correct environment variables.",
        "Private uploads must be stored outside the public web root or protected by equivalent server rules.",
        "Supabase Auth/RLS deployment requires the documented migration, backup, identity linkage, MFA, and feature flag sequence.",
        "Employee account creation and password reset require Supabase Admin service role configuration.",
        "GCash integration records manual reference numbers and does not perform automatic gateway verification.",
        "Service catalog management edits configured services and does not support arbitrary top-level service creation from the editor.",
    ]:
        body.append(bullet(item))
    body.append(heading("3.6 User Documentation", 2))
    body.append(paragraph("Implemented documentation includes README instructions, migration runbooks, reset password flow notes, privacy compliance audit, privacy incident response, Supabase security migration documentation, and tests. End-user manuals are not fully represented in the repository, although the interface contains form messages, toasts, and operational labels."))
    body.append(heading("3.7 Assumptions and Dependencies", 2))
    for item in [
        "Users have stable internet access and a modern browser.",
        "The database, SMTP/email provider, and hosting environment are available.",
        "The business owner supplies accurate service prices, store hours, holidays, GCash reference review procedures, and employee account information.",
        "Production operators protect environment variables, logs, backups, and private upload directories.",
        "Panel documentation details such as institution, adviser, and proponent names will be supplied by the project team.",
    ]:
        body.append(bullet(item))

    body.append(heading("4. System Users and Roles", 1))
    body.append(table(["Role", "Access Level", "Main Responsibilities"], roles_rows))

    body.append(heading("5. Functional Requirements", 1))
    body.append(paragraph("The following functional requirements are grouped by implemented modules and workflows. Requirement behavior is based on the current repository structure, API endpoints, migrations, tests, and configuration files."))
    body.append(table(["Requirement ID", "Requirement Name", "Description", "User Role", "Priority", "Expected System Behavior"], fr_rows))

    body.append(heading("6. Non-Functional Requirements", 1))
    body.append(table(["Requirement ID", "Category", "Requirement"], nfr_rows))

    body.append(heading("7. Security Requirements", 1))
    for item in [
        "Passwords are hashed in local authentication using PHP password_hash and verified using password_verify; Supabase Auth is used as the password verifier when enabled.",
        "Sessions use a named SERVITECHSESSID cookie with HttpOnly, SameSite=Lax, strict mode, cookie-only sessions, and secure flag when HTTPS is detected.",
        "CSRF tokens and same-origin checks protect state-changing forms and JSON endpoints.",
        "Role-based access control separates guest, customer, admin, and super admin actions through centralized guards.",
        "Role-specific login pages reject wrong-role login attempts and record them in activity logs.",
        "Admin MFA is supported in Supabase Auth mode when configured, requiring AAL2 for internal access.",
        "Customer APIs filter records by the authenticated user ID to prevent viewing or changing another customer's records.",
        "Uploaded files are validated by extension, MIME characteristics, OOXML structure where applicable, size limits, checksums, random storage keys, and private PHP download authorization.",
        "The system rejects executable uploads, traversal attempts, locked/encrypted documents, duplicate upload submissions, and unsupported file types.",
        "GCash information is limited to submitted reference numbers and review status; no card or banking credentials are stored.",
        "Activity logs record successful and failed security-relevant operations, including unauthorized Super Admin page attempts.",
        "Sensitive environment values, SMTP credentials, service role keys, logs, backups, and private upload storage must not be browser-accessible.",
        "Supabase RLS foundation migrations define owner/admin access patterns and field-protection triggers; production enablement requires the documented cutover process.",
    ]:
        body.append(bullet(item))

    body.append(heading("8. Data Requirements", 1))
    body.append(table(["Data Store/Table", "Major Data Handled", "Purpose", "Access/Protection"], data_rows))

    body.append(heading("9. External Interface Requirements", 1))
    body.append(table(["Interface Type", "Requirement"], interface_rows))

    body.append(heading("10. System Features", 1))
    feature_texts = [
        ("10.1 Customer Account and Authentication", "The system provides registration, role-specific login, email verification support, password recovery, remember-me support for local authentication, and optional Google sign-in. Authentication establishes the user's role and routes the user to the correct dashboard."),
        ("10.2 Customer Request Submission", "Customers can submit service requests through structured forms. The system validates store availability, service catalog selections, payment method availability, file ownership, and required fields before creating queue records."),
        ("10.3 Queue and Order Workflow", "SERVITECH distinguishes active queue records from order records through lifecycle stages. Staff can process requests using controlled status transitions, while customers receive status notifications."),
        ("10.4 Document Printing and Upload Handling", "Document printing supports uploaded files, printing options, quantity, color selection, page metadata, estimated pricing, payment selection, and payment reference capture. File bytes are kept private while metadata is stored in the database."),
        ("10.5 Repair and Installation Requests", "Repair and installation workflows allow customers to submit requests for staff assessment. These requests do not require immediate payment and are processed by staff through queue and order management."),
        ("10.6 Payments", "The system supports Cash and GCash payment modes. GCash submissions require a 13-digit reference number and are subject to staff review. Payment records are synchronized with status progression such as approval, completion, and cancellation."),
        ("10.7 Notifications", "Notifications support customer and internal communication for new accounts, new orders, payment review, status updates, cancellations, send-back requests, and admin messages."),
        ("10.8 Service Catalog and Availability", "Super Admin users can update configured service descriptions, active states, option groups, option values, and pricing rules. Store availability, operating hours, holidays, cutoff times, global closures, individual service closures, and payment method controls affect customer access."),
        ("10.9 Administration and Reporting", "Admins can monitor dashboards, queues, orders, customers, announcements, notifications, and CSV reports. Super Admin users receive expanded owner reporting and analytics based on queue events and monthly cycles."),
        ("10.10 Employee and Activity Management", "Super Admin users can manage employee admin accounts, enforce profile setup, reset temporary passwords, activate or deactivate employees, and review activity logs for accountability."),
        ("10.11 Data Lifecycle Maintenance", "Scheduled maintenance supports upload cleanup, closed-record archiving, recycle-bin aging, notification cleanup, temporary authentication cleanup, and run reporting."),
    ]
    for title, text in feature_texts:
        body.append(heading(title, 2))
        body.append(paragraph(text))

    body.append(heading("11. Business Rules", 1))
    body.append(table(["Rule ID", "Business Rule"], business_rules))

    body.append(heading("12. Use Case Descriptions", 1))
    for uc in use_cases:
        ucid, name, actor, desc, pre, flow, alt, post = uc
        body.append(heading(f"{ucid} - {name}", 2))
        body.append(table(["Field", "Description"], [
            ("Use Case ID", ucid),
            ("Use Case Name", name),
            ("Actor", actor),
            ("Description", desc),
            ("Preconditions", pre),
            ("Main Flow", flow),
            ("Alternative Flow", alt),
            ("Postconditions", post),
        ]))

    body.append(heading("13. Acceptance Criteria", 1))
    body.append(table(["Criterion ID", "Acceptance Criterion"], acceptance_rows))

    body.append(heading("14. Limitations", 1))
    for item in [
        "The system depends on internet connectivity, web server availability, Supabase/database availability, SMTP/email delivery, and proper environment configuration.",
        "GCash processing is manual reference-number review and not an automated payment gateway integration.",
        "The repository includes Supabase Auth/RLS migration support, but production activation depends on operator execution, backup verification, identity linking, and feature flag cutover.",
        "Employee account creation requires Supabase Admin service role configuration and readable auth.users linkage.",
        "Self-service privacy export, account deletion, and portability workflows are documented as manual/business review items rather than implemented customer tools.",
        "Direct printer, scanner, device repair diagnostics, inventory, accounting, and POS integrations are not implemented.",
        "Analytics depends on the analytics schema and populated queue status events; unavailable schema displays an analytics warning.",
        "Scheduled lifecycle maintenance depends on cron or Task Scheduler execution.",
    ]:
        body.append(bullet(item))

    body.append(heading("15. Appendices", 1))
    body.append(heading("15.1 Glossary of Terms", 2))
    body.append(table(["Term", "Definition"], [
        ("Queue", "An active service request awaiting or undergoing processing."),
        ("Order", "A request that has moved into order/history handling, commonly after completion, cancellation, or lifecycle transition."),
        ("GCash Reference Number", "A 13-digit reference number submitted by a customer for manual online payment review."),
        ("Lifecycle Stage", "A queue record marker indicating whether the record is operationally in QUEUE or ORDER state."),
        ("Send-back", "An admin action that asks the customer to correct or add request information."),
        ("Private Upload", "A file stored with a random key outside direct public access and delivered only through authorization checks."),
        ("Super Admin", "Owner-level internal user with authority over employees, services, analytics, operational controls, logs, and settings."),
    ]))
    body.append(heading("15.2 Acronyms", 2))
    body.append(table(["Acronym", "Meaning"], [
        ("SRS", "Software Requirements Specification"),
        ("RBAC", "Role-Based Access Control"),
        ("CSRF", "Cross-Site Request Forgery"),
        ("SMTP", "Simple Mail Transfer Protocol"),
        ("RLS", "Row-Level Security"),
        ("MFA", "Multi-Factor Authentication"),
        ("AAL2", "Authenticator Assurance Level 2"),
        ("CSV", "Comma-Separated Values"),
        ("PDO", "PHP Data Objects"),
    ]))
    body.append(heading("15.3 References", 2))
    for item in [
        "SERVITECH README.md and PROJECT_STRUCTURE.md.",
        "Database migrations under database/migrations.",
        "Security and implementation documentation under docs/.",
        "Application routes under auth/, api/, pages/customer, pages/admin, pages/super_admin, components, config, scripts, and tests.",
        "IEEE-style SRS structure adapted for academic capstone documentation.",
    ]:
        body.append(bullet(item))
    body.append(heading("15.4 Requirement Traceability Matrix", 2))
    body.append(table(["Requirement Area", "Functional Requirements", "Non-Functional Requirements", "Use Cases", "Acceptance Criteria"], trace_rows))
    return "".join(body)


def write_docx():
    os.makedirs(os.path.dirname(OUT_PATH), exist_ok=True)
    body_xml = build_body()
    document_xml = (
        "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>"
        "<w:document xmlns:w=\"http://schemas.openxmlformats.org/wordprocessingml/2006/main\" "
        "xmlns:r=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships\">"
        f"<w:body>{body_xml}{section_properties()}</w:body></w:document>"
    )
    with zipfile.ZipFile(OUT_PATH, "w", compression=zipfile.ZIP_DEFLATED) as docx:
        docx.writestr("[Content_Types].xml", content_types_xml())
        docx.writestr("_rels/.rels", root_relationships_xml())
        docx.writestr("word/document.xml", document_xml)
        docx.writestr("word/_rels/document.xml.rels", document_relationships_xml())
        docx.writestr("word/styles.xml", styles_xml())
        docx.writestr("word/settings.xml", settings_xml())
        docx.writestr("word/footer1.xml", page_footer_xml())
    return OUT_PATH


if __name__ == "__main__":
    path = write_docx()
    print(path)
