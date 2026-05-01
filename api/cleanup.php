<?php
declare(strict_types=1);

/**
 * POST /api/v1/cleanup/scan   — dry-run report of residual rows per category
 * POST /api/v1/cleanup/run    — actually delete; body { categories: ["missing_files", ...] }
 *
 * Auth: admin only — touches DB rows across multiple tables.
 * Always conservative: see OrphanCleaner.php class doc for category rules.
 */

if (!defined('LITEPIC_API_V1_DISPATCH')) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'API route not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \LitePic\Core\Response::error('仅支持 POST 请求', 405);
}

if (!(new \LitePic\Service\Auth\AuthService())->isApiRequestAuthorized()) {
    \LitePic\Core\Response::error('权限不足', 403);
}

$action = (string)($_GET['action'] ?? '');
$cleaner = new \LitePic\Service\Cleanup\OrphanCleaner();

if ($action === 'scan') {
    \LitePic\Core\Response::success($cleaner->scan());
}

if ($action === 'run') {
    // Body may be JSON or form-encoded — support both.
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = [];
    if ($rawBody !== '' && str_starts_with(trim($rawBody), '{')) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    } else {
        $payload = $_POST;
    }

    $categories = $payload['categories'] ?? [];
    if (!is_array($categories)) {
        \LitePic\Core\Response::error('categories 必须是数组', 400);
    }
    $categories = array_values(array_filter(array_map(
        static fn($x) => is_string($x) ? $x : '',
        $categories
    )));

    if (empty($categories)) {
        \LitePic\Core\Response::error('请至少选择一个清理类别', 400);
    }

    \LitePic\Core\Response::success($cleaner->clean($categories));
}

\LitePic\Core\Response::error('未知操作', 404);
