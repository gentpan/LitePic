<?php
declare(strict_types=1);

namespace LitePic\Service\Auth\Passkey;

use Exception;
use LitePic\Repository\WebAuthnRepository;

/**
 * Lightweight WebAuthn (Passkey / passwordless) implementation.
 * Only supports ES256 (ECDSA P-256 + SHA-256).
 *
 * State (challenges + registered credentials) lives in SQLite via
 * WebAuthnRepository — no more `data/passkeys.json` or
 * `data/challenges/*.json` sidecars.
 */
class WebAuthn {
    private string $rpName;
    private string $rpId;
    private string $origin;
    private WebAuthnRepository $repo;

    /**
     * @param string $rpName  依赖方名称（如：LitePic）
     * @param string $rpId    依赖方 ID（如：img.xifeng.net）
     * @param string $origin  完整来源（如：https://img.xifeng.net）
     */
    public function __construct(string $rpName, string $rpId, string $origin, ?WebAuthnRepository $repo = null) {
        $this->rpName = $rpName;
        $this->rpId = $rpId;
        $this->origin = $origin;
        $this->repo = $repo ?? new WebAuthnRepository();
    }

    // ==================== 工具方法 ====================

    /** 生成安全随机字符串 */
    private static function randomBytes(int $len): string {
        return random_bytes($len);
    }

    /** Base64URL 编码（无填充） */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** Base64URL 解码 */
    public static function base64UrlDecode(string $data): string {
        $pad = 4 - (strlen($data) % 4);
        if ($pad !== 4) {
            $data .= str_repeat('=', $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /** 生成挑战 */
    public function generateChallenge(): string {
        return self::base64UrlEncode(self::randomBytes(32));
    }

    /** 存储挑战（带 5 分钟过期） */
    public function storeChallenge(string $type, string $challenge): void {
        $type = self::normalizeType($type);
        $this->repo->putChallenge($type, $challenge, 300);
    }

    /** 验证并消耗挑战（无论命中与否都会消耗，避免被试探） */
    public function consumeChallenge(string $type, string $challenge): bool {
        $type = self::normalizeType($type);
        $stored = $this->repo->takeChallenge($type);
        if ($stored === null) return false;
        return hash_equals($stored, $challenge);
    }

    private static function normalizeType(string $type): string {
        // Match the old filename sanitiser so legacy callers keep working.
        return (string)preg_replace('/[^a-z]/', '', strtolower($type));
    }

    // ==================== 凭证存储 ====================

    /**
     * Returns credentials in the same nested shape the legacy JSON
     * file used (keyed by credentialId), so the rest of the class can
     * keep the existing array-access pattern without further changes.
     */
    public function getCredentials(): array {
        $rows = $this->repo->listCredentials();
        $out = [];
        foreach ($rows as $cred) {
            $out[$cred['credentialId']] = $cred;
        }
        return $out;
    }

    /** 根据 credentialId 查找凭证 */
    public function findCredential(string $credentialId): ?array {
        return $this->repo->findCredential($credentialId);
    }

    /**
     * Whether at least one Passkey credential is registered.
     *
     * Cheap (single COUNT) — safe to call at page render time so the
     * frontend can hide the "Login with Passkey" button when nothing
     * is configured yet, instead of triggering a runtime API call that
     * fails with an opaque error.
     */
    public function hasCredentials(): bool {
        return $this->repo->countCredentials() > 0;
    }

    /** 删除凭证 */
    public function deleteCredential(string $credentialId): bool {
        return $this->repo->deleteCredential($credentialId);
    }

    // ==================== 注册 (Registration) ====================

    /**
     * 生成注册选项
     */
    public function getRegistrationOptions(): array {
        $challenge = $this->generateChallenge();
        $this->storeChallenge('register', $challenge);

        return [
            'rp' => [
                'name' => $this->rpName,
                'id' => $this->rpId,
            ],
            'user' => [
                'id' => self::base64UrlEncode('admin'),
                'name' => 'admin',
                'displayName' => 'Administrator',
            ],
            'challenge' => $challenge,
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7], // ES256
            ],
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'residentKey' => 'preferred',
                'userVerification' => 'preferred',
            ],
            'attestation' => 'none',
            'timeout' => 120000,
        ];
    }

