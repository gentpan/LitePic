<?php
declare(strict_types=1);

namespace LitePic\Http\Controllers;

use LitePic\Core\Csrf;
use LitePic\Core\Response;
use LitePic\Service\Auth\AuthService;
use LitePic\Service\Image\ImageUrl;
use LitePic\Service\Image\PathService;
use LitePic\Service\Image\ThumbnailService;
use LitePic\Service\WebDav\DavNode;
use LitePic\Service\WebDav\DavPath;
use LitePic\Service\WebDav\DavTree;
use LitePic\Service\WebDav\DavWriter;

/**
 * Admin file browser — the WebDAV tree, rendered in the browser.
 *
 * Deliberately the *same* tree the protocol serves: it resolves paths through
 * {@see DavTree} and mutates through {@see DavWriter}, so a folder renamed here
 * is a folder renamed in Finder, and the naming, ordering and reconciliation
 * rules cannot drift between the two surfaces.
 *
 * Two things this does NOT share with `/dav`:
 *
 *   *Auth.* This is an admin page behind the admin cookie plus CSRF, not Basic
 *   Auth. `WEBDAV_ENABLED` gates whether the protocol endpoint answers the
 *   outside world; it has no say over whether an authenticated admin may browse
 *   their own library. Likewise `WEBDAV_READONLY`, which exists to restrain
 *   clients — an admin can already delete anything from the gallery page.
 *
 *   *Locks.* Class-2 locks coordinate concurrent WebDAV clients. Honouring them
 *   here would let a Finder window that forgot to unlock wedge the admin UI,
 *   with no way to break the lock from inside the browser.
 *
 * Endpoint map (action passed in $action):
 *   list     GET  /api/v1/files?path=/album&offset=0&limit=200
 *   move     POST /api/v1/files  form_action=move    {paths[], to}
 *   copy     POST /api/v1/files  form_action=copy    {paths[], to}
 *   delete   POST /api/v1/files  form_action=delete  {paths[]}
 *   rename   POST /api/v1/files  form_action=rename  {path, name}
 *   folder   POST /api/v1/files  form_action=folder  {name}
 */
final class FileBrowserController
{
    /** Entries returned in one `list` response. */
    private const DEFAULT_LIMIT = 200;
    private const MAX_LIMIT = 500;

    private DavTree $tree;
    private DavWriter $writer;
    private AuthService $auth;

    public function __construct(?DavTree $tree = null, ?DavWriter $writer = null, ?AuthService $auth = null)
    {
        $this->tree = $tree ?? new DavTree();
        $this->writer = $writer ?? new DavWriter($this->tree);
        $this->auth = $auth ?? new AuthService();
    }

    public function dispatch(string $action): void
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (!$this->auth->isAdmin()) {
            Response::error('权限不足', 403);
            return;
        }

        if ($method === 'GET') {
            if ($action !== 'list') {
                Response::error('未知的文件操作', 400);
                return;
            }
            $this->handleList();
            return;
        }

        if ($method !== 'POST') {
            Response::error('仅支持 GET / POST 请求', 405);
            return;
        }

        if (!Csrf::verify(Csrf::requestToken())) {
            Response::error('CSRF 令牌无效或已过期', 403);
            return;
        }

