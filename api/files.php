<?php
declare(strict_types=1);

/**
 * /api/v1/files dispatch — the admin file browser's backend.
 *
 * Action mapping:
 *   GET  /files                      → list    (?path=&offset=&limit=)
 *   POST /files  form_action=move    → move    {paths[], to}
 *   POST /files  form_action=copy    → copy    {paths[], to}
 *   POST /files  form_action=delete  → delete  {paths[]}
 *   POST /files  form_action=rename  → rename  {path, name}
 *   POST /files  form_action=folder  → folder  {name}
 *
 * No CORS allowance here, unlike the album endpoints: this is admin-only and
 * cookie-authenticated, so there is no cross-origin caller to accommodate.
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
header('Cache-Control: private, no-store');

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $action = 'list';
} else {
    $action = (string)($_POST['form_action'] ?? '');
    if ($action === '') {
        $raw = (string)file_get_contents('php://input');
        if ($raw !== '') {
            $body = json_decode($raw, true);
            if (is_array($body) && isset($body['form_action'])) {
                $action = (string)$body['form_action'];
            }
        }
    }
}

(new \LitePic\Http\Controllers\FileBrowserController())->dispatch($action);
