<?php
declare(strict_types=1);

namespace App\Support;

final class Mailer
{
    public static function send(string $to, string $subject, string $html, ?array $mailbox = null, array $headers = []): bool
    {
        $box = $mailbox ?? self::transactional();
        if (!$box) {
            Logger::error('No mailbox configured for outbound mail', ['to' => $to, 'subject' => $subject]);
            return false;
        }
        $host = $box['host'] ?? Env::get('MAIL_HOST', '');
        $port = (int) ($box['port'] ?? Env::get('MAIL_PORT', '587'));
        $user = $box['username'] ?? Env::get('MAIL_USERNAME', '');
        $pass = $box['password'] ?? Env::get('MAIL_PASSWORD', '');
        $from = $box['from_email'] ?? Env::get('MAIL_FROM_ADDRESS', 'hello@localhost');
        $name = $box['from_name'] ?? Env::get('MAIL_FROM_NAME', 'CVTara');
        $enc = strtolower((string) ($box['encryption'] ?? Env::get('MAIL_ENCRYPTION', 'tls')));

        if ($host === '' || $user === '') {
            Logger::error('SMTP host or username missing', ['to' => $to]);
            return false;
        }

        $boundary = 'cvt' . bin2hex(random_bytes(8));
        $body = "From: {$name} <{$from}>\r\n";
        $body .= "To: {$to}\r\n";
        $body .= "Subject: {$subject}\r\n";
        $body .= "MIME-Version: 1.0\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        foreach ($headers as $k => $v) {
            $body .= "$k: $v\r\n";
        }
        $body .= "\r\n" . $html;

        $remote = ($enc === 'ssl' || $port === 465 ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            Logger::error('SMTP connect failed', ['error' => $errstr, 'host' => $host]);
            return false;
        }
        stream_set_timeout($fp, 20);
        $read = fn () => fgets($fp, 2048);
        $cmd = function (string $line) use ($fp) {
            fwrite($fp, $line . "\r\n");
            return fgets($fp, 2048);
        };
        $read();
        $cmd('EHLO cvtara');
        if ($enc === 'tls' && $port !== 465) {
            $cmd('STARTTLS');
            stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $cmd('EHLO cvtara');
        }
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($user));
        $auth = $cmd(base64_encode($pass));
        if (!str_starts_with((string) $auth, '235')) {
            fclose($fp);
            Logger::error('SMTP authentication failed', ['user' => $user]);
            return false;
        }
        $cmd("MAIL FROM:<{$from}>");
        $rcpt = $cmd("RCPT TO:<{$to}>");
        if (!str_starts_with((string) $rcpt, '250') && !str_starts_with((string) $rcpt, '251')) {
            fclose($fp);
            Logger::error('SMTP recipient rejected', ['to' => $to]);
            return false;
        }
        $cmd('DATA');
        fwrite($fp, $body . "\r\n.\r\n");
        $ok = str_starts_with((string) $read(), '250');
        $cmd('QUIT');
        fclose($fp);
        if (!$ok) {
            Logger::error('SMTP data rejected', ['to' => $to, 'subject' => $subject]);
        }
        return $ok;
    }

    public static function transactional(): ?array
    {
        $row = DB::fetch("SELECT * FROM mailboxes WHERE purpose = 'transactional' AND enabled = 1 ORDER BY id ASC LIMIT 1");
        return $row ? self::hydrate($row) : self::envMailbox('transactional');
    }

    public static function nextMarketing(): ?array
    {
        $row = DB::fetch("SELECT * FROM mailboxes WHERE purpose = 'marketing' AND enabled = 1 ORDER BY last_used_at IS NULL DESC, last_used_at ASC, id ASC LIMIT 1");
        if (!$row) {
            return self::envMailbox('marketing');
        }
        DB::update('mailboxes', ['last_used_at' => now()], 'id = ?', [$row['id']]);
        return self::hydrate($row);
    }

    public static function hydrate(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'label' => $row['label'] ?? 'Mailbox',
            'host' => $row['host'],
            'port' => $row['port'],
            'username' => $row['username'],
            'password' => Crypto::decrypt((string) $row['password_enc']),
            'encryption' => $row['encryption'],
            'from_name' => $row['from_name'],
            'from_email' => $row['from_email'],
        ];
    }

    private static function envMailbox(string $purpose): ?array
    {
        if (!Env::get('MAIL_HOST')) {
            return null;
        }
        return [
            'id' => null,
            'label' => 'Environment ' . $purpose,
            'host' => Env::get('MAIL_HOST'),
            'port' => Env::get('MAIL_PORT', '587'),
            'username' => Env::get('MAIL_USERNAME'),
            'password' => Env::get('MAIL_PASSWORD'),
            'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),
            'from_name' => Env::get('MAIL_FROM_NAME', 'CVTara'),
            'from_email' => Env::get('MAIL_FROM_ADDRESS'),
        ];
    }

    public static function wrap(string $title, string $body, string $footer = ''): string
    {
        return '<div style="font-family:Inter,Arial,sans-serif;background:#f8fafc;padding:24px;color:#0f172a">'
            . '<div style="max-width:560px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px">'
            . '<h1 style="margin:0 0 12px;font-size:22px;color:#0f172a">' . e($title) . '</h1>'
            . '<div style="color:#334155;line-height:1.6">' . $body . '</div>'
            . ($footer !== '' ? '<p style="margin-top:24px;font-size:12px;color:#64748b">' . $footer . '</p>' : '')
            . '</div></div>';
    }
}
