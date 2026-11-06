<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The SEO agent's link-building and digital PR register. The owner (or the agent's daily check) records each prospect site,
 * qualifies it, writes to the editor personally, and follows it through to a published page. The agent then verifies the
 * page: is it live, does it link to CVTara, with which rel attribute, or does it only mention the name (a mention to reclaim).
 * Visits from the site and the sign-ups and sales they led to are read from the visit log.
 * Never: bought ranking links, mass-created links, private blog networks, keyword-stuffed directories or fake guest posts.
 * Mail is not sent from here: each pitch is a one-to-one note the owner sends from their own mailbox after approving it.
 */
class LinkCrm
{
    public const METHODS = ['resource' => 'Career or education resource contribution', 'research' => 'Original resume or hiring research', 'college' => 'College / TPO resource partnership', 'pr' => 'Digital PR or expert comment',
        'guest' => 'Genuine editorial guest article', 'tool' => 'Free tool or template others cite', 'directory' => 'Relevant professional or business directory', 'partner' => 'Coaching institute or career community partnership',
        'resource_page' => 'Resource page where CVTara improves the list', 'mention' => 'Unlinked brand mention to reclaim'];

    public const STATUSES = ['prospect' => 'Prospect', 'qualified' => 'Qualified', 'pitched' => 'Pitched', 'follow_up' => 'Followed up', 'replied' => 'Replied', 'accepted' => 'Accepted', 'published' => 'Published', 'declined' => 'Declined', 'dropped' => 'Dropped'];

    public const ATTRS = ['follow', 'nofollow', 'sponsored', 'ugc'];

    /** Domains that are never worth pursuing (link farms, paid-link markets). The owner can add more in notes; this only blocks the obvious. */
    private const SPAM_HINTS = '/(casino|betting|loan|pharma|crypto|seo-?links?|backlinks?|buy-?links?|linkfarm|pbn|guestpost(s|ing)?-?(market|sale))/i';

    public static function score(array $r): int
    {
        $base = (int) round(((int) ($r['relevance'] ?? 0) + (int) ($r['quality'] ?? 0)) / 10 * 100);
        $pen = ['high' => 60, 'medium' => 25][$r['spam_risk'] ?? ''] ?? 0;

        return max(0, min(100, $base - $pen));
    }

    public static function domain(string $urlOrHost): string
    {
        $h = parse_url(str_contains($urlOrHost, '://') ? $urlOrHost : 'https://'.$urlOrHost, PHP_URL_HOST) ?: $urlOrHost;

        return preg_replace('/^www\./', '', mb_strtolower(trim((string) $h)));
    }

    public static function save(array $v, ?string $id = null): string
    {
        $row = array_intersect_key($v, array_flip(['url', 'method', 'contact_name', 'contact_email', 'contact_source', 'relevance', 'quality', 'spam_risk', 'status', 'pitch', 'next_follow_up', 'response', 'publication_url', 'target_url', 'link_attr', 'anchor', 'cost_inr', 'notes']));
        $row = array_map(fn ($x) => $x ?? '', $row);
        $row['next_follow_up'] = ($v['next_follow_up'] ?? null) ?: null;
        $row['domain'] = self::domain((string) ($v['domain'] ?: $v['url']));
        abort_if($row['domain'] === '' || ! str_contains($row['domain'], '.'), 422, 'Enter the site address, for example careers.example.edu.');
        if (preg_match(self::SPAM_HINTS, $row['domain'])) {
            $row['spam_risk'] = 'high';
        }
        abort_if((int) ($row['cost_inr'] ?? 0) > 0 && ($row['link_attr'] ?? '') === 'follow', 422, 'A paid placement must be marked sponsored or nofollow. Paying for a followed link breaks Google\'s link spam rules.');
        $row['score'] = self::score($row);
        $old = $id ? DB::table('link_prospects')->where('id', $id)->first() : null;
        if ($old && in_array($row['status'] ?? '', ['pitched', 'follow_up', 'replied'], true) && ($row['status'] ?? '') !== $old->status) {
            $row['last_contact_at'] = now();
        }
        $row['updated_at'] = now();
        if ($id) {
            abort_unless($old, 404);
            DB::table('link_prospects')->where('id', $id)->update($row);
        } else {
            $id = (string) Str::uuid();
            DB::table('link_prospects')->insert($row + ['id' => $id, 'created_at' => now()]);
        }
        if (($row['publication_url'] ?? '') !== '' && (! $old || $old->publication_url !== $row['publication_url'])) {
            self::verify($id);
        }

        return $id;
    }

