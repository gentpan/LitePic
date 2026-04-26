#!/bin/bash
# CSS 构建脚本：合并模块并压缩

set -e

MODULES_DIR="assets/css/modules"
OUTPUT="assets/css/main.min.css"
TEMP="/tmp/litepic_css_build.css"

echo "Building CSS..."

# 按顺序合并所有模块
cat \
  "$MODULES_DIR/base.css" \
  "$MODULES_DIR/components.css" \
  "$MODULES_DIR/upload.css" \
  "$MODULES_DIR/recent.css" \
  "$MODULES_DIR/layout.css" \
  "$MODULES_DIR/gallery.css" \
  "$MODULES_DIR/settings.css" \
  "$MODULES_DIR/stats.css" \
  "$MODULES_DIR/docs.css" \
  "$MODULES_DIR/viewimage.css" \
  > "$TEMP"

# 使用 lightningcss 压缩（如果可用），否则使用 clean-css
if command -v lightningcss &> /dev/null; then
  lightningcss --minify "$TEMP" -o "$OUTPUT"
elif command -v npx &> /dev/null; then
  npx clean-css-cli "$TEMP" -o "$OUTPUT"
else
  echo "Warning: no CSS minifier found, copying raw concatenated file"
  cp "$TEMP" "$OUTPUT"
fi

rm -f "$TEMP"

SIZE=$(wc -c < "$OUTPUT")
echo "Build complete: $OUTPUT ($SIZE bytes)"
