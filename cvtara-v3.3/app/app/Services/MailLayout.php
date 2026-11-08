<?php

namespace App\Services;

/**
 * The one email design every CVTara mail uses: account mail, reminders, tips, campaigns and the outreach card.
 *
 * Content is written in the simple template text the owner edits. blocks() reads it, html() and text() turn it into the
 * two parts of the mail, and page() puts the HTML into the shared frame: wordmark, one white card with a thin brand line,
 * and a footer with the sender's identity and (for marketing) the unsubscribe link. Table layout and inline styles so it
 * looks the same in Gmail, Outlook and phone apps; 600px wide, full width on a phone; readable in dark mode.
 *
 * Any new mail (for example a "your review is ready" notice) only needs a template in defaults.json and a
 * MailAutomation::queue() call; it gets this design, the text part and the footer automatically.
 *
 * Template text:
 *   # Heading              on the first line: the headline
 *   ## Subheading
 *   - item                 bullet list ("- Label: value" lines become a neat details table)
 *   [button: Label | https://link]   the main action; use one per mail
 *   [code: 123456]         a one-time code in a box
 *   [note: text]           a small highlighted note
 *   [plans]                the packages on sale now, with live prices from Pricing
 *   [offer]                the offer running now in Offers & festivals; nothing when no offer is live
 *   [preheader: text]      the preview line mail apps show after the subject (not shown in the mail itself)
 * A paragraph or bullet that is empty once the placeholders are filled is left out, so optional lines can be written
 * as a placeholder on a line of their own.
 */
