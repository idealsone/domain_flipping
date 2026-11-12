<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's resume journey on one card (3.65.0, owner approval 2026-11-12):
 *  - V1: the resume as it first arrived (the oldest kept version of the primary resume, which is the uploaded content
 *    when the customer imported a file), with its real ATS-friendly score. Never adjusted.
 *  - V2: the primary resume as it is now, with its score.
 *  - V3: one copy per job. A tailored version made from the primary, or the frozen copy an application used, plus the
 *    cover letter made for it. Editing the primary never changes these.
 * Reads existing tables only (resume_drafts, resume_versions, resume_originals, applications, cover_letter_drafts).
 */
class ResumeJourney
{
    public static function for(int $ownerId): array
    {
        $primary = DB::table('resume_drafts')->where('owner_id', $ownerId)->where('is_primary', true)->first();
        if (! $primary) {
            return ['primary' => null];
        }
        $now = json_decode((string) $primary->content_json, true) ?: [];
        $v2 = ResumeQuality::score($now)['score'];
        $first = ResumeVersions::ready() ? DB::table('resume_versions')->where('resume_id', $primary->id)->where('kind', 'edit')->orderBy('created_at')->orderBy('revision')->first(['content_json', 'created_at']) : null;
        $v1Doc = $first ? (json_decode((string) $first->content_json, true) ?: []) : $now;
        $v1 = ResumeQuality::score($v1Doc)['score'];
        $file = ResumeOriginals::ready() ? DB::table('resume_originals')->where('owner_id', $ownerId)->where('resume_id', $primary->id)->where('expires_at', '>', now())->orderBy('created_at')->first(['id', 'file_name']) : null;

        $copies = [];
        foreach (DB::table('resume_drafts')->where('owner_id', $ownerId)->where('parent_id', $primary->id)->orderByDesc('updated_at')->limit(30)->get(['id', 'title', 'company', 'role', 'content_json', 'updated_at']) as $r) {
            $copies[$r->id] = ['kind' => 'tailored', 'resumeId' => $r->id, 'company' => (string) $r->company, 'role' => (string) $r->role, 'title' => (string) $r->title,
                'score' => ResumeQuality::score(json_decode((string) $r->content_json, true) ?: [])['score'], 'date' => (string) $r->updated_at, 'stage' => null, 'frozen' => false, 'letterId' => null];
        }
        $apps = DB::table('applications')->where('owner_id', $ownerId)->whereNotNull('resume_id')->orderByDesc('updated_at')->limit(30)->get();
        foreach ($apps as $a) {
            $frozen = ! empty($a->resume_version_id);
            $item = ['kind' => 'application', 'applicationId' => $a->id, 'resumeId' => $a->resume_id, 'company' => (string) $a->company, 'role' => (string) $a->role, 'stage' => (string) $a->stage, 'frozen' => $frozen,
                'versionId' => $frozen ? $a->resume_version_id : null, 'date' => (string) ($a->applied_at ?: $a->updated_at), 'letterId' => null, 'score' => null];
            if (isset($copies[$a->resume_id])) {
                $copies[$a->resume_id] = array_merge($copies[$a->resume_id], array_filter($item, fn ($v) => $v !== null && $v !== ''), ['kind' => 'application']);
            } elseif ($a->resume_id === $primary->id || DB::table('resume_drafts')->where('id', $a->resume_id)->where('owner_id', $ownerId)->exists()) {
                $copies['app:'.$a->id] = $item;
            }
        }
        $letters = Schema::hasColumn('cover_letter_drafts', 'source_resume_id')
            ? DB::table('cover_letter_drafts')->where('owner_id', $ownerId)->orderByDesc('updated_at')->limit(60)->get(['id', 'source_resume_id', 'source_application_id'])
            : collect();
        foreach ($copies as &$c) {
            $l = $letters->first(fn ($x) => ($c['applicationId'] ?? null) && $x->source_application_id === ($c['applicationId'] ?? null))
                ?? $letters->first(fn ($x) => $x->source_resume_id === $c['resumeId'] && $c['resumeId'] !== $primary->id);
            $c['letterId'] = $l?->id;
        }
        unset($c);

        return [
            'primary' => ['id' => $primary->id, 'title' => (string) $primary->title, 'updatedAt' => (string) $primary->updated_at],
            'v1' => ['score' => $v1, 'date' => (string) ($first->created_at ?? $primary->created_at), 'file' => $file ? ['id' => $file->id, 'name' => $file->file_name] : null, 'same' => ! $first],
            'v2' => ['score' => $v2, 'gain' => $v2 - $v1],
            'v3' => array_values($copies),
        ];
    }
}

