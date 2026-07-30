<?php
declare(strict_types=1);

namespace LitePic\Service\Auth\OAuth;

use LitePic\Core\Config;
use LitePic\Core\Session;
use LitePic\Repository\UserOAuthRepository;
use LitePic\Repository\UserRepository;
use LitePic\Service\Auth\UserAuthService;
use LitePic\Service\Auth\UserContext;

/**
 * OAuth 2.0 authorization-code flow for Google and GitHub.
 *
 * Endpoints (dispatched by api/oauth.php):
 *   GET /oauth/<provider>/start     → 302 to the provider consent screen
 *   GET /oauth/<provider>/callback  → exchanges code, resolves/creates the
 *                                     local user, starts the session
 *
 * State is a random token kept in the PHP session (CSRF protection).
 * Identity resolution order on callback:
 *   1. Known (provider, provider_user_id) → log that user in.
 *   2. Unknown identity + open mode      → auto-create a user (username
 *      derived from the profile, deduplicated with a numeric suffix).
 *   3. Unknown identity + invite mode    → a valid invite code must have
 *      been supplied on /start (?invite=...) and is echoed through state.
 *
 * Provider credentials come from settings (设置 → 注册与登录):
 *   OAUTH_GOOGLE_ENABLED / OAUTH_GOOGLE_CLIENT_ID / OAUTH_GOOGLE_CLIENT_SECRET
 *   OAUTH_GITHUB_ENABLED / OAUTH_GITHUB_CLIENT_ID / OAUTH_GITHUB_CLIENT_SECRET
 */
final class OAuthService
{
    private const STATE_SESSION_KEY = 'litepic_oauth_state';
    private const INVITE_SESSION_KEY = 'litepic_oauth_invite';

    /** @var array<string, array{authorize:string, token:string, userinfo:string, scope:string}> */
    private const PROVIDERS = [
        'google' => [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
            'scope' => 'openid email profile',
        ],
        'github' => [
            'authorize' => 'https://github.com/login/oauth/authorize',
            'token' => 'https://github.com/login/oauth/access_token',
            'userinfo' => 'https://api.github.com/user',
            'scope' => 'read:user user:email',
        ],
    ];

    public static function providers(): array
    {
        return array_keys(self::PROVIDERS);
    }

