<?php
declare(strict_types=1);

namespace LitePic\Service\Telegram;

use LitePic\Core\Config;
use LitePic\Core\Logger;
use LitePic\Repository\AlbumRepository;
use LitePic\Repository\ImageRepository;
use LitePic\Repository\TelegramSeenUpdateRepository;
use LitePic\Repository\TelegramUserStateRepository;
use LitePic\Service\Album\AlbumService;
use LitePic\Service\Image\ImageInfo;
use LitePic\Service\Image\ImageUrl;
use LitePic\Service\Upload\UploadService;

/**
 * Receives a single Telegram `Update` object (decoded JSON), figures out
 * what the user wants, and replies via {@see TelegramApi}.
 *
 * Supported flows (MVP):
 *   - photo / image-document        → upload to LitePic, reply with public URL
 *   - /start, /help                 → usage
 *   - /me                           → show your user_id + current default album
 *   - /list [N]                     → recent N images (N=1..20, default 5)
 *   - /albums                       → list all admin albums + counts + URLs
 *   - /newalbum <name>              → create a no-slug album, reply with URL
 *   - /album <key>                  → show one album's stats + URL
 *   - /use <key> | /use none        → set default destination album
 *
 * Authentication model:
 *   The webhook entrypoint already verified the URL secret. Here we check
 *   the user's Telegram `from.id` against the comma-separated allowlist
 *   in `TELEGRAM_ALLOWED_USER_IDS`. Anyone not on the list gets a polite
 *   "this bot isn't open to you" reply that includes their numeric id so
 *   they can ask the admin to be added.
 *
 * No exceptions escape — every code path either replies (success or
 * formatted error) or quietly logs and moves on. Telegram's webhook
 * spec wants a 200 even on failure, otherwise it retries.
 */
final class TelegramHandler
{
    private TelegramApi $api;
    private TelegramUserStateRepository $state;
    private TelegramSeenUpdateRepository $seen;

    public function __construct(
        ?TelegramApi $api = null,
        ?TelegramUserStateRepository $state = null,
        ?TelegramSeenUpdateRepository $seen = null
    ) {
        $this->api   = $api   ?? new TelegramApi();
        $this->state = $state ?? new TelegramUserStateRepository();
        $this->seen  = $seen  ?? new TelegramSeenUpdateRepository();
    }

