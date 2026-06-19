<?php
/**
 * ajax/scan_missing_ajax.php
 *
 * Dedicated endpoint for the Missing Links Scanner.
 * Performs a true recursive BFS link-discovery crawl ENTIRELY in PHP —
 * no Python subprocess, no background process, no silent failures.
 *
 * Actions (POST):
 *   start   — kick off a scan; writes a status file; returns {success, scan_id}
 *   status  — poll progress; returns {running, found, fetched, finished, error}
 *   stop    — stop a running scan
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

ob_start();

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

set_exception_handler(function($e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine()
    ]);
    exit;
});

session_start();
require_once __DIR__ . '/../db.php';

ob_clean();

header('Content-Type: application/json');
@set_time_limit(0);
ignore_user_abort(false);

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$source_id = (int)($_POST['source_id'] ?? $_GET['source_id'] ?? 0);

// ═══════════════════════════════════════════════════════════════════════════
// URL CANONICALIZATION & VALIDATION
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Query parameters that should always be stripped — these never identify
 * unique content but do create infinite URL variants.
 */
const STRIP_PARAMS = [
    // WordPress internal / redirect params
    'p', 'page_id', 'post_type', 'preview', 'ver',
    // WordPress pagination query param — real paginated content uses /page/N/ paths.
    // ?page=N on archive URLs (e.g. /uncategorized/news-updates?page=3) only
    // generates infinite recursive variants; strip it entirely.
    'page', 'paged',
    // Calendar / event plugins
    'ical', 'outlook-ical', 'webcal', 'tribe-bar-date',
    // Tracking
    'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    'fbclid', 'gclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga',
    // Session / auth tokens
    'sid', 'session_id', 'PHPSESSID', 'token', 'nonce',
    // Misc noise
    'replytocom', 'unapproved', 'moderation-hash',
];

/**
 * URL path / query patterns that identify non-content WordPress URLs.
 * Any URL matching one of these is permanently rejected.
 */
const WP_SKIP_PATTERNS = [
    // WordPress feeds & APIs
    '/feed/',
    '/feed',
    '/comments/feed/',
    '/trackback/',
    '/wp-json/',
    '/wp-admin/',
    '/wp-login.php',   // also catches ?action=lostpassword etc. via path match
    '/wp-cron.php',
    '/xmlrpc.php',
    '/wp-comments-post.php',
    '/wp-register.php',
    // Taxonomy / archive noise — these are index pages, not content
    '/author/',
    '/tag/',
    '/attachment/',
    '/category/',      // category archive — adds no unique content
    // WordPress path-style pagination: /page/N/ — archive index pages only,
    // not actual content. Individual articles never contain /page/ in their URL.
    '/page/',
    // Query string patterns
    'action=lostpassword',
    'action=register',
    'action=login',
    's=',              // search results (matches ?s= and &s=)
    'replytocom=',
    'unapproved=',
    'webcal://',
    'webcal%3A',
    'tribe_events',
    'post_type=tribe',
    '/ical/',
    'ical=1',
    'outlook-ical=1',
];

/**
 * Maximum allowed URL length. MMU never has real pages with URLs this long.
 */
const MAX_URL_LENGTH = 300;

/**
 * Maximum BFS depth — stops runaway recursion on weird sites.
 */
const MAX_CRAWL_DEPTH = 8;

/**
 * Decode a URL completely (handles multi-encoding like %252F → %2F → /).
 */
function full_decode(string $url): string {
    $prev = '';
    $decoded = $url;
    $iterations = 0;
    while ($decoded !== $prev && $iterations < 5) {
        $prev = $decoded;
        $decoded = urldecode($decoded);
        $iterations++;
    }
    return $decoded;
}

/**
 * Canonicalize a URL:
 *   1. Fully decode it (catches ?p=11165%2Fnews%2Farticle → ?p=11165/news/article)
 *   2. Strip banned query params
 *   3. Detect if a ?p= or ?page_id= was left in path (after decoding) and rebuild
 *   4. Normalize scheme + host (lowercase)
 *   5. Remove duplicate slashes from path
 *   6. Remove trailing slash (except root)
 *   7. Remove fragment
 *   8. Rebuild canonical form
 *
 * Returns null if the URL is structurally unsalvageable.
 */
