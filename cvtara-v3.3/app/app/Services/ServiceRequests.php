<?php

namespace App\Services;

use App\Http\Controllers\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Combined resume + cover letter service (3.41.0). The customer picks a saved resume and a job; the CVTara team tailors
 * the resume, writes the letter, checks that both agree, checks formatting and the ATS match, and shows a preview. Clean
 * files need a plan: the customer sees the order summary, pays through the normal checkout, and then saves both
 * documents to their account, where the normal download credits apply. Every state change is stored as an event, and
 * the progress tracker shows only those stored events.
 *
 * Work runs one step per call: the page asks for the next step while it is open, and the cron finishes any request the
 * customer left. A short lock stops two runs of the same step.
 */
class ServiceRequests
{
    public const STATES = [
        'received' => 'Information received',
        'resume' => 'Resume generation',
        'letter' => 'Cover-letter generation',
        'checks' => 'Quality checks',
        'ats' => 'ATS checks',
        'preview_ready' => 'Preview ready',
        'payment_pending' => 'Payment pending',
        'payment_confirmed' => 'Payment confirmed',
        'download_available' => 'Download available',
    ];

    /** Steps the server still has to run, and what comes after each. */
    private const NEXT = ['received' => 'resume', 'resume' => 'letter', 'letter' => 'checks', 'checks' => 'ats'];

    private const LOCK_SECONDS = 200;

    /** Customers may start this many requests a day. */
    public const DAILY = 5;

    public static function create(User $u, array $v): array
    {
        abort_unless(TaraAI::enabled(), 422, 'The resume + cover letter service is not available right now. You can still tailor your resume and write the letter in the builder.');
        $resume = DB::table('resume_drafts')->where('id', $v['resumeId'])->where('owner_id', $u->id)->first(['id', 'content_json']);
        abort_unless($resume, 422, 'Choose one of your saved resumes.');
        abort_if(mb_strlen(AtsEngine::resumeText(json_decode((string) $resume->content_json, true) ?: [])) < 200, 422, 'This resume has too little content to tailor. Add your experience and skills in the builder first.');
        $open = DB::table('service_requests')->where('owner_id', $u->id)->whereIn('status', array_keys(self::NEXT))->where('updated_at', '>', now()->subHour())->value('id');
        abort_if($open, 422, 'Your earlier request is still being prepared. Open it from your dashboard.');
        abort_if(DB::table('service_requests')->where('owner_id', $u->id)->where('created_at', '>', now()->subDay())->count() >= self::DAILY, 429, 'You have started '.self::DAILY.' requests today. Please try again tomorrow.');
        abort_if(TaraAI::left($u) < 1, 422, 'You have used today\'s CVTara team suggestions. They refresh tomorrow.');
        // The job: an earlier ATS check, or a new one made now through the normal ATS check (its limits and scan credits apply).
        $scanId = $v['scanId'] ?? null;
        if ($scanId) {
            abort_unless(DB::table('ats_scans')->where('id', $scanId)->where('owner_id', $u->id)->where('status', 'done')->exists(), 422, 'That ATS check was not found.');
        } else {
            $j = self::call($u, 'POST', 'ats/scan', ['resumeId' => $resume->id, 'source' => 'text', 'text' => (string) ($v['jobText'] ?? ''), 'title' => (string) ($v['role'] ?? ''), 'company' => (string) ($v['company'] ?? '')]);
            abort_unless($j['ok'], 422, ($j['error'] ?? ''));
            $scanId = $j['scanId'];
        }
        $id = (string) Str::uuid();
        DB::table('service_requests')->insert(['id' => $id, 'owner_id' => $u->id, 'kind' => 'resume_and_letter', 'status' => 'received', 'resume_id' => $resume->id, 'scan_id' => $scanId,
            'inputs_json' => json_encode(['template' => $v['letterTemplate'] ?? null]), 'created_at' => now(), 'updated_at' => now()]);
        self::event($id, 'received', 'customer');
        Settings::audit($u->id, 'service_request_created', $id);

        return self::show($u, $id);
    }

    /** Runs the next step if one is due and nobody else is running it. */
    public static function advance(User $u, string $id): array
    {
        $row = self::row($u, $id);
        if (isset(self::NEXT[$row->status]) && self::claim($id)) {
            self::step(User::find($row->owner_id), DB::table('service_requests')->where('id', $id)->first());
        }

        return self::show($u, $id);
    }