    /**
     * 验证注册响应并保存凭证
     *
     * @param array $clientData  前端传来的 clientDataJSON（已 Base64URL 解码的字符串）
     * @param array $attestation 前端传来的 attestationObject（Base64URL 编码）
     * @param string $credentialId 前端传来的 credentialId（Base64URL 编码）
     */
    public function verifyRegistration(string $clientDataJson, string $attestationObjectB64, string $credentialIdB64): array {
        $credentialId = self::base64UrlDecode($credentialIdB64);
        if (strlen($credentialId) === 0) {
            throw new Exception('credentialId 为空');
        }

        // 1. 解析 clientDataJSON
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new Exception('clientDataJSON 解析失败');
        }

        // 2. 验证 challenge
        if (empty($clientData['challenge']) || !$this->consumeChallenge('register', $clientData['challenge'])) {
            throw new Exception('challenge 无效或已过期');
        }

        // 3. 验证 origin
        if (($clientData['origin'] ?? '') !== $this->origin) {
            throw new Exception('origin 不匹配: ' . ($clientData['origin'] ?? 'null'));
        }

        // 4. 验证 type
        if (($clientData['type'] ?? '') !== 'webauthn.create') {
            throw new Exception('clientData.type 不正确');
        }

        // 5. 解析 attestationObject (CBOR)
        $attestationBytes = self::base64UrlDecode($attestationObjectB64);
        $attestation = CborDecoder::decode($attestationBytes);
        if (!is_array($attestation)) {
            throw new Exception('attestationObject CBOR 解码失败');
        }

        $authData = $attestation['authData'] ?? null;
        if (!is_string($authData) || strlen($authData) < 37) {
            throw new Exception('authenticatorData 无效');
        }

        // 6. 解析 authenticatorData
        $rpIdHash = substr($authData, 0, 32);
        $flags = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        // 验证 RP ID hash
        if (!hash_equals($rpIdHash, hash('sha256', $this->rpId, true))) {
            throw new Exception('rpIdHash 不匹配');
        }

        // 检查 user present flag
        if (($flags & 0x01) === 0) {
            throw new Exception('user present 标志未设置');
        }

        // 7. 提取 credentialId 和 public key
        $offset = 37;
        $aaguid = substr($authData, $offset, 16);
        $offset += 16;
        $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
        $offset += 2;
        $authCredId = substr($authData, $offset, $credIdLen);
        $offset += $credIdLen;
        $publicKeyCose = substr($authData, $offset);

        if (!hash_equals($credentialId, $authCredId)) {
            throw new Exception('credentialId 不匹配');
        }

        // 8. 解析 COSE 公钥
        $publicKey = $this->parseCosePublicKey($publicKeyCose);

        // 9. 保存凭证 — DB-backed via WebAuthnRepository
        $credentialIdB64 = self::base64UrlEncode($credentialId);
        $this->repo->saveCredential(
            $credentialIdB64,
            $publicKey['x'],
            $publicKey['y'],
            (int)$signCount
        );

