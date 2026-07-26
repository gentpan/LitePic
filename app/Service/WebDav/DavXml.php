<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Parsing of the three WebDAV request bodies that carry XML: PROPFIND,
 * PROPPATCH, and LOCK.
 *
 * Parsing is deliberately forgiving. A malformed or unparseable PROPFIND body
 * degrades to `allprop` rather than erroring, because clients in the wild send
 * bodies with mismatched namespaces, stray BOMs, and (in one Android client's
 * case) no body at all alongside a non-zero Content-Length. Returning 400 there
 * makes the mount look broken; returning every property does not.
 *
 * Security notes:
 *   - `LIBXML_NONET` blocks network access during parsing. PHP 8 already
 *     defaults external entity substitution off, but passing the flag keeps the
 *     guarantee explicit rather than dependent on a php.ini or version detail.
 *   - Body size is capped upstream in {@see DavRequest::xmlBody()}, which is
 *     what bounds entity-expansion work here.
 *   - `DOMDocument` is probed with `class_exists` instead of assumed: LitePic's
 *     only other XML dependency is `simplexml`, and a PHP build can ship that
 *     without `dom`. Missing `dom` degrades WebDAV to allprop behaviour rather
 *     than failing the request.
 */
final class DavXml
{
    public const PROPFIND_PROP = 'prop';
    public const PROPFIND_ALLPROP = 'allprop';
    public const PROPFIND_PROPNAME = 'propname';

    /**
     * What a PROPFIND is asking for.
     *
     * @return array{mode:string,props:string[]} `props` is only meaningful for
     *         mode `prop`, and holds canonical `{ns}local` names.
     */
    public static function parsePropfind(string $body): array
    {
        $root = self::rootElement($body);
        if ($root === null) {
            return ['mode' => self::PROPFIND_ALLPROP, 'props' => []];
        }

        foreach (self::childElements($root) as $child) {
            $local = $child->localName;
            if ($local === self::PROPFIND_PROPNAME) {
                return ['mode' => self::PROPFIND_PROPNAME, 'props' => []];
            }
            if ($local === self::PROPFIND_ALLPROP) {
                return ['mode' => self::PROPFIND_ALLPROP, 'props' => []];
            }
            if ($local === self::PROPFIND_PROP) {
                $props = [];
                foreach (self::childElements($child) as $requested) {
                    $props[] = DavResponse::prop(
                        (string)($requested->namespaceURI ?? DavResponse::NS_DAV),
                        (string)$requested->localName
                    );
                }
                // An empty <prop/> is meaningless; treat it as allprop so the
                // client still gets a usable listing.
                return $props === []
                    ? ['mode' => self::PROPFIND_ALLPROP, 'props' => []]
                    : ['mode' => self::PROPFIND_PROP, 'props' => array_values(array_unique($props))];
            }
        }

        return ['mode' => self::PROPFIND_ALLPROP, 'props' => []];
    }

    /**
     * Property names a PROPPATCH wants to set and remove.
     *
     * LitePic stores no dead properties — the handler answers every name with a
     * status — but it still has to know the names to produce a well-formed
     * multistatus, and Windows Explorer aborts a copy when it gets anything
     * else back.
     *
     * @return array{set:string[],remove:string[]}
     */
    public static function parsePropPatch(string $body): array
    {
        $root = self::rootElement($body);
        if ($root === null) {
            return ['set' => [], 'remove' => []];
        }

        $result = ['set' => [], 'remove' => []];
        foreach (self::childElements($root) as $instruction) {
            $bucket = match ($instruction->localName) {
                'set' => 'set',
                'remove' => 'remove',
                default => null,
            };
            if ($bucket === null) {
                continue;
            }
            foreach (self::childElements($instruction) as $propContainer) {
                if ($propContainer->localName !== 'prop') {
                    continue;
                }
                foreach (self::childElements($propContainer) as $prop) {
                    $result[$bucket][] = DavResponse::prop(
                        (string)($prop->namespaceURI ?? DavResponse::NS_DAV),
                        (string)$prop->localName
                    );
                }
            }
        }

        $result['set'] = array_values(array_unique($result['set']));
        $result['remove'] = array_values(array_unique($result['remove']));
        return $result;
    }

    /**
     * Lock request details. An empty body means "refresh an existing lock",
     * signalled by `refresh: true`.
     *
     * @return array{refresh:bool,scope:string,type:string,owner:string}
     */
    public static function parseLockInfo(string $body): array
    {
        $default = ['refresh' => true, 'scope' => 'exclusive', 'type' => 'write', 'owner' => ''];
        if (trim($body) === '') {
            return $default;
        }

        $root = self::rootElement($body);
        if ($root === null || $root->localName !== 'lockinfo') {
            return $default;
        }

        $scope = 'exclusive';
        $type = 'write';
        $owner = '';
        foreach (self::childElements($root) as $child) {
            switch ($child->localName) {
                case 'lockscope':
                    foreach (self::childElements($child) as $candidate) {
                        if ($candidate->localName === 'shared') {
                            $scope = 'shared';
                        }
                    }
                    break;
                case 'locktype':
                    foreach (self::childElements($child) as $candidate) {
                        if ($candidate->localName === 'write') {
                            $type = 'write';
                        }
                    }
                    break;
                case 'owner':
                    // Owner is opaque XML the server must echo back verbatim in
                    // theory; storing its text content is enough to identify the
                    // holder in messages and is safe to re-emit escaped.
                    $owner = trim((string)$child->textContent);
                    break;
            }
        }

        return ['refresh' => false, 'scope' => $scope, 'type' => $type, 'owner' => $owner];
    }

    private static function rootElement(string $body): ?DOMElement
    {
        $body = trim($body);
        if ($body === '' || !class_exists(DOMDocument::class)) {
            return null;
        }
        // Strip a UTF-8 BOM — libxml treats a BOM after the XML declaration as
        // a fatal error, and at least one iOS client sends one.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$document->documentElement instanceof DOMElement) {
            return null;
        }
        return $document->documentElement;
    }

    /**
     * @return DOMElement[]
     */
    private static function childElements(DOMNode $node): array
    {
        $elements = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }
        return $elements;
    }
}