    /**
     * Entry point — called by api/telegram.php with the decoded update.
     *
     * @param array<string,mixed> $update
     */
    public function handle(array $update): void
    {
        // Idempotency gate — Telegram retries non-2xx for ~24h, so an
        // ACK delivery failure causes the same update_id to come back.
        // /newalbum, /use, /album-attach etc. all mutate DB state; without
        // dedupe a retry creates a second album with the same name. Photo
        // uploads already have SHA1 dedupe in UploadService.
        $updateId = isset($update['update_id']) ? (int)$update['update_id'] : 0;
        if ($updateId > 0 && !$this->seen->markSeen($updateId)) {
            Logger::info('Telegram webhook: duplicate update_id ignored', ['id' => $updateId]);
            return;
        }

        // We only listen for `message` updates (configured in setWebhook).
        // edited_message / channel_post / inline_query etc. aren't supported.
        $message = $update['message'] ?? null;
        if (!is_array($message)) return;

        $from = $message['from'] ?? null;
        if (!is_array($from) || !isset($from['id'])) return;
        $userId  = (int)$from['id'];
        $chatId  = (int)($message['chat']['id'] ?? $userId);
        $username = (string)($from['username'] ?? $from['first_name'] ?? 'unknown');

        // ---- gate on allowlist ----
        if (!self::isUserAllowed($userId)) {
            $this->api->sendMessage($chatId,
                "🔒 此机器人未对您开放。\n\n"
                . "如果您是站点管理员,请把以下 user_id 添加到 LitePic\n"
                . "<b>设置 → Telegram → 允许访问的用户 ID</b> 后再试。\n\n"
                . "您的 user_id: <code>" . $userId . "</code>"
            );
            return;
        }

        // Bookkeeping — lets the settings UI eventually show "last seen".
        $this->state->touch($userId);

        // ---- photo / document upload ----
        // Photos arrive as `photo: [<thumb>, …, <largest>]`. Documents (when
        // user sends "as file") arrive as `document` with a mime_type. We
        // accept either as long as the MIME indicates an image.
        if (isset($message['photo']) && is_array($message['photo'])) {
            $this->handlePhoto($chatId, $message);
            return;
        }
        if (isset($message['document']) && is_array($message['document'])) {
            $this->handleDocument($chatId, $message);
            return;
        }

        // ---- text commands ----
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '') {
            $this->api->sendMessage($chatId, '收到一条空消息 — 试试 /help 看看支持哪些指令。');
            return;
        }
        $this->dispatchCommand($chatId, $userId, $username, $text);
    }

    // ==================== photo / document ====================

    /**
     * @param array<string,mixed> $message
     */
    private function handlePhoto(int $chatId, array $message): void
    {
        $photos = $message['photo'];
        // Telegram returns multiple sizes — last one is the largest (highest res).
        $largest = end($photos);
        if (!is_array($largest) || !isset($largest['file_id'])) {
            $this->api->sendMessage($chatId, '⚠️ 无法读取照片信息,请重新发送。');
            return;
        }
        $caption = (string)($message['caption'] ?? '');
        $this->ingestFile($chatId, (int)$message['from']['id'],
            (string)$largest['file_id'],
            self::guessFilename($caption, '.jpg'));
    }

    /**
     * @param array<string,mixed> $message
     */
    private function handleDocument(int $chatId, array $message): void
    {
        $doc = $message['document'];
        $mime = (string)($doc['mime_type'] ?? '');
        if (!str_starts_with(strtolower($mime), 'image/')) {
            $this->api->sendMessage($chatId,
                '⚠️ 只支持图片文件,这个文档的类型是 <code>'
                . TelegramApi::escapeHtml($mime !== '' ? $mime : '未知')
                . '</code>。'
            );
            return;
        }
        if (!isset($doc['file_id'])) {
            $this->api->sendMessage($chatId, '⚠️ 无法读取文件信息,请重新发送。');
            return;
        }
        // Documents include the original file_name — preserve it for hash dedupe metadata.
        $name = (string)($doc['file_name'] ?? self::guessFilename('', '.bin'));
        $this->ingestFile($chatId, (int)$message['from']['id'], (string)$doc['file_id'], $name);
    }

    /**
     * Download from Telegram → write to a temp file → run through
     * UploadService server-side ingest path → optionally attach to the
     * user's default album → reply with public URL.
     */
    private function ingestFile(int $chatId, int $userId, string $fileId, string $originalName): void
    {
        // Step 1: resolve file_id → CDN file_path (valid ~1 hour)
        $info = $this->api->getFile($fileId);
        if ($info === null || empty($info['file_path'])) {
            $this->api->sendMessage($chatId, '⚠️ 无法从 Telegram 取回文件,请稍后重试。');
            return;
        }
        $remotePath = (string)$info['file_path'];

        // Step 2: stream to a temp file. We use sys_get_temp_dir() — Telegram
        // uploads from PHP-FPM go to the OS temp dir which is writeable
        // even on hardened BT panel hosts. Storage move happens in step 3.
        $tmp = tempnam(sys_get_temp_dir(), 'litepic_tg_');
        if ($tmp === false) {
            $this->api->sendMessage($chatId, '⚠️ 服务器临时目录不可写,请联系管理员。');
            return;
        }

        try {
            // downloadFile has its own size cap (MAX_FILE_SIZE) — that means
            // a 2GB "image/png" Document from an allow-listed attacker can no
            // longer fill /tmp before the MIME sniff in step 3 even fires.
            if (!$this->api->downloadFile($remotePath, $tmp)) {
                $this->api->sendMessage($chatId, '⚠️ 下载文件失败(可能超出大小限制),请稍后再试。');
                return;
            }

            // Step 3: hand off to LitePic's storage pipeline. We pass the
            // tmp-dir as the only allowed source prefix — storeFromPath
            // refuses anything outside it (symlinks too), so even if a
            // future caller passed an attacker-controlled path here, the
            // ingest stays sandboxed.
            $tmpDir = realpath(sys_get_temp_dir());
            $allowedPrefixes = $tmpDir !== false ? [$tmpDir] : [];

            $upload = new UploadService();
            $result = $upload->storeFromPath($tmp, $originalName, $allowedPrefixes);

            $status = (string)($result['status'] ?? 'error');
            if ($status === 'error') {
                $this->api->sendMessage($chatId,
                    '❌ 上传失败:' . TelegramApi::escapeHtml((string)($result['message'] ?? '未知错误'))
                );
                return;
            }

            $filename = (string)($result['filename'] ?? '');
            $publicUrl = $this->absoluteUrl((string)($result['url'] ?? ''));

            // Step 4: optional album attach. Per-user > global default.
            $albumKey = $this->resolveDestinationAlbumKey($userId);
            $albumNote = '';
            if ($albumKey !== null && $filename !== '') {
                $album = (new AlbumRepository())->findByKey($albumKey);
                if ($album !== null) {
                    $svc = new AlbumService();
                    // addImages is idempotent on duplicates → safe to call.
                    $svc->addImages((int)$album['id'], [$filename]);
                    $albumNote = "\n📁 已加入相册 <b>" . TelegramApi::escapeHtml((string)$album['name']) . "</b>";
                }
            }

            $duplicateNote = $status === 'duplicate' ? "\nℹ️ 这张图之前已上传过,这次没重复存储。" : '';

            $this->api->sendMessage($chatId,
                ($status === 'duplicate' ? '✅ 已找到这张图' : '✅ 上传成功')
                . "\n🔗 <a href=\"" . TelegramApi::escapeHtml($publicUrl) . '">'
                . TelegramApi::escapeHtml($publicUrl) . '</a>'
                . $albumNote
                . $duplicateNote,
                ['disable_web_page_preview' => false]
            );
        } finally {
            // Always clean up tmp on every exit path — success, error,
            // or any throw inside storeFromPath / Imagick processing.
            // storeFromPath consumes the file via rename() on success, so
            // is_file() guards against an "already moved" double-delete.
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    // ==================== command dispatch ====================

    private function dispatchCommand(int $chatId, int $userId, string $username, string $text): void
    {
        // Telegram commands look like `/cmd@BotName arg1 arg2`. Strip `@BotName`
        // so /list@MyBot 5 still parses.
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
        $cmd = strtolower((string)$parts[0]);
        if (str_contains($cmd, '@')) $cmd = (string)strtok($cmd, '@');
        $args = array_slice($parts, 1);

        switch ($cmd) {
            case '/start':
            case '/help':
                $this->cmdHelp($chatId);
                return;
            case '/me':
                $this->cmdMe($chatId, $userId, $username);
                return;
            case '/list':
                $this->cmdList($chatId, $args);
                return;
            case '/albums':
                $this->cmdAlbums($chatId);
                return;
            case '/newalbum':
                $this->cmdNewAlbum($chatId, $args);
                return;
            case '/album':
                $this->cmdAlbum($chatId, $args);
                return;
            case '/use':
                $this->cmdUse($chatId, $userId, $args);
                return;
            default:
                if (str_starts_with($cmd, '/')) {
                    $this->api->sendMessage($chatId,
                        '🤔 未知指令 <code>' . TelegramApi::escapeHtml($cmd) . '</code>。发 /help 看支持列表。'
                    );
                    return;
                }
                // Plain text without command — show a helpful nudge.
                $this->api->sendMessage($chatId,
                    '直接发图片就会上传 📸,或者用 /help 看其它指令。'
                );
        }
    }

    private function cmdHelp(int $chatId): void
    {
        $this->api->sendMessage($chatId,
            "<b>LitePic 机器人</b>\n\n"
            . "<b>📸 上传</b>\n"
            . "直接发送图片(或图片文档)即可上传,机器人会回复公开链接。\n\n"
            . "<b>📋 指令</b>\n"
            . "/list [N]    最近 N 张图片(默认 5,最多 20)\n"
            . "/albums      列出所有相册\n"
            . "/album &lt;key&gt; 查看某个相册详情(key 是数字 ID 或 slug)\n"
            . "/newalbum &lt;名称&gt;  新建相册\n"
            . "/use &lt;key&gt;       上传时默认归入该相册\n"
            . "/use none    清除默认相册\n"
            . "/me          查看自己的 user_id 与当前默认相册\n"
            . "/help        显示此帮助"
        );
    }

    private function cmdMe(int $chatId, int $userId, string $username): void
    {
        $st = $this->state->find($userId);
        $albumLine = '尚未设置(/use &lt;key&gt; 可设置)';
        if ($st !== null && $st['default_album_key'] !== null) {
            $album = (new AlbumRepository())->findByKey($st['default_album_key']);
            if ($album !== null) {
                $albumLine = '<b>' . TelegramApi::escapeHtml((string)$album['name']) . '</b> '
                    . '(<code>/a/' . TelegramApi::escapeHtml(AlbumService::urlKey($album)) . '</code>)';
            } else {
                // Album was deleted out from under us — clear the stale ref.
                $this->state->setDefaultAlbumKey($userId, null);
                $albumLine = '原默认相册已删除,已重置';
            }
        }
        $this->api->sendMessage($chatId,
            '<b>用户名</b>: ' . TelegramApi::escapeHtml($username)
            . "\n<b>user_id</b>: <code>" . $userId . '</code>'
            . "\n<b>默认上传相册</b>: " . $albumLine
        );
    }

    private function cmdList(int $chatId, array $args): void
    {
        $n = isset($args[0]) && ctype_digit((string)$args[0]) ? (int)$args[0] : 5;
        $n = max(1, min(20, $n));
        $repo = new ImageRepository();
        $info = new ImageInfo($repo);
        $ids = $repo->listIdentifiersSafe();
        // Already sorted newest-first by listIdentifiersSafe (matches gallery).
        $rows = [];
        foreach ($ids as $id) {
            if (count($rows) >= $n) break;
            $meta = $info->getSafe((string)$id);
            if ($meta === null) continue;
            $rows[] = [
                'id'  => (string)$id,
                'url' => $this->absoluteUrl(ImageUrl::forIdentifier((string)$id)),
                'sz'  => (int)($meta['size'] ?? 0),
            ];
        }
        if ($rows === []) {
            $this->api->sendMessage($chatId, '📭 图库还是空的 — 发张图给我开张吧!');
            return;
        }
        $text = "<b>最近 " . count($rows) . " 张图</b>\n\n";
        foreach ($rows as $i => $r) {
            $text .= ($i + 1) . '. <a href="' . TelegramApi::escapeHtml($r['url']) . '">'
                . TelegramApi::escapeHtml($r['url']) . "</a>\n";
        }
        $this->api->sendMessage($chatId, $text);
    }

    private function cmdAlbums(int $chatId): void
    {
        $albums = (new AlbumRepository())->all();
        if ($albums === []) {
            $this->api->sendMessage($chatId,
                '📁 还没有相册。\n用 /newalbum &lt;名称&gt; 创建第一个吧。'
            );
            return;
        }
        $base = $this->siteBaseUrl();
        $text = '<b>共 ' . count($albums) . " 个相册</b>\n\n";
        foreach ($albums as $a) {
            $key = AlbumService::urlKey($a);
            $text .= '• <b>' . TelegramApi::escapeHtml((string)$a['name']) . '</b> ('
                . (int)$a['image_count'] . ' 张) — '
                . '<a href="' . TelegramApi::escapeHtml($base . '/a/' . $key) . '">/a/'
                . TelegramApi::escapeHtml($key) . "</a>\n";
        }
        $this->api->sendMessage($chatId, $text);
    }

    private function cmdNewAlbum(int $chatId, array $args): void
    {
        $name = trim(implode(' ', $args));
        if ($name === '') {
            $this->api->sendMessage($chatId, '⚠️ 用法: /newalbum &lt;相册名称&gt;');
            return;
        }
        if (mb_strlen($name) > 80) {
            $this->api->sendMessage($chatId, '⚠️ 相册名称太长(最多 80 字)');
            return;
        }
        $svc = new AlbumService();
        // No slug → URL becomes /a/<id>, matching the new "default = numeric"
        // contract from the slug-nullable migration.
        $result = $svc->create(['name' => $name, 'visibility' => 'public']);
        if (is_string($result)) {
            $this->api->sendMessage($chatId, '❌ 创建失败:' . TelegramApi::escapeHtml($result));
            return;
        }
        $album = (new AlbumRepository())->find($result);
        if ($album === null) {
            $this->api->sendMessage($chatId, '❌ 创建后无法读回,请到设置页查看');
            return;
        }
        $key = AlbumService::urlKey($album);
        $url = $this->siteBaseUrl() . '/a/' . $key;
        $this->api->sendMessage($chatId,
            '✅ 已创建相册 <b>' . TelegramApi::escapeHtml((string)$album['name']) . '</b>'
            . "\n🔗 <a href=\"" . TelegramApi::escapeHtml($url) . '">' . TelegramApi::escapeHtml($url) . '</a>'
            . "\n💡 用 /use " . TelegramApi::escapeHtml($key) . ' 把后续上传归入此相册'
        );
    }

    private function cmdAlbum(int $chatId, array $args): void
    {
        $key = trim((string)($args[0] ?? ''));
        if ($key === '') {
            $this->api->sendMessage($chatId, '⚠️ 用法: /album &lt;数字 ID 或 slug&gt;');
            return;
        }
        $album = (new AlbumRepository())->findByKey($key);
        if ($album === null) {
            $this->api->sendMessage($chatId, '🤷 找不到这个相册');
            return;
        }
        $url = $this->siteBaseUrl() . '/a/' . AlbumService::urlKey($album);
        $visMap = [
            'public' => '公开', 'unlisted' => '不公开', 'password' => '密码保护', 'private' => '仅自己',
        ];
        $vis = $visMap[(string)$album['visibility']] ?? (string)$album['visibility'];
        $this->api->sendMessage($chatId,
            '<b>' . TelegramApi::escapeHtml((string)$album['name']) . '</b>'
            . "\n图片数: " . (int)$album['image_count']
            . "\n访问数: " . (int)$album['view_count']
            . "\n可见性: " . TelegramApi::escapeHtml($vis)
            . "\n🔗 <a href=\"" . TelegramApi::escapeHtml($url) . '">' . TelegramApi::escapeHtml($url) . '</a>'
        );
    }

    private function cmdUse(int $chatId, int $userId, array $args): void
    {
        $key = trim((string)($args[0] ?? ''));
        if ($key === '') {
            $this->api->sendMessage($chatId, '⚠️ 用法: /use &lt;数字 ID 或 slug&gt; ,清除请用 /use none');
            return;
        }
        if (strtolower($key) === 'none' || $key === 'off' || $key === '0') {
            $this->state->setDefaultAlbumKey($userId, null);
            $this->api->sendMessage($chatId, '✅ 已清除默认相册,后续上传只入主图库');
            return;
        }
        $album = (new AlbumRepository())->findByKey($key);
        if ($album === null) {
            $this->api->sendMessage($chatId, '🤷 找不到相册 <code>' . TelegramApi::escapeHtml($key) . '</code>');
            return;
        }
        // Re-canonicalise: the user might have typed "/a/3" but we always
        // store the bare key (slug-or-id) — that's what AlbumService::urlKey
        // returns.
        $canonicalKey = AlbumService::urlKey($album);
        $this->state->setDefaultAlbumKey($userId, $canonicalKey);
        $this->api->sendMessage($chatId,
            '✅ 默认相册已设为 <b>' . TelegramApi::escapeHtml((string)$album['name']) . '</b>'
            . "\n后续上传都会自动归入这里。/use none 可取消。"
        );
    }

    // ==================== helpers ====================

    /**
     * Whitelist check — TELEGRAM_ALLOWED_USER_IDS is comma-separated
     * numeric ids, e.g. `12345,67890`. Empty list = nobody allowed.
     */
    public static function isUserAllowed(int $userId): bool
    {
        $raw = trim((string)Config::get('TELEGRAM_ALLOWED_USER_IDS', ''));
        if ($raw === '') return false;
        foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $candidate) {
            if (ctype_digit((string)$candidate) && (int)$candidate === $userId) return true;
        }
        return false;
    }

    /**
     * Determine the album key to attach uploads to. Per-user overrides
     * the global TELEGRAM_DEFAULT_ALBUM_KEY (if set).
     *
     * Returns null = "no album, just put in main gallery".
     */
    private function resolveDestinationAlbumKey(int $userId): ?string
    {
        $st = $this->state->find($userId);
        if ($st !== null && $st['default_album_key'] !== null) {
            return $st['default_album_key'];
        }
        $global = trim((string)Config::get('TELEGRAM_DEFAULT_ALBUM_KEY', ''));
        return $global !== '' ? $global : null;
    }

    /**
     * Build a fully-qualified URL for the configured site base. Telegram
     * shows clickable links from these and bare paths (`/uploads/foo.jpg`)
     * obviously don't click. Falls back to scheme://host of the current
     * request when SITE_URL isn't set.
     */
    private function absoluteUrl(string $relativeOrAbsolute): string
    {
        if ($relativeOrAbsolute === '') return '';
        if (preg_match('#^https?://#i', $relativeOrAbsolute) === 1) return $relativeOrAbsolute;
        return rtrim($this->siteBaseUrl(), '/') . '/' . ltrim($relativeOrAbsolute, '/');
    }

    private function siteBaseUrl(): string
    {
        $configured = trim((string)Config::get('SITE_URL', ''));
        if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
            return rtrim($configured, '/');
        }
        // Fallback — derive from the current webhook request.
        $scheme = (!empty($_SERVER['HTTPS']) && (string)$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }

    /**
     * Telegram doesn't always send a filename. Best-effort: derive from
     * caption (if it looks like a name) or fall back to a timestamp.
     *
     * Sanitisation:
     *   - basename() strips any directory components ("../" or "/etc/x.jpg")
     *   - whitespace + control chars + path separators removed
     *   - max length 120 chars (matches the column constraint downstream)
     *
     * The on-disk filename is always generated by PathService::generateFilename
     * regardless of what we return here, so the only attack surface is the
     * "original_name" column which is later rendered in the admin UI. The
     * UI does its own htmlspecialchars, but defense-in-depth: don't ship
     * `<script>` strings through into a DB column even if they're escaped
     * everywhere they're displayed today.
     */
    private static function guessFilename(string $caption, string $defaultExt): string
    {
        $caption = trim($caption);
        if ($caption === '' || !preg_match('/\.[a-z0-9]{1,5}$/i', $caption)) {
            return 'tg_' . date('Ymd_His') . $defaultExt;
        }
        // Strip directory portion, then any control / path-separator / shell
        // metacharacter that has no business in a stored filename. We keep
        // unicode (Chinese filenames are common) but drop anything below
        // 0x20 and the bracket family that's never a real filename.
        $name = basename($caption);
        $name = preg_replace('/[\x00-\x1f\x7f\\\\\/<>:"|?*]+/u', '', $name) ?? '';
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'tg_' . date('Ymd_His') . $defaultExt;
        }
        // Cap length while preserving the extension — mb_strlen handles
        // multi-byte names safely.
        if (mb_strlen($name) > 120) {
            $ext = (string)pathinfo($name, PATHINFO_EXTENSION);
            $stem = mb_substr($name, 0, 120 - mb_strlen($ext) - 1);
            $name = $stem . '.' . $ext;
        }
        return $name;
    }
}
