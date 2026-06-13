<?php

$case = strtolower(trim((string)($argv[1] ?? "none")));

unset($_COOKIE["SERVITECH_COOKIE_CONSENT"]);
if ($case === "invalid") {
    $_COOKIE["SERVITECH_COOKIE_CONSENT"] = "{}";
} elseif ($case === "valid") {
    $_COOKIE["SERVITECH_COOKIE_CONSENT"] = json_encode([
        "version" => "2",
        "necessary" => true,
        "updatedAt" => "2026-06-13T00:00:00.000Z",
    ]);
}

ob_start();
require __DIR__ . "/../components/cookie_consent.php";
$html = (string)ob_get_clean();

if (!preg_match('/<div\s+class="site-privacy-controls"(?P<attributes>.*?)>/s', $html, $match)) {
    fwrite(STDERR, "Consent root was not rendered.\n");
    exit(1);
}

$attributes = (string)$match["attributes"];
$serverChoice = "";
if (preg_match('/data-server-has-choice="([^"]+)"/', $attributes, $choiceMatch)) {
    $serverChoice = (string)$choiceMatch[1];
}

if (!preg_match('/<section(?P<attributes>[^>]*data-privacy-notice[^>]*)>/s', $html, $bannerMatch)) {
    fwrite(STDERR, "Consent banner was not rendered.\n");
    exit(1);
}

foreach (['class="cookie-consent', 'id="servitechCookieConsent"', 'data-cookie-consent-root'] as $blockedSelector) {
    if (str_contains($html, $blockedSelector)) {
        fwrite(STDERR, "Rendered privacy UI contains blocker-targeted selector: {$blockedSelector}\n");
        exit(1);
    }
}

if (!str_contains($html, 'id="privacy-settings"')
    || !str_contains($html, '.site-privacy-controls__modal:target')) {
    fwrite(STDERR, "Preferences modal or no-JavaScript hash fallback is missing.\n");
    exit(1);
}

foreach ([
    "Functional Enhancements",
    "Accept All",
    "Reject Non-Essential",
    "data-privacy-functional-toggle",
    "CSRF",
    "polling",
    "realtime",
    "workflow messages",
] as $disallowedText) {
    if (stripos($html, $disallowedText) !== false) {
        fwrite(STDERR, "Rendered privacy UI contains disallowed text: {$disallowedText}\n");
        exit(1);
    }
}

foreach ([
    "Continue with Required Only",
    "show important system notifications",
    "support Google sign-in when you choose it",
    "does not currently use analytics, advertising, or marketing tracking cookies",
] as $requiredText) {
    if (!str_contains($html, $requiredText)) {
        fwrite(STDERR, "Rendered privacy UI is missing required text: {$requiredText}\n");
        exit(1);
    }
}

$bannerHidden = preg_match('/\shidden(?:\s|$)/', (string)$bannerMatch["attributes"]) === 1;
$expectedChoice = $case === "valid" ? "true" : "false";
$expectedBannerHidden = $case === "valid";

if ($serverChoice !== $expectedChoice || $bannerHidden !== $expectedBannerHidden) {
    fwrite(
        STDERR,
        "Unexpected render for {$case}: choice={$serverChoice}, banner_hidden="
        . ($bannerHidden ? "true" : "false")
        . "\n"
    );
    exit(1);
}

echo "Cookie consent render case {$case} passed.\n";
