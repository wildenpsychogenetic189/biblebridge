<?php
/**
 * BibleBridge API Client
 * ----------------------
 * Thin wrapper for fetching data from the BibleBridge API.
 * Used when the reader runs in 'api' mode (no local database).
 */

/**
 * Detect if the current request is from a real browser (not a bot/crawler).
 * Used to generate a human-verification token so only real page views count
 * toward the site's daily API quota.
 */
function bb_is_human_request(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') return false;

    // Common bot patterns
    $botPatterns = [
        'bot', 'crawl', 'spider', 'slurp', 'wget', 'curl',
        'python', 'java/', 'httpclient', 'fetcher', 'scraper',
        'headless', 'phantom', 'lighthouse', 'pagespeed',
        'semrush', 'ahrefs', 'mj12bot', 'dotbot', 'petalbot',
        'yandex', 'baiduspider', 'duckduck', 'facebookexternal',
        'twitterbot', 'linkedinbot', 'whatsapp', 'telegram',
        'applebot', 'ia_archiver', 'archive.org',
    ];

    $uaLower = strtolower($ua);
    foreach ($botPatterns as $pattern) {
        if (str_contains($uaLower, $pattern)) return false;
    }

    return true;
}

/**
 * Generate the human-verification token for API requests.
 * Token = HMAC-SHA256(today's date, api_key).
 * API only counts quota when this token is present and valid.
 */
function bb_human_token(): string
{
    global $bbInstall;
    return hash_hmac('sha256', date('Y-m-d'), $bbInstall['api_key']);
}

/**
 * Make a GET request to the BibleBridge API.
 *
 * @param string $endpoint  e.g. '/scripture', '/cross-references', '/topics'
 * @param array  $params    Query parameters
 * @return array|null       Decoded JSON response, or null on failure
 */
function bb_api_get(string $endpoint, array $params = []): ?array
{
    global $bbInstall;

    $apiKey = $bbInstall['api_key'] ?? '';
    if (!$apiKey || $apiKey === 'bb_free_demo') return null;

    $url = rtrim($bbInstall['api_url'], '/') . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $headers = "X-API-Key: {$bbInstall['api_key']}\r\nAccept: application/json\r\nX-BB-Client: standalone\r\n";

    // Only send human token for real browser requests — bots get served but don't burn quota
    if (bb_is_human_request()) {
        $headers .= "X-BB-Human: " . bb_human_token() . "\r\n";
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => $headers,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;

    // Check HTTP status from response headers
    $httpCode = 200;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $m)) {
                $httpCode = (int)$m[1];
            }
        }
    }

    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    // Inject HTTP status so callers can detect rate limiting
    if ($httpCode === 429) {
        $data['_http_status'] = 429;
        $data['_rate_limited'] = true;
    }

    return $data;
}

/**
 * Check if an API response indicates rate limiting.
 */
function bb_is_rate_limited(?array $response): bool
{
    return $response !== null && !empty($response['_rate_limited']);
}

/**
 * Fetch a full chapter of verses from the API.
 *
 * @return array  [['verse' => int, 'text' => string], ...]
 */
// Global flag: set when API returns 429
$GLOBALS['bb_api_rate_limited'] = false;
$GLOBALS['bb_api_rate_reason'] = '';

function bb_api_chapter(int $bookId, int $chapter, string $version = 'kjv'): array
{
    $data = bb_api_get('/scripture', [
        'book_id' => $bookId,
        'chapter' => $chapter,
        'v'       => $version,
    ]);

    if (bb_is_rate_limited($data)) {
        $GLOBALS['bb_api_rate_limited'] = true;
        $GLOBALS['bb_api_rate_reason'] = $data['reason'] ?? 'quota';
        return [];
    }

    if (!$data || ($data['status'] ?? '') !== 'success') return [];

    // API returns 'data' as array of {verse, text}
    $verses = $data['data'] ?? [];

    // Normalize — API may return single verse as object
    if (isset($verses['verse'])) {
        $verses = [$verses];
    }

    return $verses;
}

/**
 * Fetch cross-references for a reference string from the API.
 *
 * @return array  Full API response (reference, cross_references, source_topics)
 */
function bb_api_xrefs(string $reference, string $version = 'kjv', int $limit = 8): ?array
{
    $data = bb_api_get('/cross-references', [
        'reference' => $reference,
        'version'   => $version,
        'limit'     => $limit,
    ]);

    if (bb_is_rate_limited($data)) {
        $GLOBALS['bb_api_rate_limited'] = true;
        $GLOBALS['bb_api_rate_reason'] = $data['reason'] ?? 'quota';
    }

    return $data;
}

/**
 * Fetch search results from the API.
 *
 * @return array|null  Full API response
 */
function bb_api_search(string $query, string $version = 'kjv', int $page = 1, int $limit = 25, int $bookFilter = 0): ?array
{
    $params = [
        'search'  => $query,
        'version' => $version,
        'limit'   => $limit,
        'page'    => $page,
    ];
    if ($bookFilter > 0) {
        $params['book_id'] = $bookFilter;
    }
    $data = bb_api_get('/search', $params);

    if (bb_is_rate_limited($data)) {
        $GLOBALS['bb_api_rate_limited'] = true;
        $GLOBALS['bb_api_rate_reason'] = $data['reason'] ?? 'quota';
    }

    return $data;
}

/**
 * Fetch topic data from the API.
 *
 * @param string|null $slug  Topic slug, or null for browse mode
 * @return array|null
 */
function bb_api_topics(?string $slug = null, ?string $query = null): ?array
{
    $endpoint = $slug ? "/topics/{$slug}" : '/topics';
    $params = [];
    if ($query !== null) $params['q'] = $query;
    $data = bb_api_get($endpoint, $params);

    if (bb_is_rate_limited($data)) {
        $GLOBALS['bb_api_rate_limited'] = true;
        $GLOBALS['bb_api_rate_reason'] = $data['reason'] ?? 'quota';
    }

    return $data;
}

/**
 * Fetch verse of the day from the API.
 *
 * @return array|null  {reference, text}
 */
function bb_api_votd(): ?array
{
    return bb_api_get('/votd');
}