function canonicalize_url(string $raw_url, string $base_url = ''): ?string {
    // Length guard before expensive operations
    if (strlen($raw_url) > MAX_URL_LENGTH * 2) return null;

    // Make absolute
    if (strpos($raw_url, 'http') !== 0) {
        if (!$base_url) return null;
        $raw_url = rtrim($base_url, '/') . '/' . ltrim($raw_url, '/');
    }

    // Reject webcal:// and similar non-http schemes (even if URL-encoded)
    $lower = strtolower($raw_url);
    if (strpos($lower, 'webcal') !== false) return null;
    if (strpos($lower, 'javascript:') !== false) return null;
    if (strpos($lower, 'mailto:') !== false) return null;
    if (strpos($lower, 'tel:') !== false) return null;
    if (strpos($lower, 'data:') !== false) return null;

    // Fully decode first — this exposes hidden path-in-query patterns
    $decoded = full_decode($raw_url);

    // After full decode, re-check length
    if (strlen($decoded) > MAX_URL_LENGTH) return null;

    // Re-check for webcal after decoding (was URL-encoded)
    if (strpos(strtolower($decoded), 'webcal') !== false) return null;

    // Parse
    $p = parse_url($decoded);
    if (!$p || empty($p['host'])) return null;

    $scheme = strtolower($p['scheme'] ?? 'https');
    $host   = strtolower($p['host']);
    $path   = $p['path'] ?? '/';
    $query  = $p['query'] ?? '';

    // Parse query parameters
    $params = [];
    if ($query !== '') {
        parse_str($query, $params);
    }

    // ── Special: detect decoded ?p=PATH or ?page_id=PATH patterns ──────────
    // After full_decode, ?p=11165/news/article exposes the real path embedded
    // in the value. Extract it and use it as the actual URL path.
    foreach (['p', 'page_id'] as $wp_param) {
        if (isset($params[$wp_param])) {
            $val = $params[$wp_param];
            // If the value looks like a path (contains slashes), use it
            if (strpos($val, '/') !== false) {
                // e.g. "11165/news/mmu-sets-up-demo-backyard..."
                // Strip leading numeric ID segment if present
                $val = preg_replace('/^\d+\//', '', $val);
                if ($val !== '') {
                    $path  = '/' . ltrim($val, '/');
                    unset($params[$wp_param]);
                }
            } else {
                // Bare numeric ?p=2876 / ?page_id=7478 — WordPress shortcut
                // that 301-redirects to the real permalink. We MUST NOT strip
                // the param (that silently collapses it to the homepage /), and
                // we cannot canonicalize it without following the redirect.
                // Return null here; extract_links() will call resolve_canonical()
                // on the original href so the real URL gets queued instead.
                return null;
            }
        }
    }

    // ── Strip all banned query parameters ───────────────────────────────────
    foreach (STRIP_PARAMS as $bad) {
        unset($params[$bad]);
    }

    // Strip page= if its value contains a path (malformed recursive pagination)
    if (isset($params['page']) && strpos((string)$params['page'], '/') !== false) {
        unset($params['page']);
    }

    // Strip any remaining utm_* or tracking params by prefix
    foreach (array_keys($params) as $key) {
        if (strpos($key, 'utm_') === 0 || strpos($key, '_ga') === 0) {
            unset($params[$key]);
        }
    }

    // ── Normalize path ───────────────────────────────────────────────────────
    // Remove double slashes
    $path = preg_replace('#/{2,}#', '/', $path);
    // Resolve . and .. segments
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($segments);
        } elseif ($seg !== '.') {
            $segments[] = $seg;
        }
    }
    $path = implode('/', $segments) ?: '/';

    // Ensure leading slash
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;

    // Trailing slash: keep only on root; remove elsewhere for consistency
    if ($path !== '/' && substr($path, -1) === '/') {
        $path = rtrim($path, '/');
    }

    // ── Rebuild canonical URL ────────────────────────────────────────────────
    $canonical = $scheme . '://' . $host . $path;
    if (!empty($params)) {
        ksort($params);
        $canonical .= '?' . http_build_query($params);
    }
    // No fragment — ever

    // Final length guard
    if (strlen($canonical) > MAX_URL_LENGTH) return null;

    return $canonical;
}

