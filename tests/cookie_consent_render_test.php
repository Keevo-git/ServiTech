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

if (!preg_match('/<div\s+class="cookie-consent"(?P<attributes>.*?)>/s', $html, $match)) {
    fwrite(STDERR, "Consent root was not rendered.\n");
    exit(1);
}

$attributes = (string)$match["attributes"];
$hasHidden = preg_match('/\shidden(?:\s|$)/', $attributes) === 1;
$serverChoice = "";
if (preg_match('/data-server-has-choice="([^"]+)"/', $attributes, $choiceMatch)) {
    $serverChoice = (string)$choiceMatch[1];
}

$expectedChoice = $case === "valid" ? "true" : "false";
$expectedHidden = $case === "valid";

if ($serverChoice !== $expectedChoice || $hasHidden !== $expectedHidden) {
    fwrite(
        STDERR,
        "Unexpected render for {$case}: choice={$serverChoice}, hidden="
        . ($hasHidden ? "true" : "false")
        . "\n"
    );
    exit(1);
}

echo "Cookie consent render case {$case} passed.\n";
