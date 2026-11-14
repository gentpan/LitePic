<?php
declare(strict_types=1);

require __DIR__ . '/../app/Service/Upload/SvgSanitizer.php';

use LitePic\Service\Upload\SvgSanitizer;

/** 攻击载荷 —— 净化后必须不含任何可执行内容 */
$attacks = [
    'onload='            => '<svg xmlns="http://www.w3.org/2000/svg"><rect onload="alert(1)"/></svg>',
    'onload 空格变体'     => '<svg xmlns="http://www.w3.org/2000/svg"><rect onload = "alert(1)"/></svg>',
    'onmouseenter'       => '<svg xmlns="http://www.w3.org/2000/svg"><rect onmouseenter="alert(1)"/></svg>',
    'onpointerdown'      => '<svg xmlns="http://www.w3.org/2000/svg"><rect onpointerdown="alert(1)"/></svg>',
    'ontoggle'           => '<svg xmlns="http://www.w3.org/2000/svg"><rect ontoggle="alert(1)"/></svg>',
    'onanimationstart'   => '<svg xmlns="http://www.w3.org/2000/svg"><rect onanimationstart="alert(1)"/></svg>',
    'onwheel'            => '<svg xmlns="http://www.w3.org/2000/svg"><rect onwheel="alert(1)"/></svg>',
    'onpageshow'         => '<svg xmlns="http://www.w3.org/2000/svg"><rect onpageshow="alert(1)"/></svg>',
    '大写 ONLOAD'         => '<svg xmlns="http://www.w3.org/2000/svg"><rect ONLOAD="alert(1)"/></svg>',
    '<script>'           => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'namespace script'   => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:s="http://www.w3.org/2000/svg"><s:script>alert(1)</s:script></svg>',
    'xhtml script'       => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:h="http://www.w3.org/1999/xhtml"><h:script>alert(1)</h:script></svg>',
    'foreignObject'      => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><body xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></body></foreignObject></svg>',
    'javascript: href'   => '<svg xmlns="http://www.w3.org/2000/svg"><a href="javascript:alert(1)"><rect width="10" height="10"/></a></svg>',
    '实体编码 js href'    => '<svg xmlns="http://www.w3.org/2000/svg"><a href="&#106;avascript:alert(1)"><rect width="10" height="10"/></a></svg>',
    'xlink js href'      => '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><rect width="10" height="10"/></a></svg>',
    'data:text/html'     => '<svg xmlns="http://www.w3.org/2000/svg"><a href="data:text/html,<script>alert(1)</script>"><rect width="10" height="10"/></a></svg>',
    'data:image/svg'     => '<svg xmlns="http://www.w3.org/2000/svg"><image href="data:image/svg+xml;base64,PHN2Zz48c2NyaXB0PmFsZXJ0KDEpPC9zY3JpcHQ+PC9zdmc+"/></svg>',
    'style @import'      => '<svg xmlns="http://www.w3.org/2000/svg"><style>@import url("http://evil.example/x.css");</style><rect width="10" height="10"/></svg>',
    'style expression'   => '<svg xmlns="http://www.w3.org/2000/svg"><style>rect{width:expression(alert(1))}</style><rect width="10" height="10"/></svg>',
    'style attr js url'  => '<svg xmlns="http://www.w3.org/2000/svg"><rect style="background:url(javascript:alert(1))" width="10" height="10"/></svg>',
    'animate onload'     => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"><animate attributeName="onload" to="alert(1)"/></rect></svg>',
    'animate href'       => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"><animate attributeName="href" to="javascript:alert(1)"/></rect></svg>',
    'animate to js'      => '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"><animate attributeName="fill" to="javascript:alert(1)"/></rect></svg>',
    'xml-stylesheet PI'  => '<?xml-stylesheet type="text/css" href="http://evil.example/x.css"?><svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
    'use 外部引用'        => '<svg xmlns="http://www.w3.org/2000/svg"><use href="http://evil.example/x.svg#a"/></svg>',
    '相对路径 image'      => '<svg xmlns="http://www.w3.org/2000/svg"><image href="evil.svg"/></svg>',
];