    /** Cron: finish requests whose customer left the page (nothing happened for 90 seconds). */
    public static function runDue(int $max = 5): int
    {
        $n = 0;
        $ids = DB::table('service_requests')->whereIn('status', array_keys(self::NEXT))->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->where('updated_at', '>', now()->subDay())->where('updated_at', '<', now()->subSeconds(90))->orderBy('updated_at')->limit($max)->pluck('id');
        foreach ($ids as $id) {
            if (self::claim($id) && ($r = DB::table('service_requests')->where('id', $id)->first()) && ($u = User::find($r->owner_id))) {
                self::step($u, $r);
                $n++;
            }
        }

        return $n;
    }

    public static function retry(User $u, string $id): array
    {
        $row = self::row($u, $id);
        abort_unless($row->status === 'failed', 422, 'This request has not failed.');
        abort_if($row->attempts >= 6, 422, 'This request could not be finished. Please start a new one, or contact support.');
        $back = json_decode((string) $row->output_json, true)['failedAt'] ?? 'received';
        DB::table('service_requests')->where('id', $id)->update(['status' => $back, 'error' => null, 'locked_until' => null, 'updated_at' => now()]);
        self::event($id, $back, 'customer', 'Tried again');

        return self::show($u, $id);
    }

    /**
     * After payment (or straight away on a plan that covers it): the job version of the resume and the letter are saved
     * to the customer's account through the normal endpoints, so plan limits and download credits apply exactly as usual.
     * $mode 'version' adds a job version; 'update' writes the tailored text into the resume the customer started from.
     */
    public static function save(User $u, string $id, string $mode): array
    {
        $row = self::row($u, $id);
        self::payment($u, $row);
        $row = self::row($u, $id);
        abort_unless($row->status === 'payment_confirmed', 422, $row->status === 'download_available' ? 'Already saved to your account.' : 'Choose a plan first to get the clean files.');
        $out = json_decode((string) $row->output_json, true) ?: [];
        $scan = DB::table('ats_scans')->where('id', $row->scan_id)->where('owner_id', $u->id)->first(['job_title', 'company']);
        $role = mb_substr(trim((string) ($scan->job_title ?? '')), 0, 160) ?: 'Job';
        $company = mb_substr(trim((string) ($scan->company ?? '')), 0, 160) ?: 'Company';
        $data = $out['resume']['data'] ?? null;
        abort_unless(is_array($data), 422, 'The preview is missing. Please start a new request.');
        // resume
        if (! $row->result_resume_id) {
            if ($mode === 'update') {
                $target = $row->resume_id;
            } else {
                $j = self::call($u, 'POST', 'resumes/'.$row->resume_id.'/variant', ['company' => $company, 'role' => $role, 'scanId' => $row->scan_id]);
                abort_unless($j['ok'], 422, ($j['error'] ?? '').' You can also put the tailored text into the resume you started from.');
                $target = $j['id'];
            }
            $cur = DB::table('resume_drafts')->where('id', $target)->where('owner_id', $u->id)->first(['revision', 'content_json']);
            abort_unless($cur, 422, 'That resume was not found.');
            $base = json_decode((string) $cur->content_json, true) ?: [];
            $save = array_merge($base, array_intersect_key($data, array_flip(['summary', 'skills', 'experience'])));
            $j = self::call($u, 'POST', 'resumes', ['id' => $target, 'revision' => (int) $cur->revision, 'data' => $save]);
            abort_unless($j['ok'], 422, ($j['error'] ?? ''));
            DB::table('service_requests')->where('id', $id)->update(['result_resume_id' => $target, 'updated_at' => now()]);
        }
        // letter
        $row = self::row($u, $id);
        if (! $row->letter_id) {
            $l = $out['letter'] ?? [];
            $resumeData = $out['resume']['data'] ?? [];
            $tpl = (string) (json_decode((string) $row->inputs_json, true)['template'] ?? '') ?: (DB::table('cover_letter_drafts')->where('owner_id', $u->id)->orderByDesc('updated_at')->value('template') ?: 'letter-prism');
            $p = (array) ($l['personal'] ?? []);
            $letter = ['version' => '1', 'title' => 'Cover letter · '.$role.' · '.$company, 'template' => $tpl, 'accent' => (string) ($resumeData['accent'] ?? '#153b5c'), 'font' => in_array($resumeData['font'] ?? '', ['Arial', 'Georgia', 'Verdana'], true) ? $resumeData['font'] : 'Arial',
                'personal' => ['name' => (string) ($p['name'] ?? $resumeData['personal']['name'] ?? $u->name), 'email' => (string) ($p['email'] ?? ''), 'phone' => (string) ($p['phone'] ?? ''), 'city' => (string) ($p['city'] ?? '')],
                'role' => $role, 'company' => $company, 'recipient' => 'Hiring Manager', 'date' => now()->format('j F Y')];
            foreach (['subject', 'salutation', 'opening', 'evidence', 'motivation', 'closing', 'signoff'] as $k) {
                $letter[$k] = (string) ($l['letter'][$k] ?? '');
            }
            $j = self::call($u, 'POST', 'letters', ['data' => $letter, 'source' => ['resumeId' => (string) ($row->result_resume_id ?: $row->resume_id), 'scanId' => (string) $row->scan_id]]);
            abort_unless($j['ok'], 422, ($j['error'] ?? '').' Your tailored resume is saved; you can copy the letter from the preview.');
            DB::table('service_requests')->where('id', $id)->update(['letter_id' => $j['id'], 'updated_at' => now()]);
        }
        self::state($id, 'download_available', 'customer', 'Saved to your account');
        Settings::audit($u->id, 'service_request_delivered', $id);

        return self::show($u, $id);
    }

