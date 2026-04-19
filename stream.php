<?php
/**
 * stream.php — HLS-Proxy für Live-TV
 * Leitet HTTP-HLS-Streams über HTTPS durch um Mixed-Content-Fehler zu vermeiden.
 * Verarbeitet sowohl .m3u8 Playlists als auch .ts Segmente.
 *
 * Aufruf: stream.php?url=http://...&token=SESSION_TOKEN
 */

require_once __DIR__ . '/auth.php';

// Auth prüfen — nur eingeloggte Admins
session_start_safe();
$user = current_user();
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403); exit('Forbidden');
}

$url = trim($_GET['url'] ?? '');
if ($url === '' || !preg_match('#^https?://#i', $url)) {
    http_response_code(400); exit('Bad Request');
}

// Nur HTTP-URLs proxyen — HTTPS direkt weiterleiten
if (str_starts_with(strtolower($url), 'https://')) {
    header('Location: ' . $url, true, 302); exit;
}

// Request zum Origin-Server
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HEADER         => true,
]);
$response    = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$effectiveUrl= curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 400) {
    http_response_code(502); exit('Bad Gateway');
}

$headers = substr($response, 0, $headerSize);
$body    = substr($response, $headerSize);

// Content-Type aus Response-Headern
$ct = 'application/octet-stream';
foreach (explode("\r\n", $headers) as $h) {
    if (stripos($h, 'content-type:') === 0) {
        $ct = trim(substr($h, 13)); break;
    }
}

// Basis-URL für relative Pfade in der Playlist ermitteln
$baseUrl = preg_replace('#/[^/]*$#', '/', $effectiveUrl ?: $url);

// M3U8-Playlist: relative URLs auf Proxy-URLs umschreiben
if (str_contains($ct, 'mpegurl') || str_ends_with(strtolower(parse_url($url, PHP_URL_PATH) ?? ''), '.m3u8')) {
    $ct   = 'application/vnd.apple.mpegurl';
    $body = rewriteM3u8($body, $baseUrl);
}

header('Content-Type: ' . $ct);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');
echo $body;

/**
 * Schreibt alle URLs in einer M3U8-Playlist auf den Proxy um.
 */
function rewriteM3u8(string $body, string $baseUrl): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || ($_SERVER['HTTP_CF_VISITOR'] ?? '') === '{"scheme":"https"}'
        ? 'https' : 'http';
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $proxyBase = $scheme . '://'
        . $_SERVER['HTTP_HOST']
        . $dir . '/stream.php?url=';

    $lines  = explode("\n", $body);
    $result = [];
    foreach ($lines as $line) {
        $line = rtrim($line);
        // URI in Tags umschreiben: URI="..."
        $line = preg_replace_callback('/URI="([^"]+)"/', function($m) use ($proxyBase, $baseUrl) {
            $uri = resolveUrl($m[1], $baseUrl);
            return 'URI="' . $proxyBase . urlencode($uri) . '"';
        }, $line);
        // Segment-URLs (Zeilen die nicht mit # beginnen und nicht leer sind)
        if ($line !== '' && $line[0] !== '#') {
            $line = $proxyBase . urlencode(resolveUrl($line, $baseUrl));
        }
        $result[] = $line;
    }
    return implode("\n", $result);
}

function resolveUrl(string $url, string $base): string {
    if (preg_match('#^https?://#i', $url)) return $url;
    if (str_starts_with($url, '/')) {
        $parsed = parse_url($base);
        return ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? '')
            . (isset($parsed['port']) ? ':' . $parsed['port'] : '') . $url;
    }
    return $base . $url;
}