class MailLayout
{
    public const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /** Light colours; the dark-mode style block in page() swaps them where the mail app allows. */
    public const C = ['page' => '#f3f5f9', 'card' => '#ffffff', 'line' => '#e5e9f0', 'ink' => '#0f172a', 'text' => '#334155', 'muted' => '#64748b', 'accent' => '#1557d2', 'accent2' => '#4f46e5', 'tint' => '#f1f5ff', 'tintLine' => '#d6e0ff', 'warm' => '#fff7ed', 'warmLine' => '#fed7aa', 'warmInk' => '#9a3412'];

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @return array{headline:string,preheader:string,blocks:array<int,array>} */
    public static function blocks(string $body): array
    {
        $lines = preg_split('/\R/', trim(str_replace("\r", '', $body)));
        $headline = '';
        $preheader = '';
        if ($lines && str_starts_with($lines[0], '# ')) {
            $headline = trim(substr(array_shift($lines), 2));
        }
        $blocks = [];
        $para = [];
        $list = [];
        $flushPara = function () use (&$blocks, &$para) {
            $p = array_values(array_filter($para, fn ($l) => trim($l) !== ''));
            if ($p) {
                $blocks[] = ['p', implode("\n", $p)];
            }
            $para = [];
        };
        $flushList = function () use (&$blocks, &$list) {
            $items = array_values(array_filter(array_map('trim', $list), fn ($i) => $i !== '' && ! preg_match('/^[^:]{1,40}:\s*$/', $i)));
            if ($items) {
                $blocks[] = ['ul', $items];
            }
            $list = [];
        };
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                $flushPara();
                $flushList();
            } elseif (preg_match('/^\[preheader:\s*(.*?)\s*\]$/i', $t, $m)) {
                $preheader = $m[1];
            } elseif (preg_match('/^\[code:\s*(\S+?)\s*\]$/i', $t, $m)) {
                $flushPara();
                $flushList();
                $blocks[] = ['code', $m[1]];
            } elseif (preg_match('/^\[note:\s*(.*?)\s*\]$/i', $t, $m)) {
                $flushPara();
                $flushList();
                if ($m[1] !== '') {
                    $blocks[] = ['note', $m[1]];
                }
            } elseif (preg_match('/^\[(plans|offer)\]$/i', $t, $m)) {
                $flushPara();
                $flushList();
                $blocks[] = [strtolower($m[1]), null];
            } elseif (preg_match('/^##\s+(.+)$/', $t, $m)) {
                $flushPara();
                $flushList();
                $blocks[] = ['h2', $m[1]];
            } elseif (preg_match('/^\[button:\s*(.+?)\s*\|\s*(https?:\/\/\S+?)\s*\]$/i', $t, $m)) {
                $flushPara();
                $flushList();
                $blocks[] = ['btn', [$m[1], $m[2]]];
            } elseif (preg_match('/^-(?:\s+(.*))?$/', $t, $m)) {
                $flushPara();
                $list[] = $m[1] ?? '';
            } else {
                $flushList();
                $para[] = rtrim($line);
            }
        }
        $flushPara();
        $flushList();
        if ($preheader === '') {
            // First real sentence, skipping a short greeting such as "Hi Asha," so the preview line says something useful.
            foreach ($blocks as [$k, $v]) {
                if ($k !== 'p') {
                    continue;
                }
                $s = trim(preg_replace('/^(hi|hello|dear)\b[^,\n]{0,40},\s*/i', '', preg_replace('/\s+/', ' ', $v)));
                if (mb_strlen($s) >= 20) {
                    $preheader = preg_match('/^(.{20,150}?[.!?])(\s|$)/u', $s, $mm) ? $mm[1] : mb_strimwidth($s, 0, 140, '…');
                    break;
                }
            }
        }

        return ['headline' => $headline, 'preheader' => $preheader, 'blocks' => $blocks];
    }

    /** Plain links become clickable, shown in full (never shortened). */
    private static function linkify(string $escaped): string
    {
        return preg_replace('~(https?://[^\s<]+[^\s<.,;:!?)\]])~', '<a href="$1" class="cv-acc" style="color:'.self::C['accent'].';text-decoration:underline;word-break:break-all">$1</a>', $escaped);
    }

    /** The packages on sale now, straight from Pricing. @return array<int,array{name:string,price:string,detail:string}> */
    public static function plans(): array
    {
        $out = [];
        try {
            foreach (Settings::plans() as $k => $p) {
                if (($p['type'] ?? '') !== 'plan' || ($k !== 'free' && empty($p['sale']))) {
                    continue;
                }
                $days = (int) ($p['accessDays'] ?? 0);
                $out[] = ['name' => (string) $p['name'], 'price' => $k === 'free' ? 'Free' : '₹'.number_format((float) $p['price']), 'detail' => $k === 'free' ? 'Build, edit and preview with a light watermark' : ($days ? $days.' days · ' : '').rtrim((string) ($p['tagline'] ?? ''), '.')];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $out;
    }

    /** The offer running now (Offers & festivals), or null. Never invented: no live offer means no offer in the mail. */
    public static function offer(): ?array
    {
        try {
            $v = Promos::active();
        } catch (\Throwable $e) {
            return null;
        }
        if (! $v || ($v['code'] === '' && $v['discount'] === '')) {
            return null;
        }
        $ends = ! empty($v['endsAt']) ? \Illuminate\Support\Carbon::parse($v['endsAt'])->timezone('Asia/Kolkata')->format('j M Y') : '';

        return ['badge' => $v['badge'] ?: 'Offer', 'heading' => $v['heading'], 'discount' => str_replace(' OFF', ' off', $v['discount']), 'code' => $v['code'], 'plan' => $v['plan'], 'ends' => $ends];
    }

    public static function offerLine(?array $o = null): string
    {
        $o ??= self::offer();
        if (! $o) {
            return '';
        }

        return trim(($o['heading'] ? $o['heading'].': ' : '').($o['discount'] ?: 'a special price').($o['code'] ? ' with code '.$o['code'].' at checkout' : '').($o['ends'] ? ' (until '.$o['ends'].')' : '').'.');
    }

    /** @return array{html:string,text:string} */
    public static function content(array $parsed, string $variant = 'brand'): array
    {
        $c = self::C;
        $f = self::FONT;
        $p = 'margin:0 0 18px;font-family:'.$f.';font-size:16px;line-height:1.65;color:'.$c['text'];
        $html = '';
        $text = '';
        $btnSeen = false;
        foreach ($parsed['blocks'] as [$kind, $val]) {
            if ($kind === 'p') {
                $html .= '<p class="cv-txt" style="'.$p.'">'.nl2br(self::linkify(self::e($val)), false).'</p>';
                $text .= $val."\n\n";
            } elseif ($kind === 'h2') {
                $html .= '<h2 class="cv-ink" style="margin:8px 0 8px;font-family:'.$f.';font-size:19px;line-height:1.35;font-weight:700;color:'.$c['ink'].'">'.self::e($val).'</h2>';
                $text .= $val."\n";
            } elseif ($kind === 'ul') {
                $details = count($val) >= 2 && count(array_filter($val, fn ($i) => preg_match('/^[A-Z][^:]{1,30}:\s+\S/', $i))) === count($val);
                if ($details) {
                    $rows = '';
                    foreach ($val as $i => $item) {
                        [$k, $v] = array_map('trim', explode(':', $item, 2));
                        $b = $i ? 'border-top:1px solid '.$c['line'].';' : '';
                        $rows .= '<tr><td class="cv-mut cv-line" style="'.$b.'padding:11px 16px;font-family:'.$f.';font-size:14px;color:'.$c['muted'].';white-space:nowrap">'.self::e($k).'</td><td class="cv-ink cv-line" align="right" style="'.$b.'padding:11px 16px;font-family:'.$f.';font-size:15px;font-weight:600;color:'.$c['ink'].'">'.self::e($v).'</td></tr>';
                    }
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="cv-tint" bgcolor="'.$c['tint'].'" style="margin:2px 0 22px;background:'.$c['tint'].';border:1px solid '.$c['tintLine'].';border-radius:12px;border-collapse:separate">'.$rows.'</table>';
                } else {
                    $rows = '';
                    foreach ($val as $item) {
                        $rows .= '<tr><td width="26" valign="top" class="cv-acc" style="padding:1px 0 9px;font-family:'.$f.';font-size:15px;line-height:1.65;font-weight:700;color:'.$c['accent'].'">&#10003;</td><td class="cv-txt" style="padding:0 0 9px;font-family:'.$f.';font-size:16px;line-height:1.6;color:'.$c['text'].'">'.self::linkify(self::e($item)).'</td></tr>';
                    }
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px">'.$rows.'</table>';
                }
                $text .= implode("\n", array_map(fn ($i) => '- '.$i, $val))."\n\n";
            } elseif ($kind === 'code') {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:4px 0 24px"><tr><td align="center" class="cv-tint" bgcolor="'.$c['tint'].'" style="background:'.$c['tint'].';border:1px dashed '.$c['tintLine'].';border-radius:12px;padding:20px 10px"><span class="cv-ink cv-code" style="font-family:Consolas,Menlo,Monaco,monospace;font-size:34px;line-height:1.1;font-weight:700;letter-spacing:.32em;color:'.$c['ink'].'">'.self::e($val).'</span></td></tr></table>';
                $text .= 'Your code: '.$val."\n\n";
            } elseif ($kind === 'note') {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px"><tr><td class="cv-tint cv-txt" bgcolor="'.$c['tint'].'" style="background:'.$c['tint'].';border-left:3px solid '.$c['accent'].';border-radius:8px;padding:12px 16px;font-family:'.$f.';font-size:14px;line-height:1.6;color:'.$c['text'].'">'.self::linkify(self::e($val)).'</td></tr></table>';
                $text .= $val."\n\n";
            } elseif ($kind === 'plans') {
                $plans = self::plans();
                if (! $plans) {
                    continue;
                }
                $rows = '';
                foreach ($plans as $i => $pl) {
                    $b = $i ? 'border-top:1px solid '.$c['line'].';' : '';
                    $rows .= '<tr><td class="cv-line" style="'.$b.'padding:12px 16px"><span class="cv-ink" style="font-family:'.$f.';font-size:15px;font-weight:700;color:'.$c['ink'].'">'.self::e($pl['name']).'</span><br><span class="cv-mut" style="font-family:'.$f.';font-size:13px;line-height:1.5;color:'.$c['muted'].'">'.self::e($pl['detail']).'</span></td><td class="cv-ink cv-line" align="right" valign="top" style="'.$b.'padding:12px 16px;font-family:'.$f.';font-size:16px;font-weight:700;color:'.$c['ink'].';white-space:nowrap">'.self::e($pl['price']).'</td></tr>';
                    $text .= '- '.$pl['name'].($pl['price'] !== 'Free' ? ': '.$pl['price'] : '').' ('.$pl['detail'].")\n";
                }
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="cv-card cv-line" style="margin:2px 0 8px;border:1px solid '.$c['line'].';border-radius:12px;border-collapse:separate">'.$rows.'</table>'
                    .'<p class="cv-mut" style="margin:0 0 22px;font-family:'.$f.';font-size:13px;line-height:1.5;color:'.$c['muted'].'">Pay once, no auto-renewal. Full refund within 7 days if you have not used the package. Secure payment by Razorpay.</p>';
                $text .= "\nPay once, no auto-renewal. Full refund within 7 days if you have not used the package. Secure payment by Razorpay.\n\n";
            } elseif ($kind === 'offer') {
                $o = self::offer();
                if (! $o) {
                    continue;
                }
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:2px 0 24px"><tr><td class="cv-warm" bgcolor="'.$c['warm'].'" style="background:'.$c['warm'].';border:1px solid '.$c['warmLine'].';border-radius:12px;padding:16px 18px">'
                    .'<p style="margin:0 0 4px;font-family:'.$f.';font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:'.$c['warmInk'].'">'.self::e($o['badge']).'</p>'
                    .($o['heading'] !== '' ? '<p class="cv-ink" style="margin:0 0 6px;font-family:'.$f.';font-size:17px;line-height:1.4;font-weight:700;color:'.$c['ink'].'">'.self::e($o['heading']).'</p>' : '')
                    .'<p class="cv-txt" style="margin:0;font-family:'.$f.';font-size:15px;line-height:1.6;color:'.$c['text'].'">'.($o['discount'] !== '' ? '<strong>'.self::e($o['discount']).'</strong>' : 'A special price').($o['code'] !== '' ? ' with code <strong style="font-family:Consolas,Menlo,monospace;letter-spacing:.04em">'.self::e($o['code']).'</strong> at checkout' : '').'.'.($o['ends'] !== '' ? ' Valid until '.self::e($o['ends']).'.' : '').'</p>'
                    .'</td></tr></table>';
                $text .= self::offerLine($o)."\n\n";
            } elseif ($kind === 'btn') {
                [$label, $url] = $val;
                $primary = ! $btnSeen;
                $btnSeen = true;
                $html .= $primary
                    ? '<table role="presentation" cellpadding="0" cellspacing="0" class="cv-btn" style="margin:6px 0 26px"><tr><td align="center" bgcolor="'.$c['accent'].'" style="border-radius:10px;background:'.$c['accent'].'"><a href="'.self::e($url).'" target="_blank" style="display:inline-block;padding:14px 28px;font-family:'.$f.';font-size:16px;line-height:1.2;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px">'.self::e($label).'</a></td></tr></table>'
                    : '<p style="margin:0 0 18px;font-family:'.$f.';font-size:16px"><a href="'.self::e($url).'" target="_blank" class="cv-acc" style="color:'.$c['accent'].';font-weight:700;text-decoration:none">'.self::e($label).' &rarr;</a></p>';
                $text .= $label.': '.$url."\n\n";
            }
        }

        return ['html' => $html, 'text' => $text];
    }

    /**
     * The whole HTML document.
     *
     * @param  array{subject:string,preheader?:string,headline?:string,content:string,signature?:string,footer:string,variant?:string}  $o
     */
    public static function page(array $o): string
    {
        $c = self::C;
        $f = self::FONT;
        $site = rtrim((string) config('app.url'), '/');
        $light = ($o['variant'] ?? 'brand') === 'light';
        // Mail apps show the text after the preview line too; invisible spacers push the rest of the mail out of the preview.
        $pre = ($o['preheader'] ?? '') !== '' ? '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all">'.self::e($o['preheader']).str_repeat('&#847;&zwnj;&nbsp;', 40).'</div>' : '';
        $style = '<style>:root{color-scheme:light dark;supported-color-schemes:light dark}'
            .'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}table{border-collapse:collapse}img{border:0;outline:none}a{color:'.$c['accent'].'}'
            .'@media (max-width:620px){.cv-wrap{width:100%!important}.cv-px{padding-left:22px!important;padding-right:22px!important}.cv-h1{font-size:24px!important}.cv-btn{width:100%!important}.cv-btn td,.cv-btn a{display:block!important;text-align:center!important}.cv-code{font-size:28px!important;letter-spacing:.24em!important}}'
            .'@media (prefers-color-scheme:dark){.cv-bg{background:#0b1120!important}.cv-card{background:#111827!important;border-color:#273449!important}.cv-ink{color:#f8fafc!important}.cv-txt{color:#d5dbe5!important}.cv-mut{color:#9aa6b8!important}.cv-acc{color:#8db4ff!important}.cv-tint{background:#16213a!important;border-color:#2c3d63!important}.cv-warm{background:#2b1e10!important;border-color:#6b4218!important}.cv-line{border-color:#273449!important}}'
            .'[data-ogsc] .cv-ink{color:#f8fafc!important}[data-ogsc] .cv-txt{color:#d5dbe5!important}[data-ogsc] .cv-mut{color:#9aa6b8!important}[data-ogsb] .cv-bg{background:#0b1120!important}[data-ogsb] .cv-card{background:#111827!important}'
            .'</style>';
        // Logo: the CVTara mark as a PNG (mail apps do not show SVG) next to the wordmark in text, so the name still reads when images are off.
        $size = $light ? 26 : 30;
        $img = '<img src="'.self::e($site.'/assets/brand/cvtara-mail-mark.png').'" width="'.$size.'" height="'.$size.'" alt="" style="display:inline-block;width:'.$size.'px;height:'.$size.'px;border:0;vertical-align:middle;margin:0 8px 0 0">';
        $word = $img.'<span style="vertical-align:middle"><span class="cv-ink" style="color:'.$c['ink'].'">CV</span><span class="cv-acc" style="color:'.$c['accent'].'">Tara</span></span>';
        $markStyle = 'text-decoration:none;font-family:'.$f.';font-size:'.($light ? '19' : '22').'px;font-weight:800;letter-spacing:-.02em;color:'.$c['ink'];
        // The outreach card keeps its links to the one that matters, so its wordmark is not a link.
        $mark = $light ? '<span style="'.$markStyle.'">'.$word.'</span>' : '<a href="'.self::e($site).'" target="_blank" style="'.$markStyle.'">'.$word.'</a>';
        $head = ($o['headline'] ?? '') !== '' ? '<h1 class="cv-ink cv-h1" style="margin:0 0 18px;font-family:'.$f.';font-size:'.($light ? '23' : '27').'px;line-height:1.25;font-weight:800;letter-spacing:-.015em;color:'.$c['ink'].'">'.self::e($o['headline']).'</h1>' : '';
        $bar = $light ? '' : '<tr><td height="4" bgcolor="'.$c['accent'].'" style="height:4px;line-height:4px;font-size:0;background:'.$c['accent'].';background-image:linear-gradient(90deg,'.$c['accent'].','.$c['accent2'].');border-radius:14px 14px 0 0">&nbsp;</td></tr>';
        $inner = $light
            ? '<tr><td class="cv-px" style="padding:28px 32px 26px">'.'<p style="margin:0 0 18px">'.$mark.'</p>'.$head.$o['content'].($o['signature'] ?? '').'</td></tr>'
            : $bar.'<tr><td class="cv-px" style="padding:34px 40px 32px">'.$head.$o['content'].($o['signature'] ?? '').'</td></tr>';

        return '<!doctype html><html lang="en" xmlns="http://www.w3.org/1999/xhtml"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="X-UA-Compatible" content="IE=edge"><meta name="x-apple-disable-message-reformatting"><meta name="format-detection" content="telephone=no,address=no,email=no,date=no"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>'.self::e($o['subject']).'</title>'.$style.'</head>'
            .'<body class="cv-bg" style="margin:0;padding:0;width:100%;background:'.$c['page'].'">'.$pre
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="cv-bg" bgcolor="'.$c['page'].'" style="background:'.$c['page'].'"><tr><td align="center" style="padding:28px 12px 36px">'
            .'<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="cv-wrap" style="width:600px;max-width:600px;border-collapse:separate">'
            .($light ? '' : '<tr><td class="cv-px" style="padding:0 6px 16px">'.$mark.'</td></tr>')
            .'<tr><td class="cv-card" bgcolor="'.$c['card'].'" style="background:'.$c['card'].';border:1px solid '.$c['line'].';border-radius:14px;overflow:hidden"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate">'.$inner.'</table></td></tr>'
            .'<tr><td class="cv-px cv-mut" style="padding:22px 26px 0;font-family:'.$f.';font-size:12.5px;line-height:1.65;color:'.$c['muted'].';text-align:center">'.$o['footer'].'</td></tr>'
            .'</table></td></tr></table></body></html>';
    }
}