/**
 * Resolve a URL through redirects and return the final canonical URL.
 * Returns null if the final URL is outside the allowed domain or is bad.
 */
function resolve_canonical(string $url, string $base_domain, int $timeout = 10): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 8,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Campus-AI-Bot/2.0 (Educational Purpose)',
        CURLOPT_NOBODY         => true,   // HEAD request — just follow redirects
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $final_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code >= 400 || !$final_url) return null;

    $canonical = canonicalize_url($final_url);
    if (!$canonical) return null;

    $host = strtolower(parse_url($canonical, PHP_URL_HOST) ?? '');
    if ($host !== $base_domain) return null;

    return $canonical;
}

// ═══════════════════════════════════════════════════════════════════════════
// URL REJECTION FILTERS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * File extensions that are never HTML content pages.
 */
const SKIP_EXTENSIONS = [
    '.jpg','.jpeg','.png','.gif','.webp','.svg','.ico','.bmp','.tiff',
    '.pdf','.doc','.docx','.xls','.xlsx','.ppt','.pptx',
    '.zip','.rar','.gz','.tar','.7z',
    '.mp3','.mp4','.avi','.mov','.wmv','.flv','.wav','.ogg',
    '.css','.js','.json','.xml','.rss','.atom',
    '.exe','.msi','.dmg','.apk',
    '.woff','.woff2','.ttf','.eot',
    '.ics','.ical',
];

/**
 * Return true if this URL should never be crawled.
 * Applies AFTER canonicalization so we work with clean URLs.
 */
function should_reject_url(string $url): bool {
    // Length
    if (strlen($url) > MAX_URL_LENGTH) return true;

    $lower_url = strtolower($url);
    $path      = strtolower(parse_url($url, PHP_URL_PATH) ?? '/');
    $query     = strtolower(parse_url($url, PHP_URL_QUERY) ?? '');
    $full      = $lower_url;

    // ── Extension filter ─────────────────────────────────────────────────────
    foreach (SKIP_EXTENSIONS as $ext) {
        if (substr($path, -strlen($ext)) === $ext) return true;
    }

    // ── WordPress-specific pattern filter ────────────────────────────────────
    foreach (WP_SKIP_PATTERNS as $pat) {
        if (strpos($full, strtolower($pat)) !== false) return true;
    }

    // ── Pagination: /page/N/ is valid; /page/N/page/M/ is not ───────────────
    if (preg_match('#/page/\d+/page/#', $path)) return true;

    // ── Recursive path detection ─────────────────────────────────────────────
    // e.g. /kingster/event-calendar/kingster/event-calendar/
    $segments = array_values(array_filter(explode('/', $path)));
    $segment_count = count($segments);
    if ($segment_count >= 4) {
        // Check for any repeated window of 1–3 segments
        for ($window = 1; $window <= 3; $window++) {
            for ($i = 0; $i + $window * 2 <= $segment_count; $i++) {
                $a = array_slice($segments, $i, $window);
                $b = array_slice($segments, $i + $window, $window);
                if ($a === $b) return true;
            }
        }
    }

    // ── Malformed / deeply encoded patterns ──────────────────────────────────
    // Reject if still contains suspicious encoded sequences after canonicalization
    if (preg_match('/%2F%3F|%3Fp%3D|%252F/i', $url)) return true;

    // ── Query string: reject ?p=, ?post_type= if somehow still present ───────
    // Note: PHP_URL_QUERY returns query WITHOUT leading '?', so no [?&] prefix needed
    if (preg_match('/\bp=\d+\b/i', $query)) return true;
    if (preg_match('/\bpost_type=/i', $query)) return true;

    // ── page= with embedded path  (e.g. ?page=39/uncategorized/news-updates) ─
    if (preg_match('/\bpage=[^&]*\//', $query)) return true;

    // ── Deep nesting guard: more than 8 path segments is suspicious ──────────
    if ($segment_count > 8) return true;

    return false;
}

