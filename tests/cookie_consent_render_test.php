<?php

$case = strtolower(trim((string)($argv[1] ?? "none")));

unset($_COOKIE["SERVITECH_COOKIE_CONSENT"]);
if ($case === "invalid") {
    $_COOKIE["SERVITECH_COOKIE_CONSENT"] = "{}";
} elseif ($case === "valid") {
    $_COOKIE["SERVITECH_COOKIE_CONSENT"] = json_encode([
        "version" => "1",
        "necessary" => true,
        "functional" => false,
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