    public static function isValidProvider(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    public static function isEnabled(string $provider): bool
    {
        if (!self::isValidProvider($provider) || !UserContext::enabled()) {
            return false;
        }
        $key = 'OAUTH_' . strtoupper($provider);
        return Config::get($key . '_ENABLED', 'false') === 'true'
            && trim((string)Config::get($key . '_CLIENT_ID', '')) !== ''
            && trim((string)Config::get($key . '_CLIENT_SECRET', '')) !== '';
    }

    /** @return string[] providers that are configured AND enabled */
    public static function enabledProviders(): array
    {
        $out = [];
        foreach (self::providers() as $p) {
            if (self::isEnabled($p)) $out[] = $p;
        }
        return $out;
    }

    public static function callbackUrl(string $provider): string
    {
        $base = rtrim(\LitePic\Core\Config::siteUrl(), '/');
        return $base . '/oauth/' . $provider . '/callback';
    }

    /**
     * Build the provider consent URL and stash CSRF state (+ optional
     * invite code) in the session.
     */
    public function startUrl(string $provider, string $inviteCode = ''): string
    {
        if (!self::isEnabled($provider)) {
            throw new \InvalidArgumentException('该第三方登录未启用');
        }
        Session::start();
        $state = bin2hex(random_bytes(16));
        $_SESSION[self::STATE_SESSION_KEY] = $state;
        $inviteCode = trim($inviteCode);
        if ($inviteCode !== '') {
            $_SESSION[self::INVITE_SESSION_KEY] = $inviteCode;
        } else {
            unset($_SESSION[self::INVITE_SESSION_KEY]);
        }

        $key = 'OAUTH_' . strtoupper($provider);
        $cfg = self::PROVIDERS[$provider];
        $params = [
            'client_id' => trim((string)Config::get($key . '_CLIENT_ID', '')),
            'redirect_uri' => self::callbackUrl($provider),
            'response_type' => 'code',
            'scope' => $cfg['scope'],
            'state' => $state,
        ];
        return $cfg['authorize'] . '?' . http_build_query($params);
    }

    /**
     * Handle the provider redirect back. Returns the logged-in user row.
     * Throws \InvalidArgumentException with a user-facing message on failure.
     *
     * @param array<string,mixed> $query the callback query params ($_GET)
     */
    public function handleCallback(string $provider, array $query): array
    {
        if (!self::isEnabled($provider)) {
            throw new \InvalidArgumentException('该第三方登录未启用');
        }
        Session::start();

        $error = trim((string)($query['error'] ?? ''));
        if ($error !== '') {
            throw new \InvalidArgumentException('第三方登录被取消或失败：' . $error);
        }

        $state = (string)($query['state'] ?? '');
        $expected = (string)($_SESSION[self::STATE_SESSION_KEY] ?? '');
        unset($_SESSION[self::STATE_SESSION_KEY]);
        if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
            throw new \InvalidArgumentException('登录状态校验失败，请重新发起登录');
        }
        $code = trim((string)($query['code'] ?? ''));
        if ($code === '') {
            throw new \InvalidArgumentException('第三方登录未返回授权码');
        }
        $inviteCode = trim((string)($_SESSION[self::INVITE_SESSION_KEY] ?? ''));
        unset($_SESSION[self::INVITE_SESSION_KEY]);

        $accessToken = $this->exchangeCode($provider, $code);
        $profile = $this->fetchProfile($provider, $accessToken);

        $providerUserId = (string)($profile['id'] ?? '');
        if ($providerUserId === '') {
            throw new \InvalidArgumentException('无法获取第三方账号信息');
        }
        $email = (string)($profile['email'] ?? '');
        $displayName = (string)($profile['name'] ?? '');

        $oauth = new UserOAuthRepository();
        $users = new UserRepository();

        // 1. Known identity → straight login.
        $userId = $oauth->findUserId($provider, $providerUserId);
        if ($userId !== null) {
            $user = $users->find($userId);
            if ($user === null) {
                throw new \InvalidArgumentException('绑定的账号不存在');
            }
            if ($user['status'] !== 'active') {
                throw new \InvalidArgumentException('该账号已被停用，请联系管理员');
            }
            return $user;
        }

        // 2. Email matches an existing user → bind this identity to them.
        if ($email !== '') {
            $byEmail = $users->findByEmail($email);
            if ($byEmail !== null) {
                if ($byEmail['status'] !== 'active') {
                    throw new \InvalidArgumentException('该账号已被停用，请联系管理员');
                }
                $oauth->bind((int)$byEmail['id'], $provider, $providerUserId, $email);
                return $byEmail;
            }
        }

        // 3. New user — gated by registration mode.
        if (!UserContext::openRegistration()) {
            if ($inviteCode === '' || !(new \LitePic\Repository\InviteRepository())->redeem($inviteCode)) {
                throw new \InvalidArgumentException('本站为邀请制注册，请从 /oauth/' . $provider . '/start?invite=邀请码 发起登录');
            }
        }

        $username = $this->uniqueUsername($profile, $provider);
        try {
            $id = $users->create([
                'username' => $username,
                'email' => $email,
                'display_name' => $displayName !== '' ? $displayName : $username,
                'password_hash' => '',
                'role' => 'user',
                'quota_bytes' => UserAuthService::defaultQuotaBytes(),
            ]);
        } catch (\Throwable $e) {
            \LitePic\Core\Logger::error('OAuth user creation failed', ['error' => $e->getMessage()]);
            throw new \InvalidArgumentException('创建账号失败，请稍后重试');
        }
        $oauth->bind($id, $provider, $providerUserId, $email);

        $user = $users->find($id);
        if ($user === null) {
            throw new \InvalidArgumentException('创建账号失败，请稍后重试');
        }
        return $user;
    }

    // ------------------------------------------------------------------
    // Provider plumbing
    // ------------------------------------------------------------------