        return [
            'success' => true,
            'credentialId' => self::base64UrlEncode($credentialId),
        ];
    }

    /**
     * 从 COSE_Key 解析 ES256 公钥
     */
    private function parseCosePublicKey(string $coseKey): array {
        $cose = CborDecoder::decode($coseKey);
        if (!is_array($cose)) {
            throw new Exception('COSE 公钥解码失败');
        }

        // COSE Key 参数
        $kty = $cose[1] ?? null;  // Key type: 2 = EC2
        $alg = $cose[3] ?? null;  // Algorithm: -7 = ES256
        $crv = $cose[-1] ?? null; // Curve: 1 = P-256
        $x = $cose[-2] ?? null;   // X coordinate
        $y = $cose[-3] ?? null;   // Y coordinate

        if ($kty !== 2 || $alg !== -7 || $crv !== 1) {
            throw new Exception('仅支持 ES256 (ECDSA P-256) 算法');
        }
        if (!is_string($x) || strlen($x) !== 32 || !is_string($y) || strlen($y) !== 32) {
            throw new Exception('公钥坐标无效');
        }

        return [
            'x' => self::base64UrlEncode($x),
            'y' => self::base64UrlEncode($y),
        ];
    }

    // ==================== 认证 (Authentication) ====================

    /**
     * 生成认证选项
     */
    public function getAuthenticationOptions(): array {
        $challenge = $this->generateChallenge();
        $this->storeChallenge('authenticate', $challenge);

        $credentials = $this->getCredentials();
        $allowCredentials = [];
        foreach ($credentials as $cred) {
            $allowCredentials[] = [
                'type' => 'public-key',
                'id' => $cred['credentialId'],
            ];
        }

        return [
            'challenge' => $challenge,
            'rpId' => $this->rpId,
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'preferred',
            'timeout' => 120000,
        ];
    }

    /**
     * 验证认证响应
     */
    public function verifyAuthentication(string $credentialIdB64, string $authenticatorDataB64, string $clientDataJson, string $signatureB64): bool {
        $credentialId = self::base64UrlDecode($credentialIdB64);

        // 1. 查找凭证
        $cred = $this->findCredential($credentialIdB64);
        if (!$cred) {
            throw new Exception('凭证不存在');
        }

        // 2. 解析 clientDataJSON
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new Exception('clientDataJSON 解析失败');
        }

        // 3. 验证 challenge
        if (empty($clientData['challenge']) || !$this->consumeChallenge('authenticate', $clientData['challenge'])) {
            throw new Exception('challenge 无效或已过期');
        }

        // 4. 验证 origin
        if (($clientData['origin'] ?? '') !== $this->origin) {
            throw new Exception('origin 不匹配');
        }

        // 5. 验证 type
        if (($clientData['type'] ?? '') !== 'webauthn.get') {
            throw new Exception('clientData.type 不正确');
        }

        // 6. 解析 authenticatorData
        $authData = self::base64UrlDecode($authenticatorDataB64);
        if (strlen($authData) < 37) {
            throw new Exception('authenticatorData 无效');
        }

        $rpIdHash = substr($authData, 0, 32);
        $flags = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];

        if (!hash_equals($rpIdHash, hash('sha256', $this->rpId, true))) {
            throw new Exception('rpIdHash 不匹配');
        }
        if (($flags & 0x01) === 0) {
            throw new Exception('user present 标志未设置');
        }

        // 7. 签名验证
        $signature = self::base64UrlDecode($signatureB64);
        $publicKeyPem = $this->buildEcdsaPublicKeyPem(
            self::base64UrlDecode($cred['publicKey']['x']),
            self::base64UrlDecode($cred['publicKey']['y'])
        );

        // 构建签名数据：authenticatorData + sha256(clientDataJSON)
        $clientDataHash = hash('sha256', $clientDataJson, true);
        $verifyData = $authData . $clientDataHash;

        $verified = openssl_verify($verifyData, $signature, $publicKeyPem, 'SHA256');
        if ($verified !== 1) {
            throw new Exception('签名验证失败: ' . openssl_error_string());
        }

        // 8. 更新签名计数器（防止重放）
        if ($signCount > 0 && $signCount <= ($cred['signCount'] ?? 0)) {
            throw new Exception('签名计数器回退，可能存在重放攻击');
        }

        // Update sign counter + last-used timestamp via the repo.
        $this->repo->recordUsage($credentialIdB64, (int)$signCount);

        return true;
    }

    /**
     * 从 X, Y 坐标构建 ECDSA P-256 PEM 公钥
     */
    private function buildEcdsaPublicKeyPem(string $x, string $y): string {
        // ECDSA P-256 公钥的 ASN.1 结构
        // 参考 RFC 5480
        $point = "\x04" . $x . $y; // 未压缩点格式

        // AlgorithmIdentifier: OID 1.2.840.10045.2.1 (ecPublicKey) + OID 1.2.840.10045.3.1.7 (prime256v1)
        $algorithm = "\x30\x13" .
            "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" . // ecPublicKey
            "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // prime256v1

        $subjectPublicKey = "\x03" . $this->asn1Length(strlen($point) + 1) . "\x00" . $point;
        $spki = "\x30" . $this->asn1Length(strlen($algorithm) + strlen($subjectPublicKey)) .
            $algorithm . $subjectPublicKey;

        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($spki), 64, "\n") .
            "-----END PUBLIC KEY-----";
    }

    private function asn1Length(int $len): string {
        if ($len < 128) {
            return chr($len);
        }
        $bytes = [];
        while ($len > 0) {
            $bytes[] = chr($len & 0xFF);
            $len >>= 8;
        }
        $bytes = array_reverse($bytes);
        return chr(0x80 | count($bytes)) . implode('', $bytes);
    }
}
