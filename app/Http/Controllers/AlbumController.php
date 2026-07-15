<?php
declare(strict_types=1);

namespace LitePic\Http\Controllers;

use LitePic\Core\Csrf;
use LitePic\Core\Response;
use LitePic\Repository\AlbumImageRepository;
use LitePic\Repository\AlbumRepository;
use LitePic\Service\Album\AlbumService;
use LitePic\Service\Auth\AuthService;
use LitePic\Service\Image\ImageInfo;
use LitePic\Service\Image\ImageUrl;

/**
 * Admin REST endpoints for albums. Mounted under `/api/v1/albums/*` by
 * the v1 dispatcher. All endpoints require admin auth; unsafe verbs
 * (POST/PATCH/DELETE) also require a valid CSRF token.
 *
 * Endpoint map (action passed in $action). `<key>` is the album's URL key:
 * either its numeric id (default) or, if set, its slug.
 *   list                GET    /api/v1/albums
 *   show                GET    /api/v1/albums/<key>
 *   images              GET    /api/v1/albums/<key>/images
 *   create              POST   /api/v1/albums
 *   update              POST   /api/v1/albums/<key>          (form_action=update)
 *   delete              POST   /api/v1/albums/<key>          (form_action=delete)
 *   add_images          POST   /api/v1/albums/<key>/images   (form_action=add)
 *   remove_images       POST   /api/v1/albums/<key>/images   (form_action=remove)
 *   reorder_images      POST   /api/v1/albums/<key>/images   (form_action=reorder)
 *
 * Errors are returned via {@see Response::error()} which sends a 4xx with
 * a JSON `{status:'error', message:'...'}` body.
 */
final class AlbumController
{
    private AlbumService $service;
    private AlbumRepository $albums;
    private AlbumImageRepository $albumImages;
    private AuthService $auth;

    public function __construct(
        ?AlbumService $service = null,
        ?AlbumRepository $albums = null,
        ?AlbumImageRepository $albumImages = null,
        ?AuthService $auth = null
    ) {
        $this->service = $service ?? new AlbumService();
        $this->albums = $albums ?? new AlbumRepository();
        $this->albumImages = $albumImages ?? new AlbumImageRepository();
        $this->auth = $auth ?? new AuthService();
    }

    public function dispatch(string $action, string $slug = ''): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Reads: accept admin cookie OR master X-API-Key (so scripts can list).
        if ($method === 'GET') {
            $this->requireApiAuth();
            match ($action) {
                'list'   => $this->handleList(),
                'show'   => $this->handleShow($slug),
                'images' => $this->handleImages($slug),
                default  => Response::error('未知的相册操作', 400),
            };
            return;
        }

        // Writes: require admin cookie (session-bound) + CSRF token.
        // X-API-Key alone isn't enough for mutations because CSRF is
        // session-bound; this is the safer default for an admin surface.
        if ($method !== 'POST') {
            Response::error('仅支持 GET / POST 请求', 405);
            return;
        }
        $this->requireAdmin();
        $this->requireCsrf();

