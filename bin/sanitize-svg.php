#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 批量清洗历史上传的 SVG
 * ========================
 *
 * 背景：旧版本上传 SVG 时只做子串黑名单检查（`onload=` / `<script` …），
 * 攻击者可以用 `onmouseenter=`、`onload =` 之类的写法绕过，然后把带脚本的
 * SVG 存进图床。管理员直接打开该 SVG 链接时，脚本会在站点源上执行，
 * 也就是在管理员的会话里执行。
 *
 * 现在的上传流程会用 SvgSanitizer 白名单净化，但**已经躺在磁盘上的旧文件
 * 不会被追溯修改**。这个脚本就是干这个的：把 storage 目录里所有 .svg 重新
 * 净化一遍。
 *
 * 用法：
 *   php bin/sanitize-svg.php            # 干跑，只报告不动文件（默认）
 *   php bin/sanitize-svg.php --apply    # 真的改写文件
 *   php bin/sanitize-svg.php --apply --verbose
 *   php bin/sanitize-svg.php --apply /path/to/dir   # 指定要扫的目录
 *
 * 改写文件后，images 表里对应的 hash / size 会同步更新 —— 否则重复上传
 * 同一个文件会被当成新图片。
 *
 * 注意：净化无法还原被攻击者「故意」放进 SVG 的内容（比如一个看起来像
 * 按钮的恶意链接）。如果实例曾经开着「开放注册 / 邀请制」，建议同时把
 * 已上传的 SVG 全部重新审视一遍，必要时直接删掉可疑文件。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

use LitePic\Repository\ImageRepository;
use LitePic\Service\Image\PathService;
use LitePic\Service\Upload\SvgSanitizer;

$apply = in_array('--apply', $argv, true);
$verbose = in_array('--verbose', $argv, true);

$paths = [];
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '' || $arg[0] === '-') continue;
    $paths[] = $arg;
}

$root = $paths[0] ?? (string)UPLOAD_PATH_LOCAL;
$root = rtrim($root, DIRECTORY_SEPARATOR);
if (!is_dir($root)) {
    fwrite(STDERR, "存储目录不存在：{$root}\n");
    exit(1);
}

echo "LitePic SVG 批量清洗\n";
echo "存储目录：{$root}\n";
echo '模式：' . ($apply ? '写入（--apply）' : '干跑（加 --apply 才会改文件）') . "\n\n";

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$total = 0;
$changed = 0;
$clean = 0;
$broken = 0;
$skipped = 0;

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo) continue;
    if (!$file->isFile()) continue;

    $path = $file->getPathname();
    $ext = strtolower($file->getExtension());
    $isSvgName = ($ext === 'svg');

    if (!$isSvgName) {
        // 历史投毒文件可能顶着别的扩展名（把 SVG 改名成 .png 上传就能整个
        // 绕开旧的黑名单）。所以这里不只看扩展名，还要看内容 —— 先便宜地
        // 嗅探一下文件头，命中才做完整解析。
        if (!headLooksLikeSvg($path)) {
            $skipped++;
            continue;
        }
    }

    $total++;

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $broken++;
        echo "[读取失败] {$path}\n";
        continue;
    }

    $sanitized = SvgSanitizer::sanitize($raw);
    if ($sanitized === null) {
        // 扩展名是 .svg 却不是合法 SVG —— 净化器拒绝它。留着不动，交人工看。
        if ($isSvgName) {
            $broken++;
            echo "[无法解析，需人工处理] {$path}\n";
        } else {
            $skipped++;
        }
        continue;
    }

    if ($sanitized === $raw) {
        $clean++;
        if ($verbose) {
            echo "[无需处理] {$path}\n";
        }
        continue;
    }

    $changed++;
    echo "[已净化] {$path}\n";

    if (!$apply) continue;

    if (@file_put_contents($path, $sanitized) === false) {
        fwrite(STDERR, "  !! 写入失败：{$path}\n");
        continue;
    }

    // 同步 DB —— 文件字节变了，hash 和 size 也就变了。
    $identifier = PathService::identifierFromPath($path);
    if ($identifier !== null) {
        try {
            $repo = new ImageRepository();
            $hash = @sha1_file($path);
            $repo->update($identifier, [
                'hash' => is_string($hash) ? $hash : '',
                'size' => (int)@filesize($path),
            ]);
            if ($verbose) {
                echo "  → DB 已更新：{$identifier}\n";
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "  !! DB 更新失败：{$identifier} — {$e->getMessage()}\n");
        }
    } else {
        echo "  → 路径不在存储目录内，DB 未更新\n";
    }
}

echo "\n";
echo "扫描文件：{$total}\n";
echo "已净化：{$changed}\n";
echo "无需处理：{$clean}\n";
echo "需人工处理：{$broken}\n";
echo "非 SVG 跳过：{$skipped}\n";

if (!$apply && $changed > 0) {
    echo "\n以上是干跑结果。确认无误后加 --apply 重新执行以写入。\n";
}

if ($broken > 0) {
    echo "\n提示：「需人工处理」的文件没有被修改，建议逐个检查是否需要删除。\n";
}

exit(0);

/**
 * Cheap sniff — does the file head look like an SVG?
 */
function headLooksLikeSvg(string $path): bool
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) return false;
    $head = (string)fread($fh, 4096);
    fclose($fh);
    if (trim($head) === '') return false;
    $head = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $head) ?? $head);
    return stripos($head, '<svg') !== false;
}
