<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Keeps the Applications tracker in step with documents a customer downloads. */
class Tracker
{
    /** @return string added|updated|skipped|full */
    public static function downloaded(int $uid, string $type, object $row, bool $creditUsed): string
    {
        $d = json_decode((string) $row->content_json, true) ?: [];
        $company = trim((string) ($row->company ?: ($d['company'] ?? '')));
        $role = trim((string) ($row->role ?: ($d['role'] ?? '')));
        if ($company === '' || $role === '') {
            return 'skipped';
        }
        $url = (string) (($row->job_url ?? '') ?: ($d['jobUrl'] ?? ''));
        if (! preg_match('#^https?://#i', $url) || strlen($url) > 1000) {
            $url = '';
        }
        $col = $type === 'resume' ? 'resume_credits' : 'letter_credits';
        $app = DB::table('applications')->where('owner_id', $uid)->whereRaw('lower(company) = ?', [mb_strtolower($company)])->whereRaw('lower(role) = ?', [mb_strtolower($role)])->first();
        if ($app) {
            $set = ['updated_at' => now()];
            if ($type === 'resume' && ! $app->resume_id) {
                $set['resume_id'] = $row->id;
            }
            if ($url !== '' && $app->job_url === '') {
                $set['job_url'] = $url;
            }
            if ($creditUsed) {
                $set[$col] = DB::raw($col.' + 1');
            }
            DB::table('applications')->where('id', $app->id)->update($set);

            return 'updated';
        }
        if (DB::table('applications')->where('owner_id', $uid)->count() >= (int) (Settings::get('features')['tracker']['limit'] ?? 500)) {
            return 'full';
        }
        DB::table('applications')->insert([
            'id' => (string) Str::uuid(), 'owner_id' => $uid, 'company' => mb_substr($company, 0, 180), 'role' => mb_substr($role, 0, 180), 'stage' => 'saved',
            'job_url' => $url, 'resume_id' => $type === 'resume' ? $row->id : null, 'revision' => 1, 'origin' => 'download',
            'resume_credits' => $type === 'resume' && $creditUsed ? 1 : 0, 'letter_credits' => $type === 'letter' && $creditUsed ? 1 : 0,
            'next_reminder_at' => now()->addDays(self::GAPS[0]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'added';
    }

    /** Days after an application is added until reminder 1, 2 and 3. After the third there are no more. */
    public const GAPS = [3, 4, 7];

    public const MAX = 3;

    /** A resume prepared for a job (from ATS Match or the tailor form) is added to the tracker right away. @return string added|updated|skipped|full */
    public static function prepared(int $uid, string $company, string $role, string $url, ?string $scanId, string $resumeId): string
    {
        $company = trim($company);
        $role = trim($role);
        if ($company === '' || $role === '') {
            return 'skipped';
        }
        if (! preg_match('#^https?://#i', $url) || strlen($url) > 1000) {
            $url = '';
        }
        $app = DB::table('applications')->where('owner_id', $uid)->whereRaw('lower(company) = ?', [mb_strtolower($company)])->whereRaw('lower(role) = ?', [mb_strtolower($role)])->first();
        if ($app) {
            DB::table('applications')->where('id', $app->id)->update(array_filter(['resume_id' => $app->resume_id ?: $resumeId, 'job_url' => $app->job_url === '' && $url !== '' ? $url : null, 'scan_id' => $app->scan_id ?: $scanId, 'updated_at' => now()], fn ($x) => $x !== null));

            return 'updated';
        }
        if (DB::table('applications')->where('owner_id', $uid)->count() >= (int) (Settings::get('features')['tracker']['limit'] ?? 500)) {
            return 'full';
        }
        DB::table('applications')->insert(['id' => (string) Str::uuid(), 'owner_id' => $uid, 'company' => mb_substr($company, 0, 180), 'role' => mb_substr($role, 0, 180), 'stage' => 'saved', 'job_url' => $url, 'resume_id' => $resumeId, 'revision' => 1, 'origin' => $scanId ? 'ats' : 'prepared', 'scan_id' => $scanId,
            'next_reminder_at' => now()->addDays(self::GAPS[0]), 'created_at' => now(), 'updated_at' => now()]);

        return 'added';
    }

    private const QUESTION = [
        'saved' => 'Have you applied yet? Mark it as Applied once you have.',
        'applied' => 'Have you heard back? Tell us if you have an interview, an offer or a rejection.',
        'interview' => 'How did the interview go? Update the stage when you know the outcome.',
    ];

    /** Sends the due status reminders: one in-app message and one email each, at most three per application. @return int reminders sent */
    public static function remind(): int
    {
        $n = 0;
        $due = DB::table('applications')->whereIn('origin', ['download', 'ats', 'prepared'])->where('reminders_off', false)->where('reminders_sent', '<', self::MAX)->whereNotNull('next_reminder_at')->where('next_reminder_at', '<=', now())->orderBy('next_reminder_at')->limit(300)->get();
        foreach ($due as $a) {
            $next = (int) $a->reminders_sent + 1;
            if (! isset(self::QUESTION[$a->stage])) {
                DB::table('applications')->where('id', $a->id)->update(['next_reminder_at' => null]);

                continue;
            }
            $u = \App\Models\User::find($a->owner_id);
            if (! $u || $u->blocked) {
                DB::table('applications')->where('id', $a->id)->update(['next_reminder_at' => null]);

                continue;
            }
            $status = ['saved' => 'Saved', 'applied' => 'Applied', 'interview' => 'Interview'][$a->stage];
            DB::table('notifications')->insertOrIgnore(['id' => (string) Str::uuid(), 'owner_id' => $a->owner_id, 'kind' => 'application', 'title' => $a->role.' at '.$a->company.': what is the status now?', 'body' => 'Marked "'.$status.'". '.self::QUESTION[$a->stage].' (Reminder '.$next.' of '.self::MAX.')', 'link' => '/workspace.html?view=tracker', 'ref' => $a->id.':'.$next, 'created_at' => now(), 'updated_at' => now()]);
            MailAutomation::queue($u, 'application_status', $a->id.':'.$next, ['company' => $a->company, 'role' => $a->role, 'status' => $status, 'n' => (string) $next, 'question' => self::QUESTION[$a->stage], 'tracker_url' => rtrim((string) config('app.url'), '/').'/workspace.html?view=tracker']);
            DB::table('applications')->where('id', $a->id)->update(['reminders_sent' => $next, 'next_reminder_at' => $next >= self::MAX ? null : now()->addDays(self::GAPS[$next])]);
            $n++;
        }

        return $n;
    }
}
