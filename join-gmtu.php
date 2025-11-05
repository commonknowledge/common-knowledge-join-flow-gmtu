<?php

/**
 * Plugin Name:     Common Knowledge Join Flow GMTU Extensions
 * Description:     Common Knowledge join flow plugin GMTU extensions.
 * Version:         0.1.0
 * Author:          Common Knowledge <hello@commonknowledge.coop>
 * Text Domain:     common-knowledge-join-flow
 * License: GPLv2 or later
 */

if (! defined('ABSPATH')) exit; // Exit if accessed directly

$branchMap = [
    "M1" => "South Manchester",
    "M2" => "South Manchester",
    "M3" => "South Manchester",
    "M4" => "South Manchester",
    "M5" => null,
    "M6" => null,
    "M7" => null,
    "M8" => "Harpurhey",
    "M9" => "Harpurhey",
    "M11" => "Harpurhey",
    "M12" => "Leve-Longsight",
    "M13" => "Leve-Longsight",
    "M14" => "Moss Side",
    "M15" => "Hulme",
    "M16" => "Moss Side",
    "M17" => null,
    "M18" => "Leve-Longsight",
    "M19" => "Leve-Longsight",
    "M20" => "South Manchester",
    "M21" => "South Manchester",
    "M22" => "South Manchester",
    "M23" => "South Manchester",
    "M24" => "Middleton",
    "M25" => null,
    "M26" => null,
    "M27" => null,
    "M28" => null,
    "M29" => null,
    "M30" => null,
    "M31" => null,
    "M32" => null,
    "M33" => null,
    "M34" => null,
    "M35" => null,
    "M38" => null,
    "M40" => "Harpurhey",
    "M41" => null,
    "M43" => null,
    "M44" => null,
    "M45" => null,
    "M46" => null,
    "M50" => "South Manchester",
    "OL1" => null,
    "OL2" => null,
    "OL3" => null,
    "OL4" => null,
    "OL5" => null,
    "OL6" => null,
    "OL7" => null,
    "OL8" => null,
    "OL9" => null,
    "OL10" => null,
    "OL11" => "Rochdale",
    "OL12" => "Rochdale",
    "OL13" => null,
    "OL14" => null,
    "OL15" => null,
    "OL16" => "Rochdale",
    "SK1" => "Stockport",
    "SK2" => "Stockport",
    "SK3" => "Stockport",
    "SK4" => "Stockport",
    "SK5" => "Stockport",
    "SK6" => "Stockport",
    "SK7" => "Stockport",
    "SK8" => "Stockport",
    "SK9" => null,
    "SK10" => null,
    "SK11" => null,
    "SK12" => null,
    "SK13" => null,
    "SK14" => null,
    "SK15" => null,
    "SK16" => null,
    "SK17" => null,
    "SK22" => null,
    "SK23" => null,
];

// Populate "branch" custom field from postcode
add_filter("ck_join_flow_pre_handle_join", function ($data) use ($branchMap) {
    global $joinBlockLog;

    if (!empty($data["branch"])) {
        // Don't overwrite explicitly set branch
        return $data;
    }

    if (empty($data["addressPostcode"])) {
        return $data;
    }

    $postcode = $data["addressPostcode"];

    try {
        $url = "https://api.postcodes.io/postcodes/" . rawurlencode($data["addressPostcode"]);
        $postcodesResponse = @file_get_contents($url);

        if (empty($postcodesResponse)) {
            return $data;
        }

        $postcodesData = json_decode($postcodesResponse, true);

        $outcode = $postcodesData["result"]["outcode"] ?? null;

        if (!$outcode) {
            return $data;
        }

        $data["branch"] = $branchMap[trim($outcode)] ?? null;

        // Ensure "branch" custom field exists
        $customFields = $data["customFieldsConfig"] ?? [];
        $customField = null;
        foreach ($data["customFieldsConfig"] as $field) {
            if ($field["id"] === "branch") {
                $customField = $field;
                break;
            }
        }
        if (!$customField) {
            $customFields[] = [
                "id" => "branch",
                "field_type" => "text"
            ];
        }
        $data["customFieldsConfig"] = $customFields;

        return $data;
    } catch (\Exception $e) {
        if (!empty($joinBlockLog)) {
            $joinBlockLog->warning("Could not get branch from postcode $postcode: " . $e->getMessage());
        }
        return $data;
    }
});
