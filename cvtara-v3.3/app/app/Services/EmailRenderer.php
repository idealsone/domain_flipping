<?php

namespace App\Services;

use Illuminate\Support\Facades\URL;

/**
 * Turns the plain template text an owner edits into a branded HTML email plus a plain-text copy, in the shared design
 * (MailLayout). See MailLayout for the template text rules.
 * Mail is sent as HTML and text together, from one clear sender, with a signature, the sender's postal identity and
 * (for marketing) a one-click unsubscribe.
 */
class EmailRenderer
{
    /** Triggers that are marketing: they need the person's consent and always carry an unsubscribe link. */
    public const MARKETING = ['nurture', 'blog_published', 'abandoned_purchase', 'monthly_digest', 'campaign', 'referral_invite', 'plan_ended'];

    /** Triggers that carry a one-time code or a sign-in link: sent at once, never with offers, never with an unsubscribe link. */
    public const SECURITY = ['email_verification', 'security_verification', 'password_reset'];

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

    /** The sender's postal identity for the footer. The owner enters it in Integrations → Email (Postal address). */
    public static function postalAddress(): string
    {
        try {
            $a = trim((string) (Settings::providers()['email']['postalAddress'] ?? ''));
        } catch (\Throwable $e) {
            $a = '';
        }

        return $a !== '' ? preg_replace('/\s*\R\s*/', ', ', $a) : 'Bangalore, Karnataka, India';
    }

    public static function supportAddress(): string
    {
        try {
            $r = trim((string) (Settings::providers()['email']['replyTo'] ?? ''));
        } catch (\Throwable $e) {
            $r = '';
        }

        return filter_var($r, FILTER_VALIDATE_EMAIL) ? $r : 'support@cvtara.com';
    }

    /** @return array{html:string,text:string,preheader:string} */
    public static function render(string $subject, string $body, bool $marketing = false, ?int $userId = null, string $preheader = '', ?string $unsubUrl = null, bool $security = false): array
    {
        $site = rtrim((string) config('app.url'), '/');
        $support = self::supportAddress();
        $parsed = MailLayout::blocks($body);
        if ($preheader !== '') {
            $parsed['preheader'] = $preheader;
        }
        $content = MailLayout::content($parsed);
        $f = MailLayout::FONT;
        $c = MailLayout::C;

        $footerReason = $marketing
            ? ($userId || ! $unsubUrl ? 'You are receiving this because you have a CVTara account and chose to get tips and plan updates.' : 'You are receiving this because you agreed to hear from CVTara.')
            : 'You are receiving this because of activity on your CVTara account.';
        $links = [];
        $unsubText = '';
        if ($marketing && ($unsubUrl || $userId)) {
            $u = $unsubUrl ?: self::unsubscribeUrl($userId);
            $links[] = '<a href="'.self::e($u).'" class="cv-mut" style="color:'.$c['muted'].';text-decoration:underline">Unsubscribe</a>';
            $unsubText = 'Unsubscribe: '.$u."\n";
        }
        if ($userId) {
            $links[] = '<a href="'.self::e($site.'/workspace.html?view=account').'" class="cv-mut" style="color:'.$c['muted'].';text-decoration:underline">Email preferences</a>';
        }
        $links[] = '<a href="mailto:'.self::e($support).'" class="cv-mut" style="color:'.$c['muted'].';text-decoration:underline">'.self::e($support).'</a>';
        $postal = self::postalAddress();
        $safety = $security ? '<br>Never share your password or one-time codes. CVTara will never ask you for them.' : '';
        $footer = self::e($footerReason).'<br>'.implode(' &nbsp;·&nbsp; ', $links).'<br><span class="cv-mut" style="color:'.$c['muted'].'">CVTara · '.self::e($postal).'</span>'.$safety;

        $sig = '<p class="cv-txt" style="margin:28px 0 0;font-family:'.$f.';font-size:16px;line-height:1.6;color:'.$c['text'].'">Warm regards,<br><strong class="cv-ink" style="color:'.$c['ink'].'">Team CVTara</strong><br><span class="cv-mut" style="font-size:14px;color:'.$c['muted'].'">Resumes and cover letters by an HR team in Bangalore, India</span></p>';

        $html = MailLayout::page(['subject' => $subject, 'preheader' => $parsed['preheader'], 'headline' => $parsed['headline'], 'content' => $content['html'], 'signature' => $sig, 'footer' => $footer]);

        $text = ($parsed['headline'] !== '' ? $parsed['headline']."\n".str_repeat('=', min(60, mb_strlen($parsed['headline'])))."\n\n" : '')
            .$content['text']
            ."Warm regards,\nTeam CVTara\nResumes and cover letters by an HR team in Bangalore, India\n".$support.' · '.$site."\n\n--\n".$footerReason."\n".$unsubText
            .($userId ? 'Email preferences: '.$site."/workspace.html?view=account\n" : '')
            .'CVTara · '.$postal."\n"
            .($security ? "Never share your password or one-time codes. CVTara will never ask you for them.\n" : '');

        return ['html' => $html, 'text' => $text, 'preheader' => $parsed['preheader']];
    }
}
