<?php
declare(strict_types=1);

namespace LitePic\Service\Image;

use LitePic\Repository\ImageRepository;

/**
 * Read EXIF (GPS / DateTimeOriginal / camera) from a file on disk and
 * persist it onto the `images` row *before* compression strips metadata.
 *
 * PHP `exif_read_data` covers JPEG/TIFF. HEIC and some others fall back
 * to Imagick property bags when available. Failure is silent — missing
 * EXIF is normal for PNGs, screenshots, already-stripped files.
 */
final class ExifService
{
    private ImageRepository $repo;

    public function __construct(?ImageRepository $repo = null)
    {
        $this->repo = $repo ?? new ImageRepository();
    }

    /**
     * Scan once (idempotent). Pass `$force=true` to re-read the file.
     */
    public function scanAndStore(string $identifier, bool $force = false): void
    {
        $id = PathService::normalizeIdentifier($identifier);
        if ($id === '') {
            return;
        }

        $row = $this->repo->find($id);
        if ($row !== null && !$force && !empty($row['exif_scanned'])) {
            return;
        }

        $path = PathService::resolveFilePath($id);
        $meta = is_file($path) ? $this->extract($path) : [];

        $payload = [
            'exif_scanned'  => 1,
            'exif_lat'      => $meta['lat'] ?? null,
            'exif_lng'      => $meta['lng'] ?? null,
            'exif_taken_at' => $meta['taken_at'] ?? null,
            'exif_camera'   => $meta['camera'] ?? null,
        ];

        if ($row === null) {
            // Row may not exist yet in edge cases — skip quietly.
            return;
        }
        $this->repo->update($id, $payload);
    }

    /**
     * @return array{lat:?float,lng:?float,taken_at:?int,camera:?string}
     */
    public function extract(string $filepath): array
    {
        $out = [
            'lat'      => null,
            'lng'      => null,
            'taken_at' => null,
            'camera'   => null,
        ];
        if (!is_file($filepath) || !is_readable($filepath)) {
            return $out;
        }

        $exif = null;
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($filepath, null, true);
            if (!is_array($exif)) {
                $exif = null;
            }
        }

        if ($exif !== null) {
            $gps = $exif['GPS'] ?? [];
            if (is_array($gps) && isset($gps['GPSLatitude'], $gps['GPSLongitude'])) {
                $lat = self::gpsToDecimal($gps['GPSLatitude'], (string)($gps['GPSLatitudeRef'] ?? 'N'));
                $lng = self::gpsToDecimal($gps['GPSLongitude'], (string)($gps['GPSLongitudeRef'] ?? 'E'));
                if ($lat !== null && $lng !== null) {
                    $out['lat'] = $lat;
                    $out['lng'] = $lng;
                }
            }

            $ifd0 = is_array($exif['IFD0'] ?? null) ? $exif['IFD0'] : [];
            $exifIfd = is_array($exif['EXIF'] ?? null) ? $exif['EXIF'] : [];
            $out['taken_at'] = self::parseTakenAt(
                (string)($exifIfd['DateTimeOriginal'] ?? $exifIfd['DateTimeDigitized'] ?? $ifd0['DateTime'] ?? '')
            );
            $out['camera'] = self::formatCamera(
                (string)($ifd0['Make'] ?? ''),
                (string)($ifd0['Model'] ?? '')
            );
        }