echo "=== 攻击载荷 ===\n";
$failed = 0;
foreach ($attacks as $name => $payload) {
    $out = SvgSanitizer::sanitize($payload);
    if ($out === null) {
        printf("%-22s 已拒绝 (返回 null)\n", $name);
        continue;
    }
    $probe = strtolower(preg_replace('/[\s\x00-\x20\x7F]+/', '', $out) ?? $out);
    $bad = [];
    foreach (['onload=', 'onerror=', 'onmouseenter=', 'onpointerdown=', 'ontoggle=',
              'onanimationstart=', 'onwheel=', 'onpageshow=', '<script', 'javascript:',
              'vbscript:', 'data:text/html', 'data:image/svg', 'foreignobject',
              '@import', 'expression(', '-moz-binding', 'xml-stylesheet', 'evil.example'] as $needle) {
        if (str_contains($probe, $needle)) $bad[] = $needle;
    }
    if ($bad === []) {
        printf("%-22s 已净化 OK\n", $name);
    } else {
        $failed++;
        printf("%-22s !! 残留 %s\n", $name, implode(',', $bad));
        printf("      -> %s\n", trim($out));
    }
}

echo "\n=== 必须拒绝的畸形输入 ===\n";
$rejects = [
    'DOCTYPE/XXE'    => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg">&xxe;</svg>',
    'billion laughs' => '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;">]><svg xmlns="http://www.w3.org/2000/svg">&b;</svg>',
    '非 XML'          => 'not xml at all',
    '根元素非 svg'    => '<html><body>hi</body></html>',
    '空文件'          => '',
];
foreach ($rejects as $name => $payload) {
    $out = SvgSanitizer::sanitize($payload);
    printf("%-16s => %s\n", $name, $out === null ? '已拒绝' : '!! 未拒绝');
    if ($out !== null) $failed++;
}

echo "\n=== 兼容性：老工具的 SVG 1.1 DOCTYPE 必须能用 ===\n";
$illustrator = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
    . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n"
    . '<svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
$out = SvgSanitizer::sanitize($illustrator);
if ($out === null) {
    echo "!! 带外部 DTD 声明的合法 SVG 被拒绝了\n";
    $failed++;
} elseif (str_contains(strtolower($out), '<!doctype') || str_contains(strtolower($out), 'dtd svg')) {
    echo "!! DOCTYPE 没有被剥掉：{$out}\n";
    $failed++;
} elseif (!str_contains($out, '<rect')) {
    echo "!! 内容丢失：{$out}\n";
    $failed++;
} else {
    echo "OK —— DOCTYPE 已剥离，内容保留\n";
}

echo "\n=== 合法 SVG 必须保留 ===\n";
$legit = <<<'SVG'
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 100 100" width="100" height="100">
  <defs>
    <linearGradient id="grad" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#f00"/>
      <stop offset="100%" stop-color="#00f"/>
    </linearGradient>
    <symbol id="star"><path d="M0 0 L10 10 L-10 10 Z"/></symbol>
  </defs>
  <style>.cls{fill:url(#grad);stroke:#333}</style>
  <g class="cls" transform="translate(5,5)">
    <rect x="0" y="0" width="50" height="50" rx="4" fill="url(#grad)"/>
    <use xlink:href="#star" x="20" y="20"/>
    <text x="10" y="70" font-size="12" style="fill:#222">Hello 图床</text>
    <circle cx="60" cy="60" r="20" fill="none" stroke="url(#grad)" stroke-width="2"/>
  </g>
  <a href="https://example.com/"><text x="10" y="90">link</text></a>
</svg>
SVG;
$out = SvgSanitizer::sanitize($legit);
if ($out === null) {
    echo "!! 合法 SVG 被拒绝\n";
    $failed++;
} else {
    $mustHave = ['viewBox="0 0 100 100"', 'linearGradient', 'stop-color="#f00"', 'symbol',
                 'id="star"', 'xlink:href="#star"', 'href="#star"', '<style', 'url(#grad)',
                 'Hello 图床', 'href="https://example.com/"', '<use', 'x="20"'];
    $missing = [];
    foreach ($mustHave as $needle) {
        if (!str_contains($out, $needle)) $missing[] = $needle;
    }
    if ($missing === []) {
        echo "合法 SVG 完整保留 OK\n";
    } else {
        $failed++;
        echo "!! 合法 SVG 丢失: " . implode(', ', $missing) . "\n";
        echo $out . "\n";
    }
}

echo "\n=== 净化后的产物（onload 空格变体）===\n";
echo SvgSanitizer::sanitize($attacks['onload 空格变体']) . "\n";

echo "\n结果: " . ($failed === 0 ? "全部通过 ✅" : "有 $failed 项失败 ❌") . "\n";
exit($failed === 0 ? 0 : 1);