        switch ($action) {
            case 'move':   $this->handleTransfer(false); break;
            case 'copy':   $this->handleTransfer(true); break;
            case 'delete': $this->handleDelete(); break;
            case 'rename': $this->handleRename(); break;
            case 'folder': $this->handleFolder(); break;
            default:       Response::error('未知的文件操作', 400);
        }
    }

    // ==================== READ ====================

    private function handleList(): void
    {
        $path = $this->cleanPath((string)($_GET['path'] ?? '/'));
        if ($path === null) {
            Response::error('路径不合法', 400);
            return;
        }

        $node = $this->tree->resolve($path);
        if ($node === null) {
            Response::error('目录不存在', 404);
            return;
        }
        if (!$node->isCollection) {
            Response::error('该路径是文件，不是目录', 400);
            return;
        }

        $children = $this->tree->children($node);
        $total = count($children);

        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = (int)($_GET['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $window = array_slice($children, $offset, $limit);

        Response::success([
            'path'       => $path,
            'node'       => $this->presentNode($node),
            'breadcrumb' => $this->breadcrumb($path),
            'entries'    => array_map([$this, 'presentEntry'], $window),
            'total'      => $total,
            'offset'     => $offset,
            'has_more'   => ($offset + count($window)) < $total,
        ]);
    }

    // ==================== WRITES ====================

    /**
     * MOVE or COPY a batch of paths into one destination collection. Each item
     * keeps its own name; the destination supplies the parent. Partial success
     * is normal for a multi-select drag, so failures come back per path rather
     * than aborting the batch.
     */
    private function handleTransfer(bool $isCopy): void
    {
        $payload = $this->jsonOrPost();

        $to = $this->cleanPath((string)($payload['to'] ?? ''));
        if ($to === null) {
            Response::error('目标路径不合法', 400);
            return;
        }
        $target = $this->tree->resolve($to);
        if ($target === null || !$target->isCollection) {
            Response::error('目标目录不存在', 404);
            return;
        }
        if (!$target->writable) {
            Response::error('「' . DavPath::DIR_DATE . '」是只读视图，不能作为目标', 403);
            return;
        }

        $paths = $this->collectPaths($payload);
        if ($paths === []) {
            Response::error('没有选中任何项目', 400);
            return;
        }

        $done = 0;
        $failed = [];
        foreach ($paths as $path) {
            $source = $this->tree->resolve($path);
            if ($source === null) {
                $failed[] = ['path' => $path, 'message' => '项目已不存在'];
                continue;
            }

            $destination = DavPath::join($to, $source->name);
            $result = $isCopy
                ? $this->writer->copy($source, $destination, false, 1)
                : $this->writer->move($source, $destination, false);

            if ($result['code'] < 300) {
                $done++;
            } else {
                $failed[] = [
                    'path'    => $path,
                    'message' => $source->name . '：' . $this->codeMessage((int)$result['code'], $isCopy),
                ];
            }
        }

        Response::success([
            'moved'  => $isCopy ? 0 : $done,
            'copied' => $isCopy ? $done : 0,
            'done'   => $done,
            'failed' => $failed,
        ]);
    }

    private function handleDelete(): void
    {
        $payload = $this->jsonOrPost();
        $paths = $this->collectPaths($payload);
        if ($paths === []) {
            Response::error('没有选中任何项目', 400);
            return;
        }

        $done = 0;
        $failed = [];
        foreach ($paths as $path) {
            $node = $this->tree->resolve($path);
            if ($node === null) {
                // Already gone is the outcome the caller wanted.
                $done++;
                continue;
            }
            $result = $this->writer->delete($node);
            if ($result['code'] < 300) {
                $done++;
            } else {
                $failed[] = [
                    'path'    => $path,
                    'message' => $node->name . '：' . $this->codeMessage((int)$result['code'], false),
                ];
            }
        }

        Response::success(['deleted' => $done, 'failed' => $failed]);
    }

    private function handleRename(): void
    {
        $payload = $this->jsonOrPost();

        $path = $this->cleanPath((string)($payload['path'] ?? ''));
        if ($path === null || $path === '/') {
            Response::error('路径不合法', 400);
            return;
        }
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '' || !DavPath::isSafeSegment($name)) {
            Response::error('名称不合法（不能含 / \\ 控制字符，且不能以空格或点结尾）', 400);
            return;
        }

        $node = $this->tree->resolve($path);
        if ($node === null) {
            Response::error('项目不存在', 404);
            return;
        }
        if ($node->name === $name) {
            Response::success(['path' => $path, 'name' => $name]);
            return;
        }

        $destination = DavPath::join(DavPath::parent($path), $name);
        $result = $this->writer->move($node, $destination, false);
        if ($result['code'] >= 300) {
            Response::error($this->codeMessage((int)$result['code'], false), 400);
            return;
        }

        Response::success(['path' => $destination, 'name' => $name]);
    }

    private function handleFolder(): void
    {
        $payload = $this->jsonOrPost();
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '' || !DavPath::isSafeSegment($name)) {
            Response::error('名称不合法（不能含 / \\ 控制字符，且不能以空格或点结尾）', 400);
            return;
        }

        $result = $this->writer->createCollection('/' . $name);
        if ($result['code'] >= 300) {
            Response::error($this->codeMessage((int)$result['code'], false), 400);
            return;
        }

        Response::success(['path' => '/' . $name, 'name' => $name]);
    }

    // ==================== PRESENTATION ====================

    /**
     * @return array<string,mixed>
     */
    private function presentNode(DavNode $node): array
    {
        $out = [
            'kind'          => $node->kind,
            'name'          => $node->name !== '' ? $node->name : '全部',
            'path'          => $node->path,
            'is_collection' => $node->isCollection,
            'writable'      => $node->writable,
            'album_id'      => $node->albumId,
        ];

        if ($node->kind === DavNode::KIND_ALBUM && $node->album !== null) {
            $out['visibility'] = (string)($node->album['visibility'] ?? 'private');
            $out['image_count'] = (int)($node->album['image_count'] ?? 0);
            $out['slug'] = $node->album['slug'] ?? null;
            $cover = (string)($node->album['cover_effective']
                ?? $node->album['cover_filename']
                ?? '');
            $out['thumb_url'] = '';
            if ($cover !== '' && is_file(ImageUrl::thumbnailPath($cover))) {
                $out['thumb_url'] = ImageUrl::thumbnailUrl($cover);
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function presentEntry(DavNode $node): array
    {
        $entry = $this->presentNode($node);

        if ($node->isCollection) {
            return $entry;
        }

        $entry['filename'] = $node->filename;
        $entry['size'] = $node->size;
        $entry['mime'] = $node->contentType;
        $entry['created_at'] = $node->createdAt;
        $entry['placeholder'] = $node->isPlaceholder();
        $entry['entry_id'] = $node->entryId;

        $image = $node->image;
        $entry['width'] = $image !== null ? (int)($image['width'] ?? 0) : 0;
        $entry['height'] = $image !== null ? (int)($image['height'] ?? 0) : 0;
        $entry['original_name'] = $image !== null ? (string)($image['original_name'] ?? '') : '';

        $identifier = (string)($node->filename ?? '');
        if ($identifier === '') {
            $entry['url'] = '';
            $entry['thumb_url'] = '';
            $entry['missing'] = false;
            return $entry;
        }

        $entry['url'] = ImageUrl::forIdentifier($identifier);
        // 列表/预览永远走缩略图，绝不用原图兜底——日期视图曾漏查 has_thumbnail，
        // 一列几十张 4K 原图直接把浏览器拖死。缺缩略图就留空，前端回退图标。
        $hasThumb = $image !== null && (int)($image['has_thumbnail'] ?? 0) === 1;
        if (!$hasThumb && $image !== null && ThumbnailService::canGenerate($identifier)) {
            // 库标记可能落后于磁盘；文件已在则按有缩略图处理。
            $hasThumb = is_file(ImageUrl::thumbnailPath($identifier));
        }
        $entry['thumb_url'] = $hasThumb ? ImageUrl::thumbnailUrl($identifier) : '';
        $entry['missing'] = !is_file(PathService::resolveFilePath($identifier));

        return $entry;
    }

    /**
     * Ancestor chain including the path itself, root first.
     *
     * @return array<int,array<string,string>>
     */
    private function breadcrumb(string $path): array
    {
        $crumbs = [['name' => '全部', 'path' => '/']];
        $current = '';
        foreach (DavPath::segments($path) as $segment) {
            $current .= '/' . $segment;
            $crumbs[] = ['name' => $segment, 'path' => $current];
        }
        return $crumbs;
    }

    // ==================== HELPERS ====================

    /**
     * Validate a client-supplied internal path.
     *
     * Not {@see DavPath::normalise()}: that one percent-decodes each segment,
     * and these paths arrive already decoded (PHP decoded the query string, or
     * they came from a JSON body). Decoding twice would mangle any name with a
     * literal `%` in it. Segment safety is still delegated, so the rules match
     * what the protocol side enforces.
     */
    private function cleanPath(string $raw): ?string
    {
        $trimmed = trim(str_replace('\\', '/', $raw));
        $trimmed = trim($trimmed, '/');
        if ($trimmed === '') {
            return '/';
        }

        $segments = explode('/', $trimmed);
        if (count($segments) > 8) {
            return null;
        }
        foreach ($segments as $segment) {
            if (!DavPath::isSafeSegment($segment)) {
                return null;
            }
        }

        return '/' . implode('/', $segments);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function collectPaths(array $payload): array
    {
        $raw = $payload['paths'] ?? $payload['path'] ?? null;
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $clean = [];
        foreach ($raw as $candidate) {
            $path = $this->cleanPath((string)$candidate);
            if ($path === null || $path === '/' || in_array($path, $clean, true)) {
                continue;
            }
            $clean[] = $path;
        }
        return $clean;
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOrPost(): array
    {
        if (!empty($_POST)) {
            return $_POST;
        }
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Turn a WebDAV status code into something an admin can act on. The writer
     * speaks HTTP because the protocol needs it to; this surface does not.
     */
    private function codeMessage(int $code, bool $isCopy): string
    {
        switch ($code) {
            case 403:
                return $isCopy
                    ? '不允许复制到这里（只读视图、同相册内已有，或不能复制到未分类）'
                    : '不允许这样操作（只读视图，或系统目录不能改）';
            case 405:
                return '同名项目已存在';
            case 409:
                return '目标位置不可用';
            case 412:
                return '目标已存在同名项目';
            case 415:
                return '不支持的请求内容';
            default:
                return '操作失败（' . $code . '）';
        }
    }
}