function same_domain(string $url, string $base_domain): bool {
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    return $host === $base_domain;
}

// ═══════════════════════════════════════════════════════════════════════════
// SKIP PATTERN MATCHING (user-defined in DB)
// ═══════════════════════════════════════════════════════════════════════════

function matches_skip_patterns(string $url, array $skip_patterns): bool {
    foreach ($skip_patterns as $p) {
        $pattern = $p['pattern'];
        switch ($p['type']) {
            case 'contains':
                if (strpos($url, $pattern) !== false) return true;
                break;
            case 'starts_with':
                if (strpos($url, $pattern) === 0) return true;
                break;
            case 'regex':
                if (@preg_match($pattern, $url)) return true;
                break;
        }
    }
    return false;
}

// ═══════════════════════════════════════════════════════════════════════════
// HTML FETCH & LINK EXTRACTION
// ═══════════════════════════════════════════════════════════════════════════

function fetch_html(string $url, int $timeout = 15): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_USERAGENT      => 'Campus-AI-Bot/2.0 (Educational Purpose)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code >= 400 || $html === false) return null;
    if (stripos($type ?? '', 'text/html') === false) return null;
    return $html;
}

/**
 * Extract, canonicalize, and filter all links from a page.
 * Returns only clean, same-domain URLs that pass all filters.
 *
 * @param string[] $skip_patterns  User-defined DB skip patterns
 */
function extract_links(
    string $html,
    string $page_url,
    string $base_url,
    string $base_domain,
    array  $skip_patterns
): array {
    $links = [];

    preg_match_all('/<a[^>]+href=["\']([^"\'#\s][^"\']*)["\'][^>]*>/i', $html, $m);
    foreach ($m[1] as $href) {
        $href = trim($href);
        if (!$href) continue;

        // Quick pre-decode reject for obviously malformed hrefs
        $lower_href = strtolower($href);
        if (strpos($lower_href, 'javascript:') === 0) continue;
        if (strpos($lower_href, 'mailto:') === 0) continue;
        if (strpos($lower_href, 'tel:') === 0) continue;
        if (strpos($lower_href, 'data:') === 0) continue;
        if (strpos($lower_href, 'webcal') !== false) continue;

        // Make absolute using page URL as base (handles relative links correctly)
        if (strpos($href, 'http') !== 0) {
            // Relative URL — use page URL, not base_url, for correct resolution
            $href = resolve_relative($href, $page_url, $base_url);
        }
        if (!$href) continue;

        // ── Handle ALL WordPress ?p= and ?page_id= shortcut URLs ──────────
        // These are WordPress internal redirect URLs. We always resolve them
        // via HTTP redirect to get the real permalink, regardless of whether
        // the value is a bare number (?p=2876) or an encoded path (?p=11165%2F...).
        // canonicalize_url() returns null for all of these.
        $decoded_href = urldecode(urldecode($href)); // double-decode to expose %2F etc.
        if (preg_match('/[?&](p|page_id)=/i', $decoded_href)) {
            // It's a WordPress shortcut — follow the redirect
            $resolved = resolve_canonical($href, $base_domain, 8);
            if (!$resolved) continue;
            if (should_reject_url($resolved)) continue;
            if (matches_skip_patterns($resolved, $skip_patterns)) continue;
            $links[] = $resolved;
            continue;
        }

        // Canonicalize
        $canonical = canonicalize_url($href, $base_url);
        if (!$canonical) continue;

        // Domain check
        if (!same_domain($canonical, $base_domain)) continue;

        // Reject bad patterns
        if (should_reject_url($canonical)) continue;

        // User-defined skip patterns
        if (matches_skip_patterns($canonical, $skip_patterns)) continue;

        $links[] = $canonical;
    }

    return array_unique($links);
}