    public static function show(User $u, string $id): array
    {
        $row = self::row($u, $id);
        self::payment($u, $row);
        $row = self::row($u, $id);
        $out = json_decode((string) $row->output_json, true) ?: [];
        $scan = DB::table('ats_scans')->where('id', $row->scan_id)->where('owner_id', $u->id)->first(['job_title', 'company', 'score']);
        $events = DB::table('service_request_events')->where('request_id', $id)->orderBy('id')->get(['state', 'actor', 'note', 'created_at']);
        $done = $events->pluck('created_at', 'state');
        $access = Settings::access($u->id);
        $preview = in_array($row->status, ['preview_ready', 'payment_pending', 'payment_confirmed', 'download_available'], true);

        return [
            'id' => $id, 'status' => $row->status, 'label' => self::STATES[$row->status] ?? ($row->status === 'failed' ? 'Could not finish' : $row->status),
            'working' => isset(self::NEXT[$row->status]), 'error' => $row->error,
            'job' => ['role' => $scan->job_title ?? '', 'company' => $scan->company ?? '', 'scoreBefore' => $scan->score ?? null],
            'steps' => collect(self::STATES)->map(fn ($label, $k) => ['key' => $k, 'label' => $label, 'at' => isset($done[$k]) ? (string) $done[$k] : null])->values()->all(),
            'events' => $events->map(fn ($e) => ['state' => $e->state, 'label' => self::STATES[$e->state] ?? ($e->state === 'failed' ? 'Could not finish' : $e->state), 'note' => $e->note, 'at' => (string) $e->created_at])->all(),
            'preview' => $preview ? ['resume' => $out['resume']['tailored'] ?? null, 'letter' => $out['letter']['letter'] ?? null, 'checks' => $out['checks'] ?? [], 'quality' => $out['quality'] ?? null, 'scoreAfter' => $out['ats']['score'] ?? null, 'missing' => $out['ats']['missing'] ?? []] : null,
            'order' => $row->status === 'payment_pending' ? self::order($u) : null,
            'covered' => (bool) $access['paid'],
            'resumeId' => $row->result_resume_id, 'letterId' => $row->letter_id, 'createdAt' => (string) $row->created_at,
        ];
    }

    public static function mine(User $u): array
    {
        return DB::table('service_requests as s')->leftJoin('ats_scans as a', 'a.id', '=', 's.scan_id')->where('s.owner_id', $u->id)->orderByDesc('s.created_at')->limit(10)
            ->get(['s.id', 's.status', 's.created_at', 'a.job_title', 'a.company'])
            ->map(fn ($r) => ['id' => $r->id, 'status' => $r->status, 'label' => self::STATES[$r->status] ?? 'Could not finish', 'role' => $r->job_title, 'company' => $r->company, 'createdAt' => (string) $r->created_at])->all();
    }

    /** What the customer gets and pays: the plans on sale that include a tailored resume and a cover letter. */
    private static function order(User $u): array
    {
        $plans = Settings::plans();
        $out = [];
        foreach (Settings::paidPlanKeys() as $k) {
            $p = $plans[$k] ?? null;
            if (! $p || str_starts_with($k, 'addon') || (int) ($p['savedLetters'] ?? 0) < 1 || (int) ($p['savedResumes'] ?? 0) < 2) {
                continue;
            }
            $out[] = ['plan' => $k, 'name' => (string) $p['name'], 'price' => (int) $p['price'], 'days' => (int) ($p['accessDays'] ?? 0), 'resumes' => (int) $p['savedResumes'], 'letters' => (int) $p['savedLetters'], 'url' => '/checkout.html?plan='.rawurlencode($k)];
        }

        return ['deliverables' => ['This resume, tailored for the job, as a clean PDF and Word file', 'The cover letter for the same job, as a clean PDF and Word file', 'Both saved in your account to edit and download again while your plan lasts'],
            'plans' => $out, 'note' => 'One-time payment, no automatic renewal.'];
    }

