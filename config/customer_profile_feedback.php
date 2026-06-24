<?php

function servitech_customer_profile_describe_changes(array $changedLabels): string
{
    $changedLabels = array_values(array_unique(array_filter($changedLabels)));
    $count = count($changedLabels);

    if ($count === 0) {
        return "Profile updated successfully.";
    }

    if ($count === 1) {
        return $changedLabels[0] . " updated successfully.";
    }

    if (in_array("Password", $changedLabels, true) && $count === 2) {
        $otherLabel = $changedLabels[0] === "Password" ? $changedLabels[1] : $changedLabels[0];
        return $otherLabel . " and password updated successfully.";
    }

    if ($count <= 3) {
        $last = array_pop($changedLabels);
        return implode(", ", $changedLabels) . " and " . strtolower($last) . " updated successfully.";
    }

    if (in_array("Password", $changedLabels, true)) {
        return "Profile and password updated successfully.";
    }

    return "Profile updated successfully.";
}

function servitech_customer_profile_update_feedback(
    array $changedLabels,
    bool $profileUpdated,
    bool $passwordUpdated,
    bool $emailChangePending
): array {
    if ($passwordUpdated && !in_array("Password", $changedLabels, true)) {
        $changedLabels[] = "Password";
    }

    if ($profileUpdated || $passwordUpdated) {
        return [
            "type" => "success",
            "message" => servitech_customer_profile_describe_changes($changedLabels),
        ];
    }

    if ($emailChangePending) {
        return [
            "type" => "success",
            "message" => "Check both email addresses to confirm the requested email change.",
        ];
    }

    return [
        "type" => "info",
        "message" => "No changes were detected.",
    ];
}