/**
 * Resolve a relative URL against the current page URL.
 */
function resolve_relative(string $href, string $page_url, string $base_url): string {
    if ($href === '' || $href[0] === '#') return '';

    // Protocol-relative
    if (substr($href, 0, 2) === '//') {
        $scheme = parse_url($base_url, PHP_URL_SCHEME) ?? 'https';
        return $scheme . ':' . $href;
    }

    // Absolute path (relative to domain)
    if ($href[0] === '/') {
        $p = parse_url($base_url);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $href;
    }

    // Query-only (e.g. ?foo=bar)
    if ($href[0] === '?') {
        $base = strtok($page_url, '?');
        return $base . $href;
    }

    // Relative path — resolve against page directory
    $dir = rtrim(dirname(parse_url($page_url, PHP_URL_PATH) ?? '/'), '/');
    $p   = parse_url($base_url);
    return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . $dir . '/' . $href;
}

// ═══════════════════════════════════════════════════════════════════════════
// SITEMAP
// ═══════════════════════════════════════════════════════════════════════════

function fetch_sitemap_urls(string $base_url, string $base_domain): array {
    $found = [];
    $tries = [
        rtrim($base_url, '/') . '/sitemap.xml',
        rtrim($base_url, '/') . '/sitemap_index.xml',
        rtrim($base_url, '/') . '/wp-sitemap.xml',
    ];
    foreach ($tries as $surl) {
        $ch = curl_init($surl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'Campus-AI-Bot/2.0',
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$xml) continue;

        preg_match_all('/<loc>\s*(https?:\/\/[^<\s]+)\s*<\/loc>/i', $xml, $ml);
        foreach ($ml[1] as $loc) {
            $canon = canonicalize_url(trim($loc));
            if (!$canon) continue;
            if (!same_domain($canon, $base_domain)) continue;
            if (should_reject_url($canon)) continue;

            // Is it a sub-sitemap (.xml)?
            if (substr(strtolower(parse_url($canon, PHP_URL_PATH) ?? ''), -4) === '.xml') {
                $ch2 = curl_init($canon);
                curl_setopt_array($ch2, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                $xml2  = curl_exec($ch2);
                $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                if ($code2 === 200 && $xml2) {
                    preg_match_all('/<loc>\s*(https?:\/\/[^<\s]+)\s*<\/loc>/i', $xml2, $ml2);
                    foreach ($ml2[1] as $loc2) {
                        $c2 = canonicalize_url(trim($loc2));
                        if ($c2 && same_domain($c2, $base_domain) && !should_reject_url($c2)) {
                            $found[] = $c2;
                        }
                    }
                }
            } else {
                $found[] = $canon;
            }
        }
    }
    return array_unique($found);
}

// ═══════════════════════════════════════════════════════════════════════════
// STATUS FILE HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function status_file(int $source_id): string {
    return sys_get_temp_dir() . "/scan_missing_{$source_id}.json";
}

function write_status(int $source_id, array $data): void {
    file_put_contents(status_file($source_id), json_encode($data));
}

function read_status(int $source_id): array {
    $f = status_file($source_id);
    if (!file_exists($f)) return ['running' => false, 'finished' => false];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : ['running' => false, 'finished' => false];
}

// ═══════════════════════════════════════════════════════════════════════════
// ROUTING
// ═══════════════════════════════════════════════════════════════════════════

