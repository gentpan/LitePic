#!/bin/bash
# Tailwind CSS v4 build script

set -e

echo "Building Tailwind CSS..."

npx @tailwindcss/cli -i assets/css/tailwind.css -o assets/css/main.css --minify

SIZE=$(wc -c < assets/css/main.css)
echo "Build complete: assets/css/main.css ($SIZE bytes)"
