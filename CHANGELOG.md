# Changelog

All notable changes to this project will be documented in this file.

## [3.0.0] - 2026-04-29

### Breaking Changes
- **CSS Architecture**: Migrated from custom CSS modules to Tailwind CSS v4 with CSS-first configuration. All legacy `assets/css/modules/*.css` files removed.
- **Documentation Routes**: Split `docs.php` into `usage.php` + `api.php`. Routes changed from `/docs` to `/docs` (usage) and `/api` (API docs).

### Added
- **Tailwind CSS v4** build pipeline with `@tailwindcss/cli` and component-layer CSS architecture.
- **Gallery link** in navigation bar for logged-in users (`/gallery`).
- **Custom scrollbar theming** for WebKit and Firefox, following light/dark mode.
- **Favicon suite**: apple-touch-icon, Android Chrome icons (192x192 / 512x512), favicon-16x16, favicon-32x32, and `site.webmanifest`.
- **Light/dark theme-aware logo switching** via CSS custom properties (avoids Tailwind v4 Lightning CSS optimization bugs).
- **Scrollbar gutter stabilization** (`scrollbar-gutter: stable`) to prevent layout shift.

### Changed
- **Logo**: Replaced SVG logo with PNG (`logo.png` / `logo-dark.png`) for better cross-browser rendering.
- **Footer layout**: Reorganized into three-column layout—GitHub link on the left, centered copyright/stats/docs/API/login/theme-toggle row.
- **Navigation bar**: 
  - Guest: Home + Stats + Upload (CTA)
  - Logged-in: Home + Stats + Gallery + Settings + Upload (CTA)
  - Docs/API links moved to footer.
- **Home page hero card**: Light mode now uses the same glassmorphism effect as dark mode (semi-transparent white gradient + backdrop blur).
- **Upload button colors**: Fixed light-mode CTA button to use `#0052D9` background with white text.
- **Documentation**: Restructured usage guide into 7 chapters with AVIF/Passkey/fullscreen-upload coverage and environment config cheat sheet.

### Fixed
- **Dark-mode selector optimization bug**: Replaced `html[data-theme="dark"]` element selectors with CSS custom properties to avoid Lightning CSS dropping dark-mode prefixes.
- **Login panel display conflict**: Removed HTML `hidden` class to prevent Tailwind v4 utility layer from overriding component layer.
- **Transform property conflict**: Notification animations now use `--tw-translate-x` + `translate` property instead of `transform`, avoiding conflicts with Tailwind v4 independent transform properties.

## [2.3.0] - 2025-03-28

### Added
- AVIF format support via ImageMagick.
- Preferred format setting (WebP / AVIF) in settings panel.
- Fullscreen upload mode.
- Distro detection in system status.
- Self-healing `open_basedir` sandbox.

### Changed
- UI overhaul with improved settings panel.

## [2.2.0] - 2025-02-20

### Added
- WebAuthn / Passkey login support.
- Docker support with Dockerfile and docker-compose.yml.

### Fixed
- Session headers-already-sent warning in Docker.
- Root `.htaccess` `php_flag engine off` issue.
