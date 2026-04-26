<?php
declare(strict_types=1);

/**
 * 轻量 CBOR 解码器（支持 WebAuthn 所需的最小类型集）
 * CBOR 类型参考：https://tools.ietf.org/html/rfc8949
 */
class CborDecoder {
    private string $data;
    private int $pos = 0;
    private int $len;

    public static function decode(string $data): mixed {
        $decoder = new self($data);
        return $decoder->readValue();
    }

    private function __construct(string $data) {
        $this->data = $data;
        $this->len = strlen($data);
    }

    private function readValue(): mixed {
        if ($this->pos >= $this->len) {
            throw new Exception('CBOR: unexpected end of data');
        }

        $byte = ord($this->data[$this->pos++]);
        $major = ($byte >> 5) & 0x07;
        $info = $byte & 0x1F;

        switch ($major) {
            case 0: // unsigned int
                return $this->readUInt($info);
            case 1: // negative int
                return -1 - $this->readUInt($info);
            case 2: // byte string
                $len = $this->readUInt($info);
                return $this->readBytes($len);
            case 3: // text string
                $len = $this->readUInt($info);
                return $this->readBytes($len);
            case 4: // array
                $len = $this->readUInt($info);
                $arr = [];
                for ($i = 0; $i < $len; $i++) {
                    $arr[] = $this->readValue();
                }
                return $arr;
            case 5: // map
                $len = $this->readUInt($info);
                $map = [];
                for ($i = 0; $i < $len; $i++) {
                    $key = $this->readValue();
                    $val = $this->readValue();
                    $map[$key] = $val;
                }
                return $map;
            case 6: // tag
                $this->readUInt($info); // consume tag number
                return $this->readValue(); // return tagged value
            case 7: // simple/float
                if ($info === 20) return false;
                if ($info === 21) return true;
                if ($info === 22) return null;
                if ($info === 23) return null; // undefined
                if ($info === 26) { // float32
                    return $this->readFloat32();
                }
                if ($info === 27) { // float64
                    return $this->readFloat64();
                }
                return null;
            default:
                throw new Exception('CBOR: unsupported major type ' . $major);
        }
    }

    private function readUInt(int $info): int {
        if ($info < 24) {
            return $info;
        }
        switch ($info) {
            case 24:
                return ord($this->data[$this->pos++]);
            case 25:
                $val = unpack('n', $this->readBytes(2))[1];
                return $val;
            case 26:
                $val = unpack('N', $this->readBytes(4))[1];
                return $val;
            case 27:
                $bytes = $this->readBytes(8);
                $val = unpack('J', $bytes)[1];
                if ($val === false) {
                    // 32-bit PHP fallback
                    $hi = unpack('N', substr($bytes, 0, 4))[1];
                    $lo = unpack('N', substr($bytes, 4, 4))[1];
                    $val = ($hi << 32) | $lo;
                }
                return $val;
            default:
                throw new Exception('CBOR: invalid additional info ' . $info);
        }
    }

    private function readBytes(int $len): string {
        if ($this->pos + $len > $this->len) {
            throw new Exception('CBOR: not enough data for byte string');
        }
        $bytes = substr($this->data, $this->pos, $len);
        $this->pos += $len;
        return $bytes;
    }

    private function readFloat32(): float {
        $bytes = $this->readBytes(4);
        $parts = unpack('N', $bytes);
        $bits = $parts[1];
        $sign = ($bits >> 31) & 1;
        $exp = (($bits >> 23) & 0xFF) - 127;
        $mantissa = $bits & 0x7FFFFF;
        if ($exp === -127) {
            if ($mantissa === 0) return $sign ? -0.0 : 0.0;
            $exp = -126;
            $mantissa = $mantissa / 0x800000;
        } elseif ($exp === 128) {
            return ($mantissa === 0) ? ($sign ? -INF : INF) : NAN;
        } else {
            $mantissa = ($mantissa | 0x800000) / 0x800000;
        }
        $val = $mantissa * pow(2, $exp);
        return $sign ? -$val : $val;
    }

    private function readFloat64(): float {
        $bytes = $this->readBytes(8);
        $parts = unpack('J', $bytes);
        if ($parts !== false && is_int($parts[1])) {
            $bits = $parts[1];
        } else {
            $hi = unpack('N', substr($bytes, 0, 4))[1];
            $lo = unpack('N', substr($bytes, 4, 4))[1];
            $bits = ($hi << 32) | $lo;
        }
        $sign = ($bits >> 63) & 1;
        $exp = (($bits >> 52) & 0x7FF) - 1023;
        $mantissa = $bits & 0xFFFFFFFFFFFFF;
        if ($exp === -1023) {
            if ($mantissa === 0) return $sign ? -0.0 : 0.0;
            $exp = -1022;
            $mantissa = $mantissa / 0x10000000000000;
        } elseif ($exp === 1024) {
            return ($mantissa === 0) ? ($sign ? -INF : INF) : NAN;
        } else {
            $mantissa = ($mantissa | 0x10000000000000) / 0x10000000000000;
        }
        $val = $mantissa * pow(2, $exp);
        return $sign ? -$val : $val;
    }
}