        // Imagick fallback (HEIC / when native exif missed GPS).
        if (($out['lat'] === null || $out['taken_at'] === null || $out['camera'] === null)
            && class_exists(\Imagick::class)
        ) {
            try {
                $img = new \Imagick($filepath);
                if ($out['lat'] === null) {
                    $latRaw = (string)$img->getImageProperty('exif:GPSLatitude');
                    $latRef = (string)$img->getImageProperty('exif:GPSLatitudeRef');
                    $lngRaw = (string)$img->getImageProperty('exif:GPSLongitude');
                    $lngRef = (string)$img->getImageProperty('exif:GPSLongitudeRef');
                    if ($latRaw !== '' && $lngRaw !== '') {
                        $lat = self::gpsToDecimal(self::parseGpsParts($latRaw), $latRef !== '' ? $latRef : 'N');
                        $lng = self::gpsToDecimal(self::parseGpsParts($lngRaw), $lngRef !== '' ? $lngRef : 'E');
                        if ($lat !== null && $lng !== null) {
                            $out['lat'] = $lat;
                            $out['lng'] = $lng;
                        }
                    }
                }
                if ($out['taken_at'] === null) {
                    $dt = (string)(
                        $img->getImageProperty('exif:DateTimeOriginal')
                        ?: $img->getImageProperty('exif:DateTimeDigitized')
                        ?: $img->getImageProperty('exif:DateTime')
                        ?: ''
                    );
                    $out['taken_at'] = self::parseTakenAt($dt);
                }
                if ($out['camera'] === null) {
                    $out['camera'] = self::formatCamera(
                        (string)$img->getImageProperty('exif:Make'),
                        (string)$img->getImageProperty('exif:Model')
                    );
                }
                $img->clear();
                $img->destroy();
            } catch (\Throwable $_) {
                // Imagick missing codec / corrupt file — ignore.
            }
        }

        return $out;
    }

    /**
     * Copy description + EXIF columns from one images row to another
     * (used when WebP/AVIF variants are created and may replace the original).
     */
    public function copyMeta(string $fromIdentifier, string $toIdentifier): void
    {
        $from = PathService::normalizeIdentifier($fromIdentifier);
        $to = PathService::normalizeIdentifier($toIdentifier);
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }
        $row = $this->repo->find($from);
        if ($row === null) {
            return;
        }
        $this->repo->update($to, [
            'description'   => (string)($row['description'] ?? ''),
            'exif_lat'      => $row['exif_lat'] ?? null,
            'exif_lng'      => $row['exif_lng'] ?? null,
            'exif_taken_at' => $row['exif_taken_at'] ?? null,
            'exif_camera'   => $row['exif_camera'] ?? null,
            'exif_scanned'  => !empty($row['exif_scanned']) ? 1 : 0,
        ]);
    }

    /**
     * @param array<int,mixed>|string $coord
     */
    private static function gpsToDecimal(array|string $coord, string $hemisphere): ?float
    {
        $parts = is_array($coord) ? $coord : self::parseGpsParts((string)$coord);
        if (count($parts) < 3) {
            return null;
        }
        $deg = self::fracToFloat($parts[0]);
        $min = self::fracToFloat($parts[1]);
        $sec = self::fracToFloat($parts[2]);
        if ($deg === null || $min === null || $sec === null) {
            return null;
        }
        $decimal = $deg + ($min / 60.0) + ($sec / 3600.0);
        $hemi = strtoupper(trim($hemisphere));
        if ($hemi === 'S' || $hemi === 'W') {
            $decimal *= -1;
        }
        if ($decimal < -180 || $decimal > 180) {
            return null;
        }
        return round($decimal, 7);
    }

    /**
     * @return array<int,string>
     */
    private static function parseGpsParts(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        // "31/1, 14/1, 1234/100" or "31,14,12.34"
        $chunks = preg_split('/\s*,\s*/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $chunks), 'strlen'));
    }

    private static function fracToFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float)$value;
        }
        $s = trim((string)$value);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, '/')) {
            [$n, $d] = array_pad(explode('/', $s, 2), 2, '1');
            $den = (float)$d;
            if ($den == 0.0) {
                return null;
            }
            return ((float)$n) / $den;
        }
        if (!is_numeric($s)) {
            return null;
        }
        return (float)$s;
    }

    private static function parseTakenAt(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // EXIF: "2024:03:15 14:22:01"
        $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $raw) ?? $raw;
        $ts = strtotime($normalized);
        if ($ts === false || $ts < 0) {
            return null;
        }
        return $ts;
    }

    private static function formatCamera(string $make, string $model): ?string
    {
        $make = trim($make);
        $model = trim($model);
        if ($make === '' && $model === '') {
            return null;
        }
        // Avoid "Apple Apple iPhone 15"
        if ($make !== '' && $model !== '' && stripos($model, $make) === 0) {
            $label = $model;
        } else {
            $label = trim($make . ' ' . $model);
        }
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        if (mb_strlen($label) > 80) {
            $label = mb_substr($label, 0, 80);
        }
        return $label !== '' ? $label : null;
    }
}