    /** Checks a published page: live, links to CVTara (and how), or only mentions the name. */
    public static function verify(string $id): array
    {
        $p = DB::table('link_prospects')->where('id', $id)->first();
        if (! $p || $p->publication_url === '') {
            return ['state' => ''];
        }
        $ours = array_unique(array_filter([self::domain(FastIndex::origin()), 'cvtara.com', 'cvtara.in']));
        $state = 'gone';
        $note = '';
        $attr = $p->link_attr;
        try {
            $res = Http::timeout(15)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; CVTara-link-check/1.0)', 'Accept' => 'text/html'])->get($p->publication_url);
            if (! $res->successful()) {
                $note = 'The page answered '.$res->status().'.';
            } else {
                $html = (string) $res->body();
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
                $found = null;
                foreach ($dom->getElementsByTagName('a') as $a) {
                    $href = (string) $a->getAttribute('href');
                    if (in_array(self::domain($href), $ours, true)) {
                        $found = $a;
                        break;
                    }
                }
                if ($found) {
                    $rel = strtolower((string) $found->getAttribute('rel'));
                    $attr = str_contains($rel, 'sponsored') ? 'sponsored' : (str_contains($rel, 'ugc') ? 'ugc' : (str_contains($rel, 'nofollow') ? 'nofollow' : 'follow'));
                    $state = 'live';
                    $note = 'Links to '.mb_strimwidth((string) $found->getAttribute('href'), 0, 120, '…').' with anchor "'.mb_strimwidth(trim($found->textContent), 0, 60, '…').'" ('.$attr.').';
                    if ($p->cost_inr > 0 && $attr === 'follow') {
                        $state = 'paid_follow';
                        $note .= ' This placement was paid for but the link is followed: ask the site to add rel="sponsored".';
                    }
                } elseif (preg_match('/\bCVTara\b/i', strip_tags($html))) {
                    $state = 'mention';
                    $note = 'CVTara is named but not linked: ask the editor to add a link (unlinked mention).';
                } else {
                    $state = 'missing';
                    $note = 'The page is live but neither links to nor names CVTara.';
                }
            }
        } catch (\Throwable $e) {
            $note = 'Could not open the page: '.mb_strimwidth($e->getMessage(), 0, 120, '…');
        }
        DB::table('link_prospects')->where('id', $id)->update(['verify_state' => $state, 'verify_note' => mb_substr($note, 0, 300), 'link_attr' => $attr, 'verified_at' => now(), 'updated_at' => now()]);