        match ($action) {
            'create'   => $this->handleCreate(),
            'update'   => $this->handleUpdate($slug),
            'delete'   => $this->handleDelete($slug),
            'add'      => $this->handleAddImages($slug),
            'remove'   => $this->handleRemoveImages($slug),
            'reorder'  => $this->handleReorderImages($slug),
            default    => Response::error('未知的相册操作', 400),
        };
    }

    // ==================== READS ====================

    private function handleList(): void
    {
        $albums = array_map([$this, 'presentSummary'], $this->albums->all());
        Response::success(['albums' => $albums, 'count' => count($albums)]);
    }

    private function handleShow(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        Response::success(['album' => $this->presentSummary($album)]);
    }

    private function handleImages(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        $filenames = $this->albumImages->listFilenames($album['id']);
        $info = new ImageInfo();
        $images = [];
        foreach ($filenames as $filename) {
            $meta = $info->getSafe($filename);
            if ($meta === null) continue; // Skip orphans (image was deleted)
            $images[] = [
                'filename'   => $filename,
                'url'        => ImageUrl::forIdentifier($filename),
                'thumb_url'  => (string)($meta['thumb_url'] ?? ImageUrl::forIdentifier($filename)),
                'size'       => $meta['size'] ?? 0,
                'dimensions' => $meta['dimensions'] ?? '',
                'time'       => $meta['time'] ?? 0,
            ];
        }
        Response::success([
            'album'  => $this->presentSummary($album),
            'images' => $images,
            'count'  => count($images),
        ]);
    }

    // ==================== WRITES ====================

    private function handleCreate(): void
    {
        $payload = $this->jsonOrPost();
        $result = $this->service->create([
            'name'           => (string)($payload['name'] ?? ''),
            'slug'           => (string)($payload['slug'] ?? ''),
            'description'    => (string)($payload['description'] ?? ''),
            'visibility'     => (string)($payload['visibility'] ?? 'public'),
            'password'       => isset($payload['password']) ? (string)$payload['password'] : null,
            'cover_filename' => (string)($payload['cover_filename'] ?? ''),
        ]);
        if (is_string($result)) {
            Response::error($this->errorMessage($result), 400);
            return;
        }
        $album = $this->albums->find($result);
        Response::success(['album' => $this->presentSummary($album ?? [])]);
    }

    private function handleUpdate(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        $payload = $this->jsonOrPost();

        // Only forward keys the user actually sent — partial update.
        $patch = [];
        foreach (['name', 'slug', 'description', 'visibility', 'cover_filename'] as $k) {
            if (array_key_exists($k, $payload)) $patch[$k] = $payload[$k];
        }
        // password is special: present-but-empty wipes; absent leaves alone.
        if (array_key_exists('password', $payload)) {
            $patch['password'] = $payload['password'] === '' ? null : $payload['password'];
        }

        $result = $this->service->update((int)$album['id'], $patch);
        if ($result !== true) {
            Response::error($this->errorMessage($result), 400);
            return;
        }
        $fresh = $this->albums->find((int)$album['id']);
        Response::success(['album' => $this->presentSummary($fresh ?? [])]);
    }

    private function handleDelete(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        $ok = $this->service->delete((int)$album['id']);
        if (!$ok) {
            Response::error('删除相册失败', 500);
            return;
        }
        Response::success(['deleted' => true]);
    }

    private function handleAddImages(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        $payload = $this->jsonOrPost();
        $filenames = $this->collectFilenames($payload);
        if ($filenames === []) {
            Response::error('未提供 filenames', 400);
            return;
        }
        $count = $this->service->addImages((int)$album['id'], $filenames);
        $fresh = $this->albums->find((int)$album['id']);
        Response::success([
            'added' => $count,
            'image_count' => $fresh['image_count'] ?? 0,
        ]);
    }

    private function handleRemoveImages(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        $payload = $this->jsonOrPost();
        $filenames = $this->collectFilenames($payload);
        if ($filenames === []) {
            Response::error('未提供 filenames', 400);
            return;
        }
        $count = $this->service->removeImages((int)$album['id'], $filenames);
        $fresh = $this->albums->find((int)$album['id']);
        Response::success([
            'removed' => $count,
            'image_count' => $fresh['image_count'] ?? 0,
        ]);
    }

    private function handleReorderImages(string $slug): void
    {
        $album = $this->mustFindByKey($slug);
        $payload = $this->jsonOrPost();
        $filenames = $this->collectFilenames($payload);
        if ($filenames === []) {
            Response::error('未提供 filenames', 400);
            return;
        }
        $this->service->reorder((int)$album['id'], $filenames);
        Response::success(['reordered' => count($filenames)]);
    }

    // ==================== HELPERS ====================

    private function requireAdmin(): void
    {
        if (!$this->auth->isAdmin()) {
            Response::error('权限不足', 403);
            exit;
        }
    }

    /**
     * Read-side auth — admin cookie OR master API key (X-API-Key /
     * Authorization: Bearer). Lets external scripts list albums without
     * a session.
     */
    private function requireApiAuth(): void
    {
        if (!$this->auth->isApiRequestAuthorized()) {
            Response::error('权限不足', 403);
            exit;
        }
    }

    private function requireCsrf(): void
    {
        $token = Csrf::requestToken();
        if (!Csrf::verify($token)) {
            Response::error('CSRF 令牌无效或已过期', 403);
            exit;
        }
    }

    /**
     * Resolve the album for an API request. The `<key>` segment in
     * `/api/v1/albums/<key>/...` may be either:
     *   - a numeric id (default for albums with no slug), or
     *   - a slug string (admin-set).
     */
    private function mustFindByKey(string $key): array
    {
        $key = trim($key);
        $album = $key !== '' ? $this->albums->findByKey($key) : null;
        if ($album === null) {
            Response::error('相册不存在', 404);
            exit;
        }
        return $album;
    }

    /**
     * Read POST or JSON body — supports both content types so curl/SDK
     * users can send either application/json or x-www-form-urlencoded.
     *
     * @return array<string, mixed>
     */
    private function jsonOrPost(): array
    {
        if (!empty($_POST)) return $_POST;

        $raw = (string)file_get_contents('php://input');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pull filenames from either `filenames` (array) or `filename` (single).
     * Trims, de-dupes, drops empty.
     *
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function collectFilenames(array $payload): array
    {
        $raw = $payload['filenames'] ?? $payload['filename'] ?? null;
        if ($raw === null) return [];
        if (!is_array($raw)) $raw = [$raw];
        $clean = [];
        foreach ($raw as $f) {
            $f = trim((string)$f);
            if ($f !== '' && !in_array($f, $clean, true)) $clean[] = $f;
        }
        return $clean;
    }

    /**
     * Strip server-side state out of an album row before sending it over the
     * wire. Drops password_hash, exposes embed URL only when embeddable.
     *
     * @param array<string,mixed> $album
     * @return array<string,mixed>
     */
    private function presentSummary(array $album): array
    {
        if ($album === []) return [];
        $cover = $album['cover_filename'] ?? null;
        return [
            'id'              => $album['id'],
            'slug'            => $album['slug'],
            'name'            => $album['name'],
            'description'     => $album['description'],
            'cover_filename'  => $cover,
            'cover_url'       => is_string($cover) && $cover !== ''
                                 ? ImageUrl::thumbnailUrl($cover) : null,
            'visibility'      => $album['visibility'],
            'has_password'    => ($album['password_hash'] ?? null) !== null,
            'embed_token'     => AlbumService::isEmbeddable((string)$album['visibility'])
                                 ? $album['embed_token']
                                 : '',
            'image_count'     => $album['image_count'],
            'view_count'      => $album['view_count'],
            'created_at'      => $album['created_at'],
            'updated_at'      => $album['updated_at'],
        ];
    }

    /**
     * Map service error codes to user-facing messages.
     */
    private function errorMessage(string $code): string
    {
        return match ($code) {
            'name_required'      => '请填写相册名称',
            'slug_invalid'       => 'slug 不合法（只能包含小写字母、数字、连字符，且必须以字母开头）',
            'slug_reserved'      => 'slug 是系统保留路径，请换一个',
            'slug_taken'         => 'slug 已被占用，请换一个',
            'visibility_invalid' => '可见性参数不合法',
            'password_required'  => '可见性为「密码」时必须设置密码',
            'not_found'          => '相册不存在',
            'db_error'           => '数据库操作失败',
            default              => '操作失败',
        };
    }
}
