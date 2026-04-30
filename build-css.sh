#!/bin/bash
# Tailwind CSS v4 build script

set -e

echo "Building Tailwind CSS..."

npx @tailwindcss/cli -i assets/css/tailwind.css -o assets/css/main.css --minify
cp assets/css/main.css assets/css/main.min.css

SIZE=$(wc -c < assets/css/main.css)
MIN_SIZE=$(wc -c < assets/css/main.min.css)
echo "Build complete: assets/css/main.css ($SIZE bytes), assets/css/main.min.css ($MIN_SIZE bytes)"