        return ['state' => $state, 'note' => $note];
    }

    /** Daily: re-checks published links (oldest check first), so a removed link or a changed rel shows up. */
    public static function verifyDue(int $limit = 15): array
    {
        $n = 0;
        $lost = 0;
        foreach (DB::table('link_prospects')->where('publication_url', '!=', '')->where(fn ($q) => $q->whereNull('verified_at')->orWhere('verified_at', '<', now()->subDays(7)))->orderBy('verified_at')->limit($limit)->pluck('id') as $id) {
            $was = DB::table('link_prospects')->where('id', $id)->value('verify_state');
            $r = self::verify($id);
            $n++;
            if ($was === 'live' && $r['state'] !== 'live') {
                $lost++;
                ErrorLog::record('warning', 'seo', 'A published link to CVTara is no longer found on '.DB::table('link_prospects')->where('id', $id)->value('publication_url').'.');
            }
        }

        return ['summary' => "Checked $n published link(s)".($lost ? ", $lost no longer link" : '').'.'];
    }

    /** A plain first draft of the pitch, for the owner to personalise and send themselves. */
    public static function draft(object $p): string
    {
        $site = rtrim(Settings::get('seo')['siteUrl'] ?? config('app.url'), '/');
        $hi = $p->contact_name !== '' ? 'Hi '.explode(' ', $p->contact_name)[0].',' : 'Hello,';
        $offer = match ($p->method) {
            'research' => 'We have put together findings on what Indian recruiters\' applicant tracking systems most often reject in resumes, with the method and limits stated. Happy to share the data for your readers.',
            'college' => 'We would like to offer your placement cell free resume-checking sessions and a resume guide your students can use before campus drives.',
            'mention' => 'Thank you for mentioning CVTara in your article. Would you consider linking the name to our site so readers can find the tool you describe?',
            'resource_page' => 'Your resource list helps job seekers a lot. Our free ATS resume checker ('.$site.'/ats-checker/) shows which job-description words a resume is missing; it might be a useful addition.',
            'pr' => 'If you are writing about resumes, ATS screening or fresher hiring, our team can give a short expert comment with practical examples.',
            'guest' => 'We would like to write an original article for your readers on a resume topic you choose, with real examples and no promotional fluff.',
            default => 'We make free resume and cover letter guides for Indian job seekers ('.$site.'/guides.html). If one fits a page of yours, we would be glad to help make it more useful for your readers.',
        };

        return "$hi\n\nI'm from the CVTara team. I came across ".($p->url !== '' ? $p->url : $p->domain)." while looking for resources for job seekers.\n\n$offer\n\nThank you for your time,\nCVTara team\n".$site;
    }

    public static function rows(): array
    {
        $since = now()->subDays(90);
        $views = DB::table('page_views')->where('created_at', '>=', $since)->whereNotNull('ref_host')->select('ref_host', DB::raw('count(distinct visitor_id) as n'))->groupBy('ref_host')->pluck('n', 'ref_host');
        $signups = DB::table('users')->where('is_admin', false)->whereNotNull('signup_ref_host')->select('signup_ref_host', DB::raw('count(*) as n'))->groupBy('signup_ref_host')->pluck('n', 'signup_ref_host');
        $today = now()->tz(Growth::TZ)->toDateString();

        return DB::table('link_prospects')->orderByDesc('updated_at')->get()->map(function ($p) use ($views, $signups, $today) {
            $match = fn ($h) => $h === $p->domain || str_ends_with((string) $h, '.'.$p->domain);
            $v = 0;
            $s = 0;
            foreach ($views as $h => $n) {
                $v += $match($h) ? (int) $n : 0;
            }
            foreach ($signups as $h => $n) {
                $s += $match($h) ? (int) $n : 0;
            }
            $buyers = $s ? Growth::sales()->whereIn('owner_id', DB::table('users')->where(fn ($q) => $q->where('signup_ref_host', $p->domain)->orWhere('signup_ref_host', 'like', '%.'.$p->domain))->select('id'))->distinct()->count('owner_id') : 0;

            return (array) $p + ['visits90d' => $v, 'signups' => $s, 'buyers' => $buyers, 'due' => $p->next_follow_up && $p->next_follow_up <= $today && ! in_array($p->status, ['published', 'declined', 'dropped'], true), 'methodLabel' => self::METHODS[$p->method] ?? $p->method, 'statusLabel' => self::STATUSES[$p->status] ?? $p->status];
        })->all();
    }

    public static function summary(): array
    {
        $q = DB::table('link_prospects');
        $by = (clone $q)->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return ['total' => (clone $q)->count(), 'byStatus' => $by, 'live' => (clone $q)->where('verify_state', 'live')->count(), 'followLive' => (clone $q)->where('verify_state', 'live')->where('link_attr', 'follow')->count(),
            'mentions' => (clone $q)->where('verify_state', 'mention')->count(), 'lost' => (clone $q)->whereIn('verify_state', ['missing', 'gone'])->where('status', 'published')->count(), 'paidFollow' => (clone $q)->where('verify_state', 'paid_follow')->count(),
            'dueToday' => (clone $q)->whereNotNull('next_follow_up')->where('next_follow_up', '<=', now()->tz(Growth::TZ)->toDateString())->whereNotIn('status', ['published', 'declined', 'dropped'])->count(),
            'costInr' => (int) (clone $q)->sum('cost_inr')];
    }
}
