<?php

namespace App\Services;

use Illuminate\Support\Facades\URL;

/**
 * Turns the plain template text an owner edits into a branded HTML email plus a plain-text copy.
 *
 * Template text rules:
 *  - a blank line starts a new paragraph
 *  - lines that start with "- " become a bullet list
 *  - "# Heading" on the first line becomes the headline
 *  - "[button: Label | https://link]" on its own line becomes a button
 * Mail is sent as HTML and text together, from one clear sender, with a signature and (for marketing) a one-click unsubscribe.
 */
class EmailRenderer
{
    /** Triggers that are marketing: they need the person's consent and always carry an unsubscribe link. */
    public const MARKETING = ['nurture', 'blog_published', 'abandoned_purchase', 'monthly_digest', 'campaign'];

    public static function isMarketing(string $trigger): bool
    {
        return in_array($trigger, self::MARKETING, true);
    }

    /** A campaign id is carried in the signed link so the campaign report can count who unsubscribed from it. */
    public static function unsubscribeUrl(int $userId, ?string $campaignId = null): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $userId] + ($campaignId ? ['c' => $campaignId] : []));
    }

    /** Imported contacts have no account: their link carries their own random token instead of a signature. */
    public static function contactUnsubscribeUrl(string $token, ?string $campaignId = null): string
    {
        return rtrim((string) config('app.url'), '/').'/email/unsubscribe/c/'.$token.($campaignId ? '?c='.$campaignId : '');
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array{html:string,text:string} */
    public static function render(string $subject, string $body, bool $marketing = false, ?int $userId = null, string $preheader = '', ?string $unsubUrl = null): array
    {
        $site = rtrim((string) config('app.url'), '/');
        $support = 'support@cvtara.com';
        $lines = preg_split('/\R/', trim($body));
        $headline = '';
        if ($lines && str_starts_with($lines[0], '# ')) {
            $headline = trim(substr(array_shift($lines), 2));
        }
        $blocks = [];
        $para = [];
        $list = [];
        $flush = function () use (&$blocks, &$para, &$list) {
            if ($para) {
                $blocks[] = ['p', implode("\n", $para)];
                $para = [];
            }
            if ($list) {
                $blocks[] = ['ul', $list];
                $list = [];
            }
        };
        foreach ($lines as $line) {
            $t = rtrim($line);
            if (trim($t) === '') {
                $flush();
            } elseif (preg_match('/^\[code:\s*(\S+?)\s*\]$/i', trim($t), $m)) {
                $flush();
                $blocks[] = ['code', $m[1]];
            } elseif (preg_match('/^##\s+(.+)$/', trim($t), $m)) {
                $flush();
                $blocks[] = ['h2', $m[1]];
            } elseif (preg_match('/^\[button:\s*(.+?)\s*\|\s*(https?:\/\/\S+?)\s*\]$/i', trim($t), $m)) {
                $flush();
                $blocks[] = ['btn', [$m[1], $m[2]]];
            } elseif (preg_match('/^-\s+(.+)$/', $t, $m)) {
                if ($para) {
                    $blocks[] = ['p', implode("\n", $para)];
                    $para = [];
                }
                $list[] = $m[1];
            } else {
                if ($list) {
                    $blocks[] = ['ul', $list];
                    $list = [];
                }
                $para[] = $t;
            }
        }
        $flush();

        $html = '';
        $text = ($headline !== '' ? strtoupper($headline)."\n\n" : '');
        foreach ($blocks as [$kind, $val]) {
            if ($kind === 'p') {
                $html .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.65;color:#1e293b">'.nl2br(self::e($val)).'</p>';
                $text .= $val."\n\n";
            } elseif ($kind === 'ul') {
                $html .= '<ul style="margin:0 0 18px;padding:0 0 0 20px;font-size:16px;line-height:1.7;color:#1e293b">'.implode('', array_map(fn ($i) => '<li style="margin:0 0 6px">'.self::e($i).'</li>', $val)).'</ul>';
                $text .= implode("\n", array_map(fn ($i) => '- '.$i, $val))."\n\n";
            } elseif ($kind === 'code') {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:6px 0 22px"><tr><td align="center" style="background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:18px 10px;font-family:Consolas,Menlo,monospace;font-size:34px;font-weight:800;letter-spacing:.35em;color:#1e1b4b">'.self::e($val).'</td></tr></table>';
                $text .= 'YOUR CODE: '.$val."\n\n";
            } elseif ($kind === 'h2') {
                $html .= '<h2 style="margin:6px 0 8px;font-size:21px;line-height:1.3;letter-spacing:-.01em;color:#0f172a">'.self::e($val).'</h2>';
                $text .= strtoupper($val)."\n";
            } else {
                [$label, $url] = $val;
                $html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 22px"><tr><td style="border-radius:10px;background:#1557d2"><a href="'.self::e($url).'" style="display:inline-block;padding:14px 26px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px">'.self::e($label).'</a></td></tr></table>';
                $text .= $label.': '.$url."\n\n";
            }
        }

        $footerReason = $marketing
            ? ($userId || ! $unsubUrl ? 'You are receiving this because you have a CVTara account and chose to get tips and plan updates.' : 'You are receiving this because you agreed to hear from CVTara.')
            : 'You are receiving this because of activity on your CVTara account.';
        $unsub = '';
        $unsubText = '';
        if ($marketing && ($unsubUrl || $userId)) {
            $u = $unsubUrl ?: self::unsubscribeUrl($userId);
            $unsub = '<br><a href="'.self::e($u).'" style="color:#64748b;text-decoration:underline">Unsubscribe</a> from these emails any time.';
            $unsubText = "\nUnsubscribe from these emails any time: ".$u."\n";
        }
        $sigHtml = '<p style="margin:26px 0 0;font-size:16px;line-height:1.6;color:#1e293b">Warm regards,<br><strong>Team CVTara</strong><br><span style="color:#64748b;font-size:14px">Resumes and cover letters by an HR team in Bangalore, India<br><a href="mailto:'.$support.'" style="color:#1557d2;text-decoration:none">'.$support.'</a> · <a href="'.self::e($site).'" style="color:#1557d2;text-decoration:none">cvtara.com</a></span></p>';
        $pre = $preheader !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">'.self::e($preheader).'</div>' : '';

        $page = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>'.self::e($subject).'</title></head>'
            .'<body style="margin:0;padding:0;background:#f1f5f9;font-family:Inter,Segoe UI,Helvetica,Arial,sans-serif">'.$pre
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9"><tr><td align="center" style="padding:28px 14px">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
            .'<tr><td bgcolor="#1e1b4b" style="background-color:#1e1b4b;background-image:linear-gradient(135deg,#1e1b4b,#3730a3);padding:22px 32px"><a href="'.self::e($site).'" style="font-size:24px;font-weight:800;letter-spacing:-.02em;color:#ffffff;text-decoration:none">CV<span style="color:#a5b4fc">Tara</span></a></td></tr>'
            .'<tr><td style="padding:32px 32px 30px">'.($headline !== '' ? '<h1 style="margin:0 0 18px;font-size:26px;line-height:1.2;letter-spacing:-.02em;color:#0f172a">'.self::e($headline).'</h1>' : '').$html.$sigHtml.'</td></tr>'
            .'<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 32px;font-size:12.5px;line-height:1.6;color:#64748b">'.$footerReason.$unsub.'<br>CVTara · Bangalore, Karnataka, India · Need help? Write to <a href="mailto:'.$support.'" style="color:#64748b">'.$support.'</a>. Please do not share your password or one-time codes with anyone.</td></tr>'
            .'</table></td></tr></table></body></html>';

        $text .= "Warm regards,\nTeam CVTara\nResumes and cover letters by an HR team in Bangalore, India\n".$support.' · '.$site."\n\n--\n".$footerReason.$unsubText."CVTara · Bangalore, Karnataka, India\n";

        return ['html' => $page, 'text' => $text];
    }
}
