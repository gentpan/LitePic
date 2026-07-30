<?php
declare(strict_types=1);

namespace LitePic\Service\Mail;

use LitePic\Core\Config;

/**
 * Minimal dependency-free SMTP client.
 *
 * Reads its configuration from the settings table (editable in 设置 → 邮件):
 *   SMTP_HOST / SMTP_PORT / SMTP_USERNAME / SMTP_PASSWORD
 *   SMTP_ENCRYPTION  none | ssl | starttls
 *   SMTP_FROM_EMAIL / SMTP_FROM_NAME
 *
 * Supports AUTH LOGIN over plain, ssl://, or STARTTLS-upgraded sockets and
 * sends UTF-8 multipart/alternative (text + HTML) mail. No composer
 * dependency — matches the project's zero-dependency philosophy.
 */
final class SmtpMailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private string $fromEmail;
    private string $fromName;

    /** @var resource|null */
    private $socket = null;

    public function __construct()
    {
        $this->host = trim((string)Config::get('SMTP_HOST', ''));
        $this->port = max(1, (int)Config::get('SMTP_PORT', '465'));
        $this->username = trim((string)Config::get('SMTP_USERNAME', ''));
        $this->password = (string)Config::get('SMTP_PASSWORD', '');
        $this->encryption = strtolower(trim((string)Config::get('SMTP_ENCRYPTION', 'ssl')));
        if (!in_array($this->encryption, ['none', 'ssl', 'starttls'], true)) {
            $this->encryption = 'ssl';
        }
        $this->fromEmail = trim((string)Config::get('SMTP_FROM_EMAIL', ''));
        $this->fromName = trim((string)Config::get('SMTP_FROM_NAME', ''));
    }

    public function isConfigured(): bool
    {
        return $this->host !== '' && $this->fromEmail !== '';
    }

    /**
     * Send one message. Throws \RuntimeException with a readable message on
     * any transport or protocol failure.
     */
    public function send(string $toEmail, string $subject, string $textBody, string $htmlBody = ''): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SMTP 未配置（请先在 设置 → 邮件 中填写服务器信息）');
        }
        $toEmail = trim($toEmail);
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('收件人邮箱格式不正确');
        }

        $remote = ($this->encryption === 'ssl' ? 'ssl://' : '') . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $this->socket = @fsockopen($remote, $this->port, $errno, $errstr, 15);
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('无法连接 SMTP 服务器：' . ($errstr !== '' ? $errstr : ('错误 ' . $errno)));
        }
        stream_set_timeout($this->socket, 20);

        try {
            $this->expect([220]);

            $ehlo = $this->serverIdentity();
            $this->command('EHLO ' . $ehlo, [250]);

            if ($this->encryption === 'starttls') {
                $this->command('STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    throw new \RuntimeException('STARTTLS 加密握手失败');
                }
                // RFC 3207: re-EHLO after TLS upgrade.
                $this->command('EHLO ' . $ehlo, [250]);
            }

            if ($this->username !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($this->username), [334]);
                $this->command(base64_encode($this->password), [235]);
            }

            $this->command('MAIL FROM:<' . $this->fromEmail . '>', [250]);
            $this->command('RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command('DATA', [354]);

            $message = $this->buildMessage($toEmail, $subject, $textBody, $htmlBody);
            // Dot-stuffing + terminating CRLF.CRLF
            $message = preg_replace('/\r?\n\./', "\r\n..", $message);
            $this->write($message . "\r\n.\r\n");
            $this->expect([250]);

            $this->command('QUIT', [221]);
        } finally {
            if (is_resource($this->socket)) {
                fclose($this->socket);
                $this->socket = null;
            }
        }
    }

    /** Convenience: send a test message to the given address. */
    public function sendTest(string $toEmail): void
    {
        $site = (string)Config::get('SITE_NAME', 'LitePic');
        $this->send(
            $toEmail,
            '[' . $site . '] SMTP 测试邮件',
            "这是一封来自 {$site} 的 SMTP 测试邮件。\n如果你收到它，说明邮件配置工作正常。",
            '<p>这是一封来自 <strong>' . htmlspecialchars($site) . '</strong> 的 SMTP 测试邮件。</p>'
            . '<p>如果你收到它，说明邮件配置工作正常。</p>'
        );
    }

    // ------------------------------------------------------------------
    // Invite mail
    // ------------------------------------------------------------------

    public function sendInvite(string $toEmail, string $inviteUrl, string $note = ''): void
    {
        $site = (string)Config::get('SITE_NAME', 'LitePic');
        $noteHtml = $note !== ''
            ? '<p style="color:#555">邀请备注：' . htmlspecialchars($note) . '</p>'
            : '';
        $this->send(
            $toEmail,
            '[' . $site . '] 邀请你注册',
            "你被邀请注册 {$site}。\n\n打开下面的链接完成注册：\n{$inviteUrl}\n",
            '<p>你被邀请注册 <strong>' . htmlspecialchars($site) . '</strong>。</p>'
            . $noteHtml
            . '<p><a href="' . htmlspecialchars($inviteUrl, ENT_QUOTES) . '" '
            . 'style="display:inline-block;padding:10px 20px;background:#0052D9;color:#fff;'
            . 'text-decoration:none;border-radius:6px">点击完成注册</a></p>'
            . '<p style="color:#888;font-size:12px">如果按钮无法点击，复制此链接到浏览器打开：<br>'
            . htmlspecialchars($inviteUrl) . '</p>'
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function buildMessage(string $toEmail, string $subject, string $textBody, string $htmlBody): string
    {
        $fromName = $this->fromName !== '' ? $this->fromName : $this->fromEmail;
        $encodedFromName = self::encodeHeader($fromName);
        $encodedSubject = self::encodeHeader($subject);
        $boundary = '=_litepic_' . bin2hex(random_bytes(12));

        $headers = [
            'From: ' . $encodedFromName . ' <' . $this->fromEmail . '>',
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->serverIdentity() . '>',
            'MIME-Version: 1.0',
        ];

        if ($htmlBody !== '') {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $body = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($textBody))
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($htmlBody))
                . '--' . $boundary . "--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($textBody));
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private static function encodeHeader(string $value): string
    {
        if ($value === '' || preg_match('/^[\x20-\x7E]+$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function serverIdentity(): string
    {
        $host = (string)($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        }
        $host = preg_replace('/:\d+$/', '', $host);
        return $host !== '' ? $host : 'localhost';
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP 连接已断开');
        }
        $written = @fwrite($this->socket, $data);
        if ($written === false) {
            throw new \RuntimeException('SMTP 写入失败');
        }
    }

    /**
     * Send a command and assert the reply code. Single-line commands only —
     * multi-line replies (EHLO) are drained until the final "250 " line.
     */
    private function command(string $line, array $expectCodes): string
    {
        $this->write($line . "\r\n");
        return $this->expect($expectCodes);
    }

    private function expect(array $codes): string
    {
        $reply = '';
        while (is_resource($this->socket) && !feof($this->socket)) {
            $line = fgets($this->socket, 515);
            if ($line === false) break;
            $reply .= $line;
            // "250-..." continues; "250 ..." (space) ends the reply.
            if (preg_match('/^\d{3} /', $line) === 1) break;
        }
        $code = strlen($reply) >= 3 ? (int)substr($reply, 0, 3) : 0;
        if (!in_array($code, $codes, true)) {
            throw new \RuntimeException('SMTP 服务器返回异常（' . $code . '）：' . trim(substr($reply, 4)));
        }
        return $reply;
    }
}