try {
    // ── status ────────────────────────────────────────────────────────────
    if ($action === 'status') {
        if (!$source_id) throw new Exception('source_id required');
        echo json_encode(read_status($source_id));
        exit;
    }

    // ── stop ──────────────────────────────────────────────────────────────
    if ($action === 'stop') {
        if (!$source_id) throw new Exception('source_id required');
        $sf = status_file($source_id);
        if (file_exists($sf)) {
            // Write stopped flag before deleting so running scan can detect it
            write_status($source_id, [
                'running' => false,
                'stopped' => true,
                'finished' => true,
                'phase' => 'Stopped by user'
            ]);
            // Give the scan loop time to detect the stop
            sleep(1);
            unlink($sf);
        }
        echo json_encode(['success' => true, 'message' => 'Scan stopped']);
        exit;
    }

    // ── start ─────────────────────────────────────────────────────────────
    if ($action !== 'start') throw new Exception("Unknown action: $action");
    if (!$source_id) throw new Exception('source_id required');

    $current_status = read_status($source_id);
    if (!empty($current_status['running'])) {
        echo json_encode(['success' => true, 'already_running' => true, 'message' => 'Scan already in progress']);
        exit;
    }

    // Load source
    $stmt = $conn->prepare("SELECT * FROM scraping_sources WHERE source_id = ?");
    $stmt->bind_param('i', $source_id);
    $stmt->execute();
    $source = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$source) throw new Exception("Source $source_id not found");

    write_status($source_id, [
        'running'  => true,
        'finished' => false,
        'found'    => 0,
        'fetched'  => 0,
        'phase'    => 'Initializing scan…',
        'started'  => time(),
    ]);

    // Return to browser immediately
    echo json_encode(['success' => true, 'started' => true, 'message' => 'Scan started in background']);
    ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ignore_user_abort(true);

    // ═══════════════════════════════════════════════════════════════════════
    // SCAN BODY
    // ═══════════════════════════════════════════════════════════════════════

    $base_url    = rtrim($source['base_url'], '/');
    $base_domain = strtolower(parse_url($base_url, PHP_URL_HOST));
    $config      = json_decode($source['scraping_config'] ?? '{}', true) ?: [];
    $include_pat = $config['url_patterns']['include'] ?? [];
    $exclude_pat = $config['url_patterns']['exclude'] ?? [];

    // ── Step 1: load known URLs, skip patterns, blocked URLs ──────────────
    write_status($source_id, [
        'running' => true, 'finished' => false,
        'found' => 0, 'fetched' => 0,
        'phase' => 'Loading known URLs…', 'started' => time(),
    ]);

    $known        = [];   // canonical URL → true (ever seen)
    $blocked      = [];   // blocked canonical URL → true
    $skip_patterns = [];

    // User-defined skip patterns from DB
    $r_skip = $conn->query("SELECT url_pattern, pattern_type FROM skip_url_patterns WHERE source_id = {$source_id}");
    if ($r_skip) {
        while ($row = $r_skip->fetch_assoc()) {
            $skip_patterns[] = ['pattern' => $row['url_pattern'], 'type' => $row['pattern_type']];
        }
    }

    // Blocked URLs
    $r_blocked = $conn->prepare("SELECT page_url FROM blocked_urls WHERE source_id = ?");
    $r_blocked->bind_param('i', $source_id);
    $r_blocked->execute();
    $res_blocked = $r_blocked->get_result();
    while ($row = $res_blocked->fetch_assoc()) {
        $n = canonicalize_url($row['page_url']) ?? $row['page_url'];
        $blocked[$n] = true;
        $known[$n]   = true;
    }
    $r_blocked->close();

    // Already-scraped pages
    $r = $conn->prepare("SELECT page_url FROM scraped_content WHERE source_id = ?");
    $r->bind_param('i', $source_id);
    $r->execute();
    $res = $r->get_result();
    $scraped_pages = [];
    while ($row = $res->fetch_assoc()) {
        $n = canonicalize_url($row['page_url']) ?? $row['page_url'];
        $known[$n]     = true;
        $scraped_pages[] = $n;
    }
    $r->close();

    // Already-queued URLs
    $r2 = $conn->prepare(
        "SELECT page_url FROM scrape_link_queue
         WHERE source_id = ? AND status IN ('pending','done','scraping','skipped')"
    );
    $r2->bind_param('i', $source_id);
    $r2->execute();
    $res2 = $r2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $n = canonicalize_url($row['page_url']) ?? $row['page_url'];
        $known[$n] = true;
    }
    $r2->close();

    // ── Step 2: content-include/exclude check ────────────────────────────
    $should_scrape = function(string $url) use ($include_pat, $exclude_pat): bool {
        foreach ($exclude_pat as $pat) {
            $pat = trim($pat);
            if ($pat !== '' && strpos($url, $pat) !== false) return false;
        }
        if (!empty($include_pat)) {
            foreach ($include_pat as $pat) {
                $pat = trim($pat);
                if ($pat !== '' && strpos($url, $pat) !== false) return true;
            }
            return false;
        }
        return true;
    };

    // ── Step 3: prepared insert ───────────────────────────────────────────
    $insert_stmt = $conn->prepare("
        INSERT INTO scrape_link_queue (source_id, page_url, discovered_from_url, crawl_depth, status, discovered_at)
        VALUES (?, ?, ?, ?, 'pending', NOW())
        ON DUPLICATE KEY UPDATE
            status = IF(status IN ('done','failed','skipped'), 'pending', status),
            discovered_from_url = VALUES(discovered_from_url)
    ");

    /**
     * Helper: validate + dedup + insert a URL into the queue.
     * Returns true if it was new (not previously known).
     */
    $try_queue = function(
        string $raw_url,
        string $discovered_from,
        int    $depth
    ) use (
        $conn, $insert_stmt, $source_id,
        $base_domain, $blocked, &$known,
        $skip_patterns, $should_scrape
    ): bool {
        // ── Bare WordPress shortcut ?p=N / ?page_id=N ────────────────────────
        // canonicalize_url() returns null for these. Resolve the redirect to
        // get the real permalink, then proceed with the canonical form.
        $decoded_raw = urldecode($raw_url);
        if (preg_match('/[?&](p|page_id)=(\d+)(&|$)/', $decoded_raw)) {
            $resolved = resolve_canonical($raw_url, $base_domain, 8);
            if (!$resolved) return false;
            $raw_url = $resolved;  // fall through with the real URL
        }

        // Canonicalize
        $url = canonicalize_url($raw_url);
        if (!$url) return false;

        // Domain
        if (!same_domain($url, $base_domain)) return false;

        // Hard rejection rules
        if (should_reject_url($url)) return false;

        // Blocked
        if (isset($blocked[$url])) return false;

        // User-defined skip patterns
        if (matches_skip_patterns($url, $skip_patterns)) return false;

        // Include/exclude patterns
        if (!$should_scrape($url)) return false;

        // Already known?
        if (isset($known[$url])) return false;

        // Mark known immediately (prevents re-insertion from parallel links)
        $known[$url] = true;

        $insert_stmt->bind_param('issi', $source_id, $url, $discovered_from, $depth);
        $insert_stmt->execute();
        return true;
    };

    // ── Step 4: seed queue ────────────────────────────────────────────────
    $missing_found = 0;
    $norm_base     = canonicalize_url($base_url) ?? $base_url;

    if (!isset($known[$norm_base])) {
        $d = 0;
        $insert_stmt->bind_param('issi', $source_id, $norm_base, $norm_base, $d);
        $insert_stmt->execute();
        $known[$norm_base] = true;
        $missing_found++;
    }

    // ── Step 5: sitemap seed ──────────────────────────────────────────────
    write_status($source_id, [
        'running' => true, 'finished' => false,
        'found' => $missing_found, 'fetched' => 0,
        'phase' => 'Checking sitemap…',
    ]);

    $sitemap_urls = fetch_sitemap_urls($base_url, $base_domain);
    $d_sitemap = 1;
    foreach ($sitemap_urls as $su) {
        if ($try_queue($su, $norm_base, $d_sitemap)) {
            $missing_found++;
        }
    }

    // ── Step 6: BFS crawl ─────────────────────────────────────────────────
    $fetch_visited = [];  // URLs actually fetched (for link extraction)
    $bfs           = new SplQueue();

    // Seed BFS with base + already-scraped pages
    $bfs->enqueue([$norm_base, 0]);
    foreach ($scraped_pages as $pg) {
        $bfs->enqueue([$pg, 1]);
    }

    $fetched_count   = 0;
    $status_tick     = 0;
    $stop_check_tick = 0;

    while (!$bfs->isEmpty()) {
        [$fetch_url, $depth] = $bfs->dequeue();

        // Normalize again (BFS entries may have been added before full canonicalization)
        $fetch_url = canonicalize_url($fetch_url) ?? $fetch_url;

        // Depth limit
        if ($depth > MAX_CRAWL_DEPTH) continue;

        // Skip if already fetched for link extraction
        if (isset($fetch_visited[$fetch_url])) continue;

        // Re-validate (defensive)
        if (!same_domain($fetch_url, $base_domain)) continue;
        if (should_reject_url($fetch_url)) continue;
        if (isset($blocked[$fetch_url])) continue;
        if (matches_skip_patterns($fetch_url, $skip_patterns)) continue;

        // ── Stop-check ────────────────────────────────────────────────────
        $stop_check_tick++;
        if ($stop_check_tick >= 1) {
            $sf = status_file($source_id);
            if (!file_exists($sf)) {
                // Status file deleted - stop immediately
                exit;
            }
            $cs = read_status($source_id);
            if (!empty($cs['stopped']) || empty($cs['running'])) {
                // Stop flag set - exit gracefully
                exit;
            }
            $stop_check_tick = 0;
        }

        $fetch_visited[$fetch_url] = true;
        $fetched_count++;
        $status_tick++;

        if ($status_tick >= 5) {
            write_status($source_id, [
                'running' => true, 'finished' => false,
                'found'   => $missing_found,
                'fetched' => $fetched_count,
                'phase'   => "Scanning… (fetched: {$fetched_count}, new: {$missing_found})",
            ]);
            $status_tick = 0;
        }

        $html = fetch_html($fetch_url);
        if ($html === null) continue;

        $links = extract_links($html, $fetch_url, $base_url, $base_domain, $skip_patterns);

        foreach ($links as $link) {
            // Depth limit for new discoveries
            if ($depth + 1 > MAX_CRAWL_DEPTH) continue;

            $is_new = $try_queue($link, $fetch_url, $depth + 1);
            if ($is_new) {
                $missing_found++;
                // Only enqueue for BFS crawling if it was genuinely new.
                // If it's already in $known (DB or seen this run), we've
                // already crawled or will crawl it — don't re-add it.
                // This is the critical fix preventing infinite re-crawl loops.
                $bfs->enqueue([$link, $depth + 1]);
            }
        }

        usleep(300000); // 0.3 s polite crawl delay
    }

    $insert_stmt->close();

    // ── Done ──────────────────────────────────────────────────────────────
    write_status($source_id, [
        'running'  => false,
        'finished' => true,
        'found'    => $missing_found,
        'fetched'  => $fetched_count,
        'phase'    => 'Scan complete',
        'elapsed'  => time() - (read_status($source_id)['started'] ?? time()),
    ]);

    $log_stmt = $conn->prepare(
        "INSERT INTO admin_activity_logs (admin_id, action, module, description, ip_address)
         VALUES (?, 'SCAN_MISSING', 'web_scraper', ?, ?)"
    );
    $desc = "PHP smart scan for source $source_id: $missing_found new URLs discovered, $fetched_count pages fetched";
    $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
    $log_stmt->bind_param('iss', $_SESSION['admin_id'], $desc, $ip);
    $log_stmt->execute();
    $log_stmt->close();

    exit;

} catch (Exception $e) {
    if ($source_id) {
        write_status($source_id, [
            'running'  => false,
            'finished' => true,
            'error'    => $e->getMessage(),
            'found'    => 0,
            'fetched'  => 0,
        ]);
    }
    if (!headers_sent()) {
        ob_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        ob_end_flush();
    }
    exit;
}