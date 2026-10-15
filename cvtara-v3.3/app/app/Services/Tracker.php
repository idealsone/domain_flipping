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
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'added';
    }
}
