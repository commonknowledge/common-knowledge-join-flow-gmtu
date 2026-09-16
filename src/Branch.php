<?php
/**
 * Branch mapping and configuration.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Get the branch map configuration.
 *
 * Maps postcode outcodes to branch names.
 *
 * @since 1.2.0
 *
 * @return array Branch map array with outcode => branch name mapping.
 */
function get_branch_map() {
    return [
        "M1" => "City Centre and Salford",
        "M2" => "City Centre and Salford",
        "M3" => "City Centre and Salford",
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
        "M17" => "City Centre and Salford",
        "M18" => "Leve-Longsight",
        "M19" => "Leve-Longsight",
        "M20" => "South Manchester",
        "M21" => "South Manchester",
        "M22" => "South Manchester",
        "M23" => "South Manchester",
        "M24" => "Middleton",
        "M25" => null,
        "M26" => null,
        "M27" => "City Centre and Salford",
        "M28" => "City Centre and Salford",
        "M29" => null,
        "M30" => "City Centre and Salford",
        "M31" => null,
        "M32" => null,
        "M33" => null,
        "M34" => null,
        "M35" => null,
        "M38" => "City Centre and Salford",
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
        "BL1" => "Bolton",
        "BL2" => "Bolton",
        "BL3" => "Bolton",
        "BL4" => "Bolton",
        "BL5" => "Bolton",
        "BL6" => "Bolton",
        "BL7" => "Bolton",
        "BL8" => null,
        "BL9" => null,
        "WA3" => "Wigan",
        "WA13" => null,
        "WA14" => null,
        "WA15" => null,
        "WN1" => "Wigan",
        "WN2" => "Wigan",
        "WN3" => "Wigan",
        "WN4" => "Wigan",
        "WN5" => "Wigan",
        "WN6" => "Wigan",
        "WN7" => "Wigan",
    ];
}

/**
 * Get the branch email map configuration.
 *
 * Maps branch names to email addresses for notifications.
 *
 * @since 1.2.0
 *
 * @return array Branch email map array with branch name => email mapping.
 */
function get_branch_email_map() {
    return [
        "City Centre and Salford" => null,
        "South Manchester" => 'south.mcr@tenantsunion.org.uk',
        "Harpurhey" => 'harpurhey@tenantsunion.org.uk',
        "Leve-Longsight" => 'levenshulme-longsight@tenantsunion.org.uk',
        "Moss Side" => 'moss-side@tenantsunion.org.uk',
        "Hulme" => 'hulme@tenantsunion.org.uk',
        "Middleton" => 'middleton@tenantsunion.org.uk',
        "Rochdale" => 'rochdale@tenantsunion.org.uk',
        "Stockport" => null,
        "Bolton" => null,
        "Wigan" => null,
    ];
}

/**
 * Get branch name for a given postcode outcode.
 *
 * @since 1.2.0
 *
 * @param string $outcode The postcode outcode.
 * @return string|null The branch name or null if not found.
 */
function get_branch_for_outcode($outcode) {
    $branchMap = get_branch_map();
    return $branchMap[$outcode] ?? null;
}

