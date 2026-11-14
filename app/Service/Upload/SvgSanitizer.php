<?php
declare(strict_types=1);

namespace LitePic\Service\Upload;

/**
 * Whitelist SVG sanitizer.
 *
 * Why this class exists
 * ---------------------
 * Uploaded SVGs used to be accepted/rejected by matching the file content
 * against a fixed list of substrings (`onload=`, `<script`, `javascript:`,
 * …). That list can never be complete: SVG/HTML accept any `on*` event
 * attribute, tolerate whitespace around `=`, and allow namespace prefixes.
 * A file like
 *
 *     <rect onmouseenter="alert(1)"/>
 *     <rect onload = "alert(1)"/>
 *
 * passed the filter, and the stored SVG then ran script in the site's
 * origin — i.e. inside the administrator's session — as soon as somebody
 * opened the image URL directly.
 *
 * This class rebuilds the document from an explicit element/attribute
 * allowlist instead of trying to enumerate everything that is dangerous.
 * Whatever we did not think of is dropped, so unknown vectors fail closed.
 *
 * The sanitizer is intentionally strict:
 *   - elements outside the allowlist are removed *with their subtree*;
 *   - every `on*` attribute is removed, whatever its spelling;
 *   - URL attributes only keep `#fragment`, `http(s)://`, `mailto:` and
 *     non-scriptable `data:image/*` payloads;
 *   - CSS is scrubbed of `@import`, `expression()`, `-moz-binding` and
 *     non-fragment `url()` targets;
 *   - a DOCTYPE / ENTITY declaration is refused outright (XXE, billion
 *     laughs).
 *
 * The trade-off: SVGs that reference sibling files by relative URL
 * (`<image href="logo.png">`) lose that reference. Browsers already block
 * such external loads when an SVG is rendered through `<img>`, so nothing
 * that used to render keeps rendering differently.
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    /**
     * Elements we keep, matched against the lowercased local name.
     * Every other element is dropped together with its whole subtree.
     *
     * @var string[]
     */
    private const ALLOWED_ELEMENTS = [
        // structure
        'svg', 'g', 'defs', 'symbol', 'use', 'switch', 'view', 'a',
        'title', 'desc', 'style',
        // shapes
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        // text
        'text', 'tspan', 'textpath',
        // paint servers & painting
        'marker', 'clippath', 'mask', 'pattern',
        'lineargradient', 'radialgradient', 'stop',
        // raster image
        'image',
        // filters
        'filter', 'feblend', 'fecolormatrix', 'fecomponent', 'feflood',
        'fegaussianblur', 'femerge', 'femergenode', 'femorphology',
        'feoffset', 'fepointlight', 'fespecularlighting', 'fedropshadow',
        'fetile', 'feturbulence', 'fedisplacementmap',
        // SMIL animation — guarded separately, see isAnimationSafe()
        'animate', 'animatetransform', 'animatemotion', 'mpath',
    ];

    /**
     * Attributes whose value is a URL and therefore needs a scheme check.
     *
     * @var string[]
     */
    private const URL_ATTRIBUTES = [
        'href', 'src', 'data', 'action', 'formaction', 'poster', 'background',
    ];

    /**
     * Attributes we always strip: navigation side channels and the base
     * URL override (which would silently re-resolve relative references).
     *
     * @var string[]
     */
    private const FORBIDDEN_ATTRIBUTES = [
        'xml:base', 'ping', 'target', 'download', 'srcdoc', 'formaction',
        'action', 'http-equiv', 'content',
    ];

    /** @var string[] */
    private const ALLOWED_SCHEMES = ['http://', 'https://', 'mailto:'];

    /**
     * Which URL kinds each element may reference.
     *
     * The default is "this document only" — an external reference has to
     * earn its place:
     *   - `<a>` may link out (the visitor has to click it),
     *   - `<image>` may load a remote raster image (rendered in secure
     *     static mode, so it cannot execute script),
     *   - `<use>` may only reference a fragment of the same document —
     *     pulling in an external SVG is the classic SVG-injection vector.
     *
     * @var array<string,string[]>
     */
    private const URL_POLICY = [
        'a'     => ['fragment', 'http', 'https', 'mailto'],
        'image' => ['fragment', 'http', 'https', 'data-image'],
        'use'   => ['fragment'],
    ];

    /** Inline image payloads we accept. `data:image/svg+xml` is scriptable. */
    private const ALLOWED_DATA_IMAGE = '/^data:image\/(?:png|jpe?g|gif|webp|bmp|avif)[;,]/';

    /**
     * Sanitize the SVG stored at $path and write the result back.
     *
     * @param bool $write When false the file is only inspected — the
     *                    on-disk bytes stay untouched.
     * @return bool True when the file is a valid (possibly rewritten) SVG.
     */
    public static function sanitizeFile(string $path, bool $write = true): bool
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return false;

        $clean = self::sanitize($raw);
        if ($clean === null) return false;

        if ($write && @file_put_contents($path, $clean) === false) {
            return false;
        }
        return true;
    }

    /**
     * Sanitize an SVG document.
     *
     * @param string $raw The raw file content.
     * @return string|null The sanitized XML, or null when the input is not
     *                     a usable SVG.
     */
    public static function sanitize(string $raw): ?string
    {
        // An internal ENTITY declaration is the entry point for XXE and
        // billion-laughs expansion — always fatal.
        if (preg_match('/<!\s*ENTITY/i', $raw) === 1) return null;

        // Tolerate a UTF-8 BOM / leading whitespace before the declaration.
        $source = ltrim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw);
        if ($source === '') return null;

        // Strip the DOCTYPE before parsing, so libxml never sees a DTD at
        // all. SVG 1.1 files from older tools (Illustrator, Inkscape) ship a
        // harmless external DTD reference — refusing those would break
        // perfectly legitimate uploads.
        $source = preg_replace('/<!DOCTYPE[^>\[]*(?:\[[^\]]*\])?[^>]*>/is', '', $source) ?? $source;

        $previousErrors = libxml_use_internal_errors(true);
        // Disable external entity loading for the parse. Note: the return
        // value is a bool, not the previous loader, so there is nothing to
        // restore — and nothing in LitePic needs external entities anyway.
        @libxml_set_external_entity_loader(null);

        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;

        $loaded = $doc->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if ($loaded === false) return null;

        $root = $doc->documentElement;
        if (!$root instanceof \DOMElement) return null;
        if (strtolower($root->localName) !== 'svg') return null;

        // Processing instructions sitting *outside* the root element (an
        // `xml-stylesheet` PI, for example) would pull in an external
        // stylesheet — they are siblings of the root, not children, so the
        // recursive cleaner below never sees them.
        foreach ($doc->childNodes as $node) {
            if ($node instanceof \DOMProcessingInstruction || $node instanceof \DOMComment) {
                $doc->removeChild($node);
            }
        }

        // The root must live in the SVG namespace (or in no namespace at
        // all — we normalise that below).
        if ($root->namespaceURI !== null && $root->namespaceURI !== '' && $root->namespaceURI !== self::SVG_NS) {
            return null;
        }

        self::cleanElement($root);

        // A namespace-less document never renders as SVG; give it the
        // declaration so the file we store actually works.
        if ($root->namespaceURI === null || $root->namespaceURI === '') {
            $root->setAttribute('xmlns', self::SVG_NS);
        }

        $out = $doc->saveXML();
        if (!is_string($out) || trim($out) === '') return null;
        return $out;
    }

    /**
     * Recursively clean an element: attributes first, then children.
     */
    private static function cleanElement(\DOMElement $el): void
    {
        self::cleanAttributes($el);

        // Snapshot the children — we mutate the live node list below.
        $children = [];
        foreach ($el->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            // Processing instructions (xml-stylesheet) can pull in an
            // external stylesheet — drop them.
            if ($child instanceof \DOMProcessingInstruction || $child instanceof \DOMComment) {
                $el->removeChild($child);
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue; // text / CDATA — kept as-is
            }

            $name = strtolower($child->localName);

            if (!in_array($name, self::ALLOWED_ELEMENTS, true)) {
                $el->removeChild($child);
                continue;
            }

            // Elements from a foreign namespace (xhtml, xlink, ...) are
            // never rendered as SVG and are a classic namespace-confusion
            // vector — drop them.
            if ($child->namespaceURI !== null && $child->namespaceURI !== '' && $child->namespaceURI !== self::SVG_NS) {
                $el->removeChild($child);
                continue;
            }

            if (in_array($name, ['animate', 'animatetransform', 'animatemotion'], true)) {
                if (!self::isAnimationSafe($child)) {
                    $el->removeChild($child);
                    continue;
                }
            }

            if ($name === 'style') {
                self::cleanStyleElement($child);
                continue;
            }

            self::cleanElement($child);
        }
    }

    /**
     * Strip every attribute we do not trust.
     */
    private static function cleanAttributes(\DOMElement $el): void
    {
        /** @var \DOMAttr[] $remove */
        $remove = [];
        /** @var array{0:\DOMAttr,1:string,2:string} $rewrite */
        $rewrite = [];

        $element = strtolower($el->localName);

        foreach ($el->attributes as $attr) {
            if (!$attr instanceof \DOMAttr) continue;

            $name = strtolower($attr->name);
            $value = (string)$attr->value;

            // Namespace declarations are structural — never touch them.
            if ($name === 'xmlns' || str_starts_with($name, 'xmlns:')) continue;

            // Every event handler, whatever its spelling or case.
            if (str_starts_with($name, 'on')) {
                $remove[] = $attr;
                continue;
            }

            if (in_array($name, self::FORBIDDEN_ATTRIBUTES, true)) {
                $remove[] = $attr;
                continue;
            }

            // `xlink:href` → plain `href` (SVG2), keeping only safe targets.
            if ($name === 'xlink:href') {
                $target = self::safeUrlTarget($value, $element);
                if ($target === null) {
                    $remove[] = $attr;
                    continue;
                }
                $rewrite[] = [$attr, 'href', $target];
                continue;
            }
            if (str_starts_with($name, 'xlink:')) {
                $remove[] = $attr;
                continue;
            }

            if ($name === 'style') {
                $css = self::sanitizeCss($value);
                if ($css === '') {
                    $remove[] = $attr;
                    continue;
                }
                if ($css !== $value) {
                    $rewrite[] = [$attr, 'style', $css];
                }
                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true)) {
                $target = self::safeUrlTarget($value, $element);
                if ($target === null) {
                    $remove[] = $attr;
                    continue;
                }
                if ($target !== $value) {
                    $rewrite[] = [$attr, $name, $target];
                }
                continue;
            }

            // Generic value guard — no script schemes anywhere.
            if (!self::isSafeValue($value)) {
                $remove[] = $attr;
                continue;
            }

            // `fill="url(#gradient)"` style references must stay local.
            if (stripos($value, 'url(') !== false && !self::hasOnlyFragmentUrls($value)) {
                $remove[] = $attr;
                continue;
            }
        }

        foreach ($remove as $attr) {
            if ($attr->parentNode instanceof \DOMElement) {
                $attr->parentNode->removeAttributeNode($attr);
            }
        }
        foreach ($rewrite as [$attr, $newName, $newValue]) {
            $owner = $attr->parentNode;
            if ($owner instanceof \DOMElement) {
                $owner->removeAttributeNode($attr);
                $owner->setAttribute($newName, $newValue);
            }
        }
    }

    /**
     * Replace the content of a `<style>` element with scrubbed CSS.
     */
    private static function cleanStyleElement(\DOMElement $el): void
    {
        $css = '';
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                $css .= $child->textContent;
            }
        }

        $clean = self::sanitizeCss($css);

        while ($el->firstChild !== null) {
            $el->removeChild($el->firstChild);
        }
        if ($clean !== '') {
            $doc = $el->ownerDocument;
            if ($doc !== null) {
                $el->appendChild($doc->createTextNode($clean));
            }
        }
    }

    /**
     * SMIL animation may not touch event handlers or URL attributes, and
     * must not carry script payloads in its value attributes.
     */
    private static function isAnimationSafe(\DOMElement $el): bool
    {
        foreach ($el->attributes as $attr) {
            if (!$attr instanceof \DOMAttr) continue;
            if (strtolower($attr->name) === 'attributename') {
                $target = strtolower(trim((string)$attr->value));
                if (str_starts_with($target, 'on')) return false;
                if ($target === 'href' || $target === 'xlink:href') return false;
            }
        }

        foreach (['from', 'to', 'by', 'values'] as $name) {
            $value = trim((string)$el->getAttribute($name));
            if ($value === '') continue;
            if (!self::isSafeValue($value)) return false;
            $squished = self::squish($value);
            if (str_contains($squished, 'javascript:')
                || str_contains($squished, 'vbscript:')
                || str_contains($squished, 'data:')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a URL-bearing attribute value against the element's policy.
     *
     * @param string $value   The raw attribute value.
     * @param string $element The lowercased local name of the owning element.
     * @return string|null The value to keep, or null when it must go.
     */
    private static function safeUrlTarget(string $value, string $element): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') return null;

        $allowed = self::URL_POLICY[$element] ?? ['fragment'];

        // Same-document reference.
        if (str_starts_with($trimmed, '#')) {
            return in_array('fragment', $allowed, true) ? $trimmed : null;
        }

        // Protocol-relative URLs (`//host/x`) inherit the page scheme and
        // are not worth the ambiguity — reject.
        if (str_starts_with($trimmed, '//')) return null;

        $squished = self::squish($trimmed);

        if (in_array('http', $allowed, true)
            && (str_starts_with($squished, 'http://') || str_starts_with($squished, 'https://'))) {
            return self::isSafeValue($trimmed) ? $trimmed : null;
        }

        if (in_array('mailto', $allowed, true) && str_starts_with($squished, 'mailto:')) {
            return self::isSafeValue($trimmed) ? $trimmed : null;
        }

        if (in_array('data-image', $allowed, true)
            && preg_match(self::ALLOWED_DATA_IMAGE, $squished) === 1) {
            return $trimmed;
        }

        // Relative URLs and unknown schemes are rejected.
        return null;
    }

    /**
     * Generic guard against script-carrying values.
     */
    private static function isSafeValue(string $value): bool
    {
        $squished = self::squish($value);
        foreach (['javascript:', 'vbscript:', 'data:text/html', 'data:application', 'data:image/svg'] as $bad) {
            if (str_contains($squished, $bad)) return false;
        }
        return true;
    }

    /**
     * Remove whitespace and control characters so `java\nscript:` cannot
     * smuggle a scheme past the checks.
     */
    private static function squish(string $value): string
    {
        $stripped = preg_replace('/[\s\x00-\x20\x7F]+/', '', $value);
        return strtolower(is_string($stripped) ? $stripped : $value);
    }

    /**
     * True when every `url(...)` in the value points at a local fragment
     * or an allowed inline image.
     */
    private static function hasOnlyFragmentUrls(string $value): bool
    {
        $matched = preg_match_all('/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i', $value, $m);
        if (!is_int($matched) || $matched === 0) return true;

        foreach ($m[2] as $target) {
            $target = trim($target);
            if ($target === '') return false;
            if (str_starts_with($target, '#')) continue;
            if (preg_match(self::ALLOWED_DATA_IMAGE, self::squish($target)) === 1) continue;
            return false;
        }
        return true;
    }

    /**
     * Scrub a CSS fragment.
     */
    private static function sanitizeCss(string $css): string
    {
        // Comments can hide anything — strip them first.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

        // External stylesheets.
        $css = preg_replace('/@import[^;}]*;?/i', '', $css) ?? $css;

        // Legacy script vectors.
        $css = preg_replace('/expression\s*\(/i', 'blocked(', $css) ?? $css;
        $css = preg_replace('/-moz-binding\s*:[^;}]*;?/i', '', $css) ?? $css;
        $css = preg_replace('/behavior\s*:[^;}]*;?/i', '', $css) ?? $css;

        // url() targets — keep local fragments and inline images only.
        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]*)\1\s*\)/i',
            static function (array $m): string {
                $target = trim($m[2]);
                if ($target === '') return 'none';
                if (str_starts_with($target, '#')) return $m[0];
                if (preg_match(self::ALLOWED_DATA_IMAGE, self::squish($target)) === 1) return $m[0];
                return 'none';
            },
            $css
        ) ?? $css;

        // Anything still carrying a script scheme invalidates the whole
        // declaration block.
        if (preg_match('/(?:javascript|vbscript)\s*:/i', $css) === 1) return '';

        return $css;
    }
}
