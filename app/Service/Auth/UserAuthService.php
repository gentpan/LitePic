<?php
declare(strict_types=1);

namespace LitePic\Service\Auth;

use LitePic\Core\Config;
use LitePic\Repository\InviteRepository;
use LitePic\Repository\UserRepository;

/**
 * Registration and password login for regular (non-admin) users.
 *
 * Admin keeps the legacy master-password flow in api/auth.php; this service
 * only handles accounts stored in the `users` table. All validation errors
 * surface as \InvalidArgumentException with a user-facing message; callers
 * convert that into a 400 response.
 */
final class UserAuthService
{
    private UserRepository $users;

    public function __construct(?UserRepository $users = null)
    {
        $this->users = $users ?? new UserRepository();
    }

    /**
     * Register a new account. Enforces the site registration mode:
     *   - off    → always refused
     *   - invite → a redeemable invite code is required (spent atomically
     *              only after every other check has passed)
     *   - open   → invite code optional
     *
     * @return array<string,mixed> the created user row
     */
    public function register(string $username, string $password, string $email = '', string $inviteCode = ''): array
    {
        if (!UserContext::enabled()) {
            throw new \InvalidArgumentException('本站未开放注册');
        }

        $username = trim($username);
        $email = trim($email);
        $inviteCode = trim($inviteCode);

        $this->assertValidUsername($username);
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('密码至少 8 位');
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('邮箱格式不正确');
        }

        if ($this->users->findByUsername($username) !== null) {
            throw new \InvalidArgumentException('该用户名已被使用');
        }
        if ($email !== '' && $this->users->findByEmail($email) !== null) {
            throw new \InvalidArgumentException('该邮箱已注册过账号');
        }

        $invites = new InviteRepository();
        $needsInvite = UserContext::mode() === 'invite';
        if ($needsInvite) {
            if ($inviteCode === '') {
                throw new \InvalidArgumentException('本站为邀请制注册，请输入邀请码');
            }
            if (!$invites->isRedeemable($inviteCode)) {
                throw new \InvalidArgumentException('邀请码无效、已用完或已过期');
            }
        } elseif ($inviteCode !== '' && !$invites->isRedeemable($inviteCode)) {
            throw new \InvalidArgumentException('邀请码无效、已用完或已过期');
        }

        // Spend the invite first in open-mode-with-code / invite mode, then
        // create the user. If user creation somehow fails (race on username),
        // the spent invite is collateral damage — acceptable and logged.
        if ($inviteCode !== '') {
            if (!$invites->redeem($inviteCode)) {
                throw new \InvalidArgumentException('邀请码刚刚被用完了，请换一个');
            }
        }

        try {
            $id = $this->users->create([
                'username' => $username,
                'email' => $email,
                'display_name' => $username,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'role' => 'user',
                'quota_bytes' => self::defaultQuotaBytes(),
            ]);
        } catch (\Throwable $e) {
            \LitePic\Core\Logger::error('User registration failed', ['error' => $e->getMessage()]);
            throw new \InvalidArgumentException('注册失败，请稍后重试');
        }

        $user = $this->users->find($id);
        if ($user === null) {
            throw new \InvalidArgumentException('注册失败，请稍后重试');
        }
        return $user;
    }

    /**
     * Username + password login for regular users. Returns the user row.
     * The administrator (user id 1, empty password_hash) can never log in
     * through this path — admin uses the master-password login instead.
     */
    public function login(string $username, string $password): array
    {
        if (!UserContext::enabled()) {
            throw new \InvalidArgumentException('本站未开启多用户');
        }

        $user = $this->users->findByUsername(trim($username));
        if ($user === null && str_contains(trim($username), '@')) {
            $user = $this->users->findByEmail(trim($username));
        }
        if ($user === null
            || (int)$user['id'] === 1
            || (string)$user['password_hash'] === ''
            || !password_verify($password, (string)$user['password_hash'])) {
            throw new \InvalidArgumentException('用户名或密码不正确');
        }
        if ($user['status'] !== 'active') {
            throw new \InvalidArgumentException('该账号已被停用，请联系管理员');
        }
        return $user;
    }

    public function changePassword(int $userId, string $current, string $next): void
    {
        $user = $this->users->find($userId);
        if ($user === null || (int)$user['id'] === 1) {
            throw new \InvalidArgumentException('账号不存在');
        }
        $hash = (string)$user['password_hash'];
        if ($hash !== '' && !password_verify($current, $hash)) {
            throw new \InvalidArgumentException('当前密码不正确');
        }
        if (strlen($next) < 8) {
            throw new \InvalidArgumentException('新密码至少 8 位');
        }
        $this->users->update($userId, ['password_hash' => password_hash($next, PASSWORD_BCRYPT)]);
    }

    /** Default quota for newly registered users, from settings. 0 = unlimited. */
    public static function defaultQuotaBytes(): int
    {
        $mb = (int)Config::get('REGISTRATION_DEFAULT_QUOTA_MB', '0');
        return $mb > 0 ? $mb * 1024 * 1024 : 0;
    }

    private function assertValidUsername(string $username): void
    {
        if ($username === '') {
            throw new \InvalidArgumentException('请输入用户名');
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{1,31}$/', $username) !== 1) {
            throw new \InvalidArgumentException('用户名需 2-32 位，以字母或数字开头，可含 _ . -');
        }
        if (strtolower($username) === 'admin') {
            throw new \InvalidArgumentException('该用户名为保留账号');
        }
    }
}
