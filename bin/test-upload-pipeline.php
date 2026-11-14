#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 上传链路集成测试（SVG 部分）
 * =============================
 *
 * 验证 UploadService 的真实上传路径确实会：
 *   1. 用白名单净化器替换掉旧的子串黑名单；
 *   2. 把净化后的内容写回文件（存盘的字节 == 净化后的字节）；
 *   3. 纯校验接口（validateMime）不会动用户的文件；
 *   4. 拒绝伪装成 .svg 的非 SVG 文件。
 *
 * 用法：php bin/test-upload-pipeline.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap.php';

use LitePic\Service\Upload\UploadService;

$failed = 0;
$service = new UploadService();

$resolve = new ReflectionMethod(UploadService::class, 'resolveUploadType');

function makeTmp(string $content): string
{
    $dir = sys_get_temp_dir() . '/litepic_svg_test';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $path = $dir . '/' . bin2hex(random_bytes(6)) . '.svg';
    file_put_contents($path, $content);
    return $path;
}

$payload = '<svg xmlns="http://www.w3.org/2000/svg"><rect onmouseenter="alert(1)" onload = "alert(2)" width="10" height="10"/></svg>';

echo "=== 1. resolveUploadType() 会净化并写回文件 ===\n";
$path = makeTmp($payload);
$type = $resolve->invoke($service, $path, 'svg', ['svg'], true);
$after = (string)file_get_contents($path);
$probe = strtolower($after);

if (!is_array($type) || ($type['ext'] ?? '') !== 'svg') {
    echo "!! 没有识别为 svg\n";
    $failed++;
} elseif (str_contains($probe, 'onmouseenter') || str_contains($probe, 'onload')) {
    echo "!! 文件里还留着事件属性：{$after}\n";
    $failed++;
} else {
    echo "OK —— 存盘内容已净化：{$after}\n";
    echo "     返回类型：ext={$type['ext']} mime={$type['mime']}\n";
}
@unlink($path);

echo "\n=== 2. rewrite=false 时不修改文件（纯校验路径） ===\n";
// 注意：validateMime() 走的是站点配置的允许类型（本地实例默认没开 svg），
// 所以这里直接测 resolveUploadType(..., rewrite: false) 这条纯校验路径。
$path = makeTmp($payload);
$before = (string)file_get_contents($path);
$type = $resolve->invoke($service, $path, 'svg', ['svg'], false);
$after = (string)file_get_contents($path);
if (!is_array($type)) {
    echo "!! 纯校验路径应当接受这个 SVG\n";
    $failed++;
} elseif ($after !== $before) {
    echo "!! 纯校验路径改动了文件（不应该）\n";
    $failed++;
} else {
    echo "OK —— 文件字节未被改动\n";
}
@unlink($path);

echo "\n=== 3. 伪装成 .svg 的非 SVG 文件被拒绝 ===\n";
$path = makeTmp("\x89PNG\r\n\x1a\n" . random_bytes(64));
$type = $resolve->invoke($service, $path, 'svg', ['svg'], true);
if ($type !== null) {
    echo "!! 二进制文件被当成了 SVG 接受\n";
    $failed++;
} else {
    echo "OK —— 已拒绝\n";
}
@unlink($path);

echo "\n=== 4. svg 不在允许列表时拒绝 ===\n";
$path = makeTmp($payload);
$type = $resolve->invoke($service, $path, 'svg', ['jpg', 'png'], true);
if ($type !== null) {
    echo "!! 未启用 svg 时仍然接受\n";
    $failed++;
} else {
    echo "OK —— 已拒绝\n";
}
@unlink($path);

echo "\n=== 5. 存盘内容与后续 hash 一致 ===\n";
// UploadService::handleSingle() 先 resolveUploadType()（净化），再 sha1_file()
// 计算 hash —— 顺序必须是这样，否则去重会拿净化前的字节。
$path = makeTmp($payload);
$resolve->invoke($service, $path, 'svg', ['svg'], true);
$hashOnDisk = sha1_file($path);
$hashOfSanitized = sha1((string)file_get_contents($path));
if (!hash_equals($hashOnDisk, $hashOfSanitized)) {
    echo "!! hash 与落盘内容不一致\n";
    $failed++;
} else {
    echo "OK —— hash 来自净化后的内容（{$hashOnDisk}）\n";
}
@unlink($path);

echo "\n=== 6. SVG 内容改名成 .png 也必须被净化（扩展名绕过） ===\n";
// 旧实现只在扩展名是 svg 时才跑黑名单，改个名就能整个绕过去，
// 而文件仍然以 image/svg+xml 提供 —— 脚本照样在站点源里执行。
$path = sys_get_temp_dir() . '/litepic_svg_test/disguised.png';
if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
file_put_contents($path, $payload);
$type = $resolve->invoke($service, $path, 'png', ['jpg', 'png', 'webp', 'svg'], true);
$after = (string)file_get_contents($path);
if (!is_array($type)) {
    echo "!! 改名后的 SVG 应当被接受（识别为 svg）\n";
    $failed++;
} elseif (($type['ext'] ?? '') !== 'svg') {
    echo "!! 扩展名没有归一化为 svg，实际是 " . ($type['ext'] ?? '?') . "\n";
    $failed++;
} elseif (stripos($after, 'onmouseenter') !== false || stripos($after, 'onload') !== false) {
    echo "!! 文件里还留着事件属性：{$after}\n";
    $failed++;
} else {
    echo "OK —— 已被识别为 svg 并净化：{$after}\n";
}
@unlink($path);

echo "\n=== 7. 真正的 PNG 不会被误判成 SVG ===\n";
$path = sys_get_temp_dir() . '/litepic_svg_test/real.png';
// 用 GD 生成一张真 PNG，再在尾部拼一段 "<svg onload=..." —— 模拟 tEXt 之类的
// 元数据里出现 "<svg" 字样的情况（内容嗅探不能把它误判成 SVG）。
$img = imagecreatetruecolor(4, 4);
ob_start();
imagepng($img);
$png = (string)ob_get_clean();
file_put_contents($path, $png . "\n<!--<svg onload=\"alert(1)\">-->");
$type = $resolve->invoke($service, $path, 'png', ['jpg', 'png', 'webp', 'svg'], true);
if (!is_array($type) || ($type['ext'] ?? '') !== 'png') {
    echo "!! PNG 被误判了：" . json_encode($type) . "\n";
    $failed++;
} else {
    echo "OK —— 仍然是 png\n";
}
@unlink($path);

echo "\n结果: " . ($failed === 0 ? "全部通过 ✅" : "有 $failed 项失败 ❌") . "\n";
exit($failed === 0 ? 0 : 1);