    // ---- internals ----

    private static function row(User $u, string $id): object
    {
        $row = DB::table('service_requests')->where('id', $id)->where('owner_id', $u->id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private static function claim(string $id): bool
    {
        return DB::table('service_requests')->where('id', $id)->whereIn('status', array_keys(self::NEXT))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->update(['locked_until' => now()->addSeconds(self::LOCK_SECONDS), 'attempts' => DB::raw('attempts+1'), 'updated_at' => now()]) === 1;
    }

    private static function step(?User $u, object $row): void
    {
        $next = self::NEXT[$row->status];
        $out = json_decode((string) $row->output_json, true) ?: [];
        try {
            abort_unless($u && ! $u->blocked, 403, 'This account cannot use the service.');
            $resume = DB::table('resume_drafts')->where('id', $row->resume_id)->where('owner_id', $u->id)->first(['content_json']);
            $scan = DB::table('ats_scans')->where('id', $row->scan_id)->where('owner_id', $u->id)->first(['job_text', 'job_title', 'company', 'result_json']);
            abort_unless($resume && $scan, 422, 'The resume or the job of this request was removed.');
            if ($next === 'resume') {
                $data = json_decode((string) $resume->content_json, true) ?: [];
                $t = ResumeTailor::run($u, $data, (string) $scan->job_text, json_decode((string) $scan->result_json, true) ?: []);
                $out['resume'] = ['tailored' => $t, 'data' => self::apply($data, $t)];
            } elseif ($next === 'letter') {
                $l = CoverLetterWriter::draft($u, $out['resume']['data'], ['role' => (string) $scan->job_title, 'company' => (string) $scan->company, 'recipient' => '', 'text' => (string) $scan->job_text]);
                $out['letter'] = $l;
            } elseif ($next === 'checks') {
                $out['checks'] = self::consistency($out['resume']['data'], $out['letter'], (string) $scan->company, (string) $scan->job_title);
                $q = ResumeQuality::score($out['resume']['data']);
                $out['quality'] = ['score' => $q['score'], 'tips' => array_slice($q['tips'] ?? [], 0, 4)];
            } elseif ($next === 'ats') {
                $rep = AtsEngine::report($out['resume']['data'], (string) $scan->job_text, (string) $scan->job_title);
                $out['ats'] = ['score' => $rep['score'], 'missing' => array_slice($rep['keywords']['missing'] ?? [], 0, 12)];
            }
            unset($out['failedAt']);
            DB::table('service_requests')->where('id', $row->id)->update(['output_json' => json_encode($out, JSON_UNESCAPED_UNICODE), 'locked_until' => null]);
            self::state($row->id, $next);
            if ($next === 'ats') {
                self::state($row->id, 'preview_ready');
                self::payment($u, DB::table('service_requests')->where('id', $row->id)->first());
            }
        } catch (\Throwable $e) {
            $msg = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first()
                : ($e instanceof HttpExceptionInterface || $e instanceof \InvalidArgumentException ? $e->getMessage() : '');
            if ($msg === '') {
                ErrorLog::exception($e, $u?->id, 'service-request');
                $msg = 'Something went wrong on our side. Try again in a minute; nothing was charged.';
            }
            $out['failedAt'] = $row->status;
            DB::table('service_requests')->where('id', $row->id)->update(['status' => 'failed', 'error' => mb_substr($msg, 0, 500), 'output_json' => json_encode($out, JSON_UNESCAPED_UNICODE), 'locked_until' => null, 'updated_at' => now()]);
            self::event($row->id, 'failed', 'system', mb_substr($msg, 0, 300));
        }
    }

    /** Moves a previewed request to payment pending, or to payment confirmed when a plan covers it. */
    private static function payment(User $u, object $row): void
    {
        if (! in_array($row->status, ['preview_ready', 'payment_pending'], true)) {
            return;
        }
        $access = Settings::access($u->id);
        $limits = (array) ($access['limits'] ?? []);
        if ($access['paid']) {
            $purchase = DB::table('purchases')->where('owner_id', $u->id)->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->orderByDesc('purchased_at')->value('id');
            DB::table('service_requests')->where('id', $row->id)->update(['purchase_id' => $purchase]);
            self::state($row->id, 'payment_confirmed', 'system', $row->status === 'payment_pending' ? 'Payment received' : 'Covered by your plan'.(! empty($limits['oneResume']) ? ' (your plan covers one resume: the tailored text can go into it)' : ''));
        } elseif ($row->status === 'preview_ready') {
            self::state($row->id, 'payment_pending');
        }
    }

    private static function state(string $id, string $state, string $actor = 'system', string $note = ''): void
    {
        DB::table('service_requests')->where('id', $id)->update(['status' => $state, 'updated_at' => now()]);
        self::event($id, $state, $actor, $note);
    }

    private static function event(string $id, string $state, string $actor = 'system', string $note = ''): void
    {
        DB::table('service_request_events')->insert(['request_id' => $id, 'state' => $state, 'actor' => $actor, 'note' => $note, 'created_at' => now()]);
    }

    /** The tailored summary, skills and bullets on top of the customer's own resume; everything else stays as it was. */
    public static function apply(array $data, array $t): array
    {
        if (trim((string) ($t['summary'] ?? '')) !== '') {
            $data['summary'] = $t['summary'];
        }
        if (trim((string) ($t['skills'] ?? '')) !== '') {
            $data['skills'] = $t['skills'];
        }
        $exp = array_values($data['experience'] ?? []);
        foreach ($t['experience'] ?? [] as $x) {
            $i = (int) $x['i'];
            if (isset($exp[$i])) {
                $key = array_key_exists('bullets', $exp[$i]) || ! array_key_exists('details', $exp[$i]) ? 'bullets' : 'details';
                $exp[$i][$key] = (string) $x['bullets'];
            }
        }
        $data['experience'] = $exp;

        return $data;
    }

    /** Do the resume and the letter tell the same story? Each line is a plain check the customer can read. */
    public static function consistency(array $resume, array $l, string $company, string $role): array
    {
        $p = (array) ($resume['personal'] ?? []);
        $lp = (array) ($l['personal'] ?? []);
        $letter = (array) ($l['letter'] ?? []);
        $text = implode(' ', array_map('strval', array_intersect_key($letter, array_flip(['subject', 'opening', 'evidence', 'motivation', 'closing']))));
        $norm = fn ($s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
        $out = [];
        $out[] = ['ok' => $norm($lp['name'] ?? $p['name'] ?? '') === $norm($p['name'] ?? ''), 'text' => 'Your name is the same on both'];
        if (! empty($p['email'])) {
            $out[] = ['ok' => $norm($lp['email'] ?? $p['email']) === $norm($p['email']), 'text' => 'Your email is the same on both'];
        }
        if ($company !== '') {
            $out[] = ['ok' => mb_stripos($text, $company) !== false, 'text' => 'The letter names '.$company];
        }
        if ($role !== '') {
            $out[] = ['ok' => $norm($l['role'] ?? $role) === $norm($role) || mb_stripos($text, $role) !== false, 'text' => 'The letter is for the '.$role.' role'];
        }
        // employers and figures in the letter must be in the resume
        preg_match_all('/\d[\d,.]*%?/u', $text, $nums);
        $rt = AtsEngine::resumeText($resume);
        $new = array_values(array_filter(array_unique(array_map(fn ($n) => rtrim($n, '.,'), $nums[0])), fn ($n) => $n !== '' && mb_strpos($rt, $n) === false));
        $out[] = ['ok' => ! $new, 'text' => $new ? 'Check these figures, they are not in your resume: '.implode(', ', array_slice($new, 0, 5)) : 'Every figure in the letter is in your resume'];
        return $out;
    }

    /** Calls the customer API as this customer, with every check it normally makes. */
    private static function call(User $u, string $method, string $path, array $data = []): array
    {
        $req = Request::create('/api/'.$path, $method, $data, [], [], ['HTTP_ACCEPT' => 'application/json']);
        $req->setUserResolver(fn () => $u);
        try {
            $res = app(ApiController::class)->run($req, $path);
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => (string) (collect($e->errors())->flatten()->first() ?: 'That was not accepted.')];
        } catch (HttpExceptionInterface $e) {
            return ['ok' => false, 'error' => $e->getMessage() ?: 'That was not allowed.'];
        }
        $j = json_decode((string) $res->getContent(), true) ?: [];
        if ($res->getStatusCode() >= 400 || ($j['ok'] ?? true) === false) {
            return ['ok' => false, 'error' => (string) (($j['error'] ?? '') ?? $j['message'] ?? 'That did not work.')];
        }

        return ['ok' => true] + $j;
    }
}