    private function exchangeCode(string $provider, string $code): string
    {
        $key = 'OAUTH_' . strtoupper($provider);
        $cfg = self::PROVIDERS[$provider];
        $response = $this->httpPost($cfg['token'], [
            'client_id' => trim((string)Config::get($key . '_CLIENT_ID', '')),
            'client_secret' => trim((string)Config::get($key . '_CLIENT_SECRET', '')),
            'code' => $code,
            'redirect_uri' => self::callbackUrl($provider),
            'grant_type' => 'authorization_code',
        ], ['Accept: application/json']);

        $data = json_decode($response, true);
        $token = is_array($data) ? (string)($data['access_token'] ?? '') : '';
        if ($token === '') {
            \LitePic\Core\Logger::error('OAuth token exchange failed', [
                'provider' => $provider,
                'body' => substr($response, 0, 500),
            ]);
            throw new \InvalidArgumentException('第三方授权码交换失败');
        }
        return $token;
    }

    /**
     * @return array{id:string, email:string, name:string}
     */
    private function fetchProfile(string $provider, string $accessToken): array
    {
        $cfg = self::PROVIDERS[$provider];
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
            'User-Agent: LitePic-OAuth',
        ];
        $raw = $this->httpGet($cfg['userinfo'], $headers);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('无法读取第三方账号信息');
        }

        if ($provider === 'google') {
            return [
                'id' => (string)($data['sub'] ?? ''),
                'email' => (string)($data['email'] ?? ''),
                'name' => (string)($data['name'] ?? ''),
            ];
        }

        // GitHub: /user may hide the email — fall back to /user/emails.
        $email = (string)($data['email'] ?? '');
        if ($email === '') {
            $rawEmails = $this->httpGet('https://api.github.com/user/emails', $headers);
            $emails = json_decode($rawEmails, true);
            if (is_array($emails)) {
                foreach ($emails as $e) {
                    if (is_array($e) && !empty($e['primary']) && !empty($e['verified'])) {
                        $email = (string)($e['email'] ?? '');
                        break;
                    }
                }
                if ($email === '') {
                    foreach ($emails as $e) {
                        if (is_array($e) && !empty($e['verified'])) {
                            $email = (string)($e['email'] ?? '');
                            break;
                        }
                    }
                }
            }
        }
        return [
            'id' => isset($data['id']) ? (string)$data['id'] : '',
            'email' => $email,
            'name' => (string)($data['name'] ?? '') !== '' ? (string)$data['name'] : (string)($data['login'] ?? ''),
        ];
    }

    /**
     * Derive a unique local username from the OAuth profile.
     *
     * @param array{id:string,email:string,name:string} $profile
     */
    private function uniqueUsername(array $profile, string $provider): string
    {
        $users = new UserRepository();
        $base = '';
        if ($profile['email'] !== '') {
            $base = strtolower((string)strtok($profile['email'], '@'));
        }
        if ($base === '' && $profile['name'] !== '') {
            $base = strtolower((string)preg_replace('/\s+/', '', $profile['name']));
        }
        $base = (string)preg_replace('/[^a-z0-9_.-]/', '', $base);
        $base = trim($base, '_.-');
        if (strlen($base) < 2) {
            $base = $provider . '_user';
        }
        $base = substr($base, 0, 24);
        if (strtolower($base) === 'admin') {
            $base = $base . '_oauth';
        }

        $candidate = $base;
        for ($i = 2; $i <= 99; $i++) {
            if ($users->findByUsername($candidate) === null) {
                return $candidate;
            }
            $candidate = $base . $i;
        }
        return $base . '_' . bin2hex(random_bytes(3));
    }

    private function httpPost(string $url, array $params, array $headers): string
    {
        return $this->http('POST', $url, http_build_query($params), array_merge(
            ['Content-Type: application/x-www-form-urlencoded'],
            $headers
        ));
    }

    private function httpGet(string $url, array $headers): string
    {
        return $this->http('GET', $url, null, $headers);
    }

    private function http(string $method, string $url, ?string $body, array $headers): string
    {
        $headers[] = 'User-Agent: LitePic-OAuth';
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $result = @file_get_contents($url, false, $context);
        if (!is_string($result)) {
            throw new \InvalidArgumentException('无法连接第三方登录服务');
        }
        return $result;
    }
}
