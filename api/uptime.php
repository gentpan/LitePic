<?php
declare(strict_types=1);

/**
 * GET /api/v1/uptime?range={1h|1d|30d|90d}
 *
 * Returns the uptime series consumed by the runtime-info uptime strip.
 * Admin-only because this exposes traffic patterns of the install
 * (a public endpoint would let anyone count buckets).
 *
 * Read-only — no CSRF needed; admin cookie OR master X-API-Key works.
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json');
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With');
$_origin = cors_origin();
if ($_origin !== '') {
    header('Access-Control-Allow-Origin: ' . $_origin);
}
unset($_origin);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    \LitePic\Core\Response::error('仅支持 GET 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

// Range switching must always return the selected window. Some browser /
// proxy combinations were reusing a nearby cached response, making 30D / 90D
// appear unclickable in the settings page uptime strip.
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$range = strtolower(trim((string)($_GET['range'] ?? '1d')));
$series = (new \LitePic\Service\Stats\LivenessTracker())->series($range);

\LitePic\Core\Response::success($series);
