<?php

namespace App\Services;

/**
 * 3.75.0 Reads one email (RFC 5322 / MIME) well enough for support: decoded headers, the sender, the subject, the plain
 * text (or the HTML turned into text), attachment names, and the new part of a reply without the quoted older mail.
 */
class MimeMessage
{
    private array $headers = [];

    private string $text = '';

    private string $html = '';

    private array $files = [];

    public static function parse(string $raw): self
    {
        $m = new self;
        [$h, $body] = self::split($raw);
        $m->headers = $h;
        $m->walk($h, $body, 0);

        return $m;
    }

    /** @return array{0: array<string,string>, 1: string} headers (lower-case names, first occurrence, decoded) and body */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = preg_replace("/\n[ \t]+/", ' ', $head);
        $h = [];
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $x)) {
                $k = strtolower($x[1]);
                if (! isset($h[$k])) {
                    $h[$k] = self::decodeHeader(trim($x[2]));
                } elseif ($k === 'references' || $k === 'received') {
                    $h[$k] .= ' '.trim($x[2]);
                }
            }
        }

        return [$h, $body];
    }

    private static function decodeHeader(string $v): string
    {
        if (! str_contains($v, '=?')) {
            return $v;
        }
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $d !== false ? $d : (mb_decode_mimeheader($v) ?: $v);
    }

    private static function param(string $header, string $name): string
    {
        return preg_match('/(?:^|;)\s*'.$name.'\*?=\s*(?:"([^"]*)"|([^;\s]+))/i', $header, $m) ? ($m[1] !== '' ? $m[1] : $m[2]) : '';
    }

    private function walk(array $h, string $body, int $depth): void
    {
        $type = strtolower(trim(strtok(($h['content-type'] ?? 'text/plain') ?: 'text/plain', ';')));
        $disp = strtolower($h['content-disposition'] ?? '');
        if (str_starts_with($type, 'multipart/') && $depth < 6) {
            $b = self::param($h['content-type'] ?? '', 'boundary');
            if ($b === '') {
                return;
            }
            $parts = preg_split('/^--'.preg_quote($b, '/').'(?:--)?[ \t]*$/m', str_replace("\r\n", "\n", $body));
            foreach (array_slice($parts, 1) as $p) {
                $p = ltrim($p, "\n");
                if (trim($p) === '') {
                    continue;
                }
                [$ph, $pb] = self::split($p);
                $this->walk($ph, $pb, $depth + 1);
            }

            return;
        }
        $name = self::param($h['content-disposition'] ?? '', 'filename') ?: self::param($h['content-type'] ?? '', 'name');
        if (str_starts_with($disp, 'attachment') || ($name !== '' && ! in_array($type, ['text/plain', 'text/html'], true))) {
            $this->files[] = mb_substr(self::decodeHeader($name) ?: $type, 0, 80);

            return;
        }
        if ($type !== 'text/plain' && $type !== 'text/html') {
            return;
        }
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        $data = match ($enc) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body)),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
        $cs = strtoupper(self::param($h['content-type'] ?? '', 'charset') ?: 'UTF-8');
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') {
            $conv = @mb_convert_encoding($data, 'UTF-8', $cs);
            $data = is_string($conv) ? $conv : $data;
        }
        $data = mb_scrub($data, 'UTF-8');
        if ($type === 'text/plain' && $this->text === '') {
            $this->text = $data;
        } elseif ($type === 'text/html' && $this->html === '') {
            $this->html = $data;
        }
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function subject(): string
    {
        return trim(preg_replace('/\s+/', ' ', $this->header('subject')));
    }

    public function fromAddress(): string
    {
        $f = $this->header('from');

        return preg_match('/<([^<>\s]+@[^<>\s]+)>/', $f, $m) ? mb_strtolower($m[1]) : (preg_match('/[^\s<>"]+@[^\s<>"]+/', $f, $m) ? mb_strtolower(trim($m[0], '.,;')) : '');
    }

    public function fromName(): string
    {
        $f = $this->header('from');

        return preg_match('/^\s*"?([^"<]*?)"?\s*</', $f, $m) ? trim($m[1]) : '';
    }

    /** @return string[] */
    public function attachments(): array
    {
        return $this->files;
    }

    /** The full text: the plain part, or the HTML part turned into text. */
    public function text(): string
    {
        $t = $this->text !== '' ? $this->text : self::htmlToText($this->html);

        return trim(preg_replace("/\n{3,}/", "\n\n", str_replace(["\r\n", "\r"], "\n", $t)));
    }

    private static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
        $html = preg_replace('#<blockquote\b.*?</blockquote>#is', "\n> (quoted)\n", $html);
        $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', "\n", $html);
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace("/[ \t]+/", ' ', $t);
    }

    /** The new part of a reply: the quoted older mail and the signature separator onwards are left out. */
    public function replyText(): string
    {
        $lines = explode("\n", $this->text());
        $out = [];
        foreach ($lines as $i => $l) {
            $t = trim($l);
            $next = trim($lines[$i + 1] ?? '');
            if (preg_match('/^On .{6,200}wrote:$/i', $t) || (preg_match('/^On .{6,200}$/i', $t) && preg_match('/wrote:$/i', $next) && mb_strlen($t.$next) < 260)
                || preg_match('/^-{2,}\s*(Original Message|Forwarded message)\s*-{2,}$/i', $t) || $t === '-- '
                || (preg_match('/^From: .+/i', $t) && preg_match('/^(Sent|Date|To): /i', $next))
                || preg_match('/^_{10,}$/', $t)) {
                break;
            }
            if (str_starts_with($t, '>')) {
                continue;
            }
            $out[] = rtrim($l);
        }
        $r = trim(implode("\n", $out));

        return mb_substr($r !== '' ? $r : $this->text(), 0, 5000);
    }
}
