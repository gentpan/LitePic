<?php
declare(strict_types=1);

/**
 * /api/v1/albums/* dispatch — sets up CORS / JSON / bootstrap, parses the
 * route variables that v1.php captured into $m, and hands off to
 * {@see \LitePic\Http\Controllers\AlbumController}.
 *
 * Action mapping:
 *   GET  /albums                  → list
 *   POST /albums                  → create
 *   GET  /albums/<slug>           → show
 *   POST /albums/<slug>           → update | delete           (form_action)
 *   GET  /albums/<slug>/images    → images
 *   POST /albums/<slug>/images    → add | remove | reorder    (form_action)
 *
 * `form_action` lives in $_POST or the JSON body. Defaults to 'update' /
 * 'add' so simple POSTs still do the obvious thing.
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
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, X-Requested-With, X-CSRF-Token');
$_origin = cors_origin();
if ($_origin !== '') {
    header('Access-Control-Allow-Origin: ' . $_origin);
}
unset($_origin);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// $m comes from the regex in v1.php:
//   $m[1] = slug (optional)
//   $m[2] = 'images' (optional)
$slug = (string)($m[1] ?? '');
$resource = (string)($m[2] ?? '');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Pull form_action from POST (form-encoded) or the JSON body.
$formAction = (string)($_POST['form_action'] ?? '');
if ($formAction === '' && $method === 'POST') {
    $raw = (string)file_get_contents('php://input');
    if ($raw !== '') {
        $body = json_decode($raw, true);
        if (is_array($body) && isset($body['form_action'])) {
            $formAction = (string)$body['form_action'];
        }
    }
}

// Resolve the action verb.
if ($resource === 'images') {
    if ($method === 'GET') {
        $action = 'images';
    } else {
        $action = $formAction !== '' ? $formAction : 'add';   // POST default = add
    }
} else {
    if ($method === 'GET') {
        $action = $slug === '' ? 'list' : 'show';
    } else {
        if ($slug === '') {
            $action = 'create';
        } else {
            $action = $formAction !== '' ? $formAction : 'update';   // POST default = update
        }
    }
}

(new \LitePic\Http\Controllers\AlbumController())->dispatch($action, $slug);
