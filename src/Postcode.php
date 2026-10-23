<?php
/**
 * Postcode lookup and caching functionality.
 *
 * @package CommonKnowledge\JoinBlock\Organisation\GMTU
 */

namespace CommonKnowledge\JoinBlock\Organisation\GMTU;

/**
 * Get outcode from postcode using postcodes.io API.
 *
 * Uses WordPress transients to cache results for 7 days to avoid expensive API calls.
 *
 * @since 0.1.0
 *
 * @param string $postcode The postcode to lookup.
 * @return string|null The outcode or null if not found/error.
 */
function get_postcode_outcode($postcode) {
    if (empty($postcode)) {
        return null;
    }

    // Normalize postcode for cache key (uppercase, remove spaces)
    $normalizedPostcode = strtoupper(str_replace(' ', '', trim($postcode)));
    $cacheKey = 'gmtu_postcode_outcode_' . md5($normalizedPostcode);

    // Try to get from cache first
    $cachedOutcode = get_transient($cacheKey);
    if ($cachedOutcode !== false) {
        log_info("Postcode outcode cache hit for: $postcode -> $cachedOutcode");
        return $cachedOutcode;
    }

    // Cache miss - fetch from API
    log_info("Postcode outcode cache miss, fetching from API: $normalizedPostcode");

    $url = "https://api.postcodes.io/postcodes/" . rawurlencode($normalizedPostcode);
    $response = wp_remote_get($url);

    if (is_wp_error($response)) {
        log_warning("Postcode API request failed for $normalizedPostcode: " . $response->get_error_message());
        return null;
    }

    $statusCode = wp_remote_retrieve_response_code($response);
    if ($statusCode !== 200) {
        log_warning("Postcode API returned status $statusCode for $normalizedPostcode");
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $postcodesData = json_decode($body, true);
    $outcode = $postcodesData["result"]["outcode"] ?? null;

    if ($outcode) {
        $outcode = trim($outcode);

        // Cache the result for 7 days
        // Postcodes don't change, so a long cache is safe
        set_transient($cacheKey, $outcode, 7 * DAY_IN_SECONDS);

        log_info("Cached postcode outcode: $normalizedPostcode -> $outcode");
    }

    return $outcode;
}

/**
 * Parse the outcode from a postcode without calling the postcodes.io API.
 *
 * `get_postcode_outcode()` is the right call during a signup: one member, one
 * lookup, validated against a real postcode database. Bulk jobs that walk the
 * whole membership cannot afford an API call each, so they use this instead.
 *
 * Accepts a full postcode or a bare outcode, with or without a space, in any
 * case. Returns null if the input is not shaped like a UK postcode, which
 * callers should treat as "cannot classify this member" rather than guessing.
 *
 * @since 1.5.12
 *
 * @param string|null $postcode The postcode to parse.
 * @return string|null The uppercased outcode, or null if it cannot be parsed.
 */
function parse_outcode($postcode) {
    if (empty($postcode)) {
        return null;
    }

    $normalised = strtoupper(preg_replace('/\s+/', '', $postcode));

    // Outward code is 2 to 4 characters; the inward code, if present, is
    // always a digit followed by two letters.
    if (!preg_match('/^([A-Z]{1,2}[0-9][A-Z0-9]?)([0-9][A-Z]{2})?$/', $normalised, $matches)) {
        return null;
    }

    return $matches[1];
}
