<?php

function register_agreement_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function register_agreement_source(string $relativePath): string
{
    return file_get_contents(__DIR__ . "/../" . $relativePath) ?: "";
}

$pageSource = register_agreement_source("auth/regis.php");
$handlerSource = register_agreement_source("auth/register.php");
$cssSource = register_agreement_source("assets/css/style.css");

// Cases A-C: the required input remains associated with its label, and a
// separate visual box cannot be collapsed by native checkbox rendering.
register_agreement_assert(
    preg_match('/<label\s+class="agreement-row"\s+for="privacyConsent">/', $pageSource) === 1,
    "The agreement label is not associated with the checkbox."
);
register_agreement_assert(
    preg_match('/<input\s+id="privacyConsent"[^>]*name="privacy_consent"[^>]*type="checkbox"[^>]*value="1"[^>]*required>/', $pageSource) === 1,
    "The required agreement checkbox wiring changed."
);
register_agreement_assert(
    str_contains($pageSource, 'id="register-agreement-critical-styles"'),
    "The Register page is missing its critical checkbox fallback styles."
);
register_agreement_assert(
    str_contains($pageSource, 'class="agreement-row__box" aria-hidden="true"'),
    "The visible agreement checkbox box is missing."
);
foreach ([
    "grid-template-columns: 20px minmax(0, 1fr);",
    'body.auth-page--register .agreement-row__box',
    "width: 20px;",
    "height: 20px;",
    "border: 2px solid #7a0808;",
    "visibility: visible;",
    'body.auth-page--register .agreement-row__native[type="checkbox"]:checked + .agreement-row__box',
    'body.auth-page--register .agreement-row__native[type="checkbox"]:focus-visible + .agreement-row__box',
] as $requiredStyle) {
    register_agreement_assert(
        str_contains($cssSource, $requiredStyle),
        "The visible checkbox style is missing: {$requiredStyle}"
    );
}

// Cases D-E: unchecked consent is rejected in the browser and at the server;
// checked consent posts the exact value accepted by the server.
register_agreement_assert(
    str_contains($pageSource, 'validate: (checked) => checked ? ""'),
    "Client-side agreement validation is missing."
);
register_agreement_assert(
    str_contains($pageSource, 'if (!fields.privacyConsent.input.checked)'),
    "The Google registration agreement guard is missing."
);
register_agreement_assert(
    str_contains($handlerSource, '$privacy_consent !== "1"'),
    "Server-side agreement validation is missing."
);

// Case F: both legal-document controls and their modal binding remain present.
foreach ([
    'data-doc-trigger="privacy">Data Privacy Policy',
    'data-doc-trigger="terms">Terms &amp; Conditions',
    'document.querySelectorAll("[data-doc-trigger]")',
    "event.stopPropagation();",
] as $requiredLinkWiring) {
    register_agreement_assert(
        str_contains($pageSource, $requiredLinkWiring),
        "Policy/terms link wiring is missing: {$requiredLinkWiring}"
    );
}

echo "Register agreement UI and validation checks passed.\n";
