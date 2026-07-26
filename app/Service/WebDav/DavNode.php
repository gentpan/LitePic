<?php
declare(strict_types=1);

namespace LitePic\Service\WebDav;

/**
 * One resource in the WebDAV tree.
 *
 * Plain mutable properties rather than `readonly` — LitePic supports PHP 8.0,
 * where that modifier does not exist. Instances are only ever produced by the
 * named constructors below and treated as immutable by every consumer.
 *
 * The tree has seven kinds of node:
 *
 *   root         `/`                          the mount itself
 *   album        `/风景照`                     one album, writable
 *   unfiled      `/未分类`                     images belonging to no album
 *   date         `/按日期`                     read-only view root
 *   dateYear     `/按日期/2026`
 *   dateMonth    `/按日期/2026/07`
 *   file         a stored image, in any of the collections above
 *
 * A file node carries `entryId` when it came from a `dav_entries` mapping and
 * `null` when it came from the date view, which addresses images by their raw
 * identifier and therefore needs no mapping row. `filename === null` on a file
 * node means an empty placeholder (the 0-byte probe clients send before a real
 * PUT); its size is 0 and it has no bytes on disk.
 */
final class DavNode
{
    public const KIND_ROOT = 'root';
    public const KIND_ALBUM = 'album';
    public const KIND_UNFILED = 'unfiled';
    public const KIND_DATE = 'date';
    public const KIND_DATE_YEAR = 'date_year';
    public const KIND_DATE_MONTH = 'date_month';
    public const KIND_FILE = 'file';

    public string $kind;
    public string $path;
    public string $name;
    public bool $isCollection;

    /** Album id for collections and for files inside one; 0 means "unfiled". */
    public ?int $albumId = null;

    /** `dav_entries.id`, when this node is backed by a mapping row. */
    public ?int $entryId = null;

    /** Image identifier (`2026/07/AbCdEf123456.jpg`); null for placeholders. */
    public ?string $filename = null;

    public int $size = 0;
    public int $modifiedAt = 0;
    public int $createdAt = 0;
    public string $contentType = '';

    /** Set on the date view, whose names are system identifiers we must not rewrite. */
    public bool $writable = true;

    /** Album row, when kind is KIND_ALBUM. @var array<string,mixed>|null */
    public ?array $album = null;

    /** Image row, when kind is KIND_FILE and the image exists. @var array<string,mixed>|null */
    public ?array $image = null;

    private function __construct(string $kind, string $path, string $name, bool $isCollection)
    {
        $this->kind = $kind;
        $this->path = $path;
        $this->name = $name;
        $this->isCollection = $isCollection;
        $this->modifiedAt = time();
        $this->createdAt = $this->modifiedAt;
    }

    public static function root(): self
    {
        return new self(self::KIND_ROOT, '/', '', true);
    }

    /**
     * @param array<string,mixed> $album
     */
    public static function album(string $path, string $name, array $album): self
    {
        $node = new self(self::KIND_ALBUM, $path, $name, true);
        $node->albumId = (int)($album['id'] ?? 0);
        $node->album = $album;
        $node->createdAt = (int)($album['created_at'] ?? time());
        $node->modifiedAt = (int)($album['updated_at'] ?? $node->createdAt);
        return $node;
    }

    public static function unfiled(): self
    {
        $node = new self(self::KIND_UNFILED, '/' . DavPath::DIR_UNFILED, DavPath::DIR_UNFILED, true);
        $node->albumId = 0;
        return $node;
    }

    public static function dateRoot(): self
    {
        $node = new self(self::KIND_DATE, '/' . DavPath::DIR_DATE, DavPath::DIR_DATE, true);
        $node->writable = false;
        return $node;
    }

    public static function dateYear(string $year): self
    {
        $node = new self(
            self::KIND_DATE_YEAR,
            '/' . DavPath::DIR_DATE . '/' . $year,
            $year,
            true
        );
        $node->writable = false;
        return $node;
    }

    public static function dateMonth(string $year, string $month): self
    {
        $node = new self(
            self::KIND_DATE_MONTH,
            '/' . DavPath::DIR_DATE . '/' . $year . '/' . $month,
            $month,
            true
        );
        $node->writable = false;
        return $node;
    }

    /**
     * A stored image.
     *
     * @param array<string,mixed>|null $image Row from `images`, when known.
     */
    public static function file(
        string $path,
        string $name,
        ?string $filename,
        ?array $image,
        ?int $entryId,
        ?int $albumId,
        bool $writable = true
    ): self {
        $node = new self(self::KIND_FILE, $path, $name, false);
        $node->filename = $filename;
        $node->image = $image;
        $node->entryId = $entryId;
        $node->albumId = $albumId;
        $node->writable = $writable;

        if ($image !== null) {
            $node->size = (int)($image['size'] ?? 0);
            $node->createdAt = (int)($image['created_at'] ?? time());
            $node->modifiedAt = $node->createdAt;
            $node->contentType = (string)($image['mime'] ?? '') ?: 'application/octet-stream';
        } else {
            // Placeholder: exists as a name, has no bytes yet.
            $node->size = 0;
            $node->contentType = 'application/octet-stream';
        }

        return $node;
    }

    public function isPlaceholder(): bool
    {
        return $this->kind === self::KIND_FILE && $this->filename === null;
    }

    public function href(): string
    {
        return DavPath::href($this->path, $this->isCollection);
    }

    /**
     * Weak-ish entity tag. Built from the identifier plus size and mtime so it
     * changes when an overwrite replaces the bytes behind a name, and is stable
     * across requests otherwise.
     */
    public function etag(): string
    {
        $seed = ($this->filename ?? $this->path) . ':' . $this->size . ':' . $this->modifiedAt;
        return '"' . md5($seed) . '"';
    }
}
