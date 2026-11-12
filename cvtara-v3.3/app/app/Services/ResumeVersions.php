<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Resume version history (3.52.0, V7 WS-05). A save never loses the previous content: it is copied here first. The copy
 * of a resume used for a job application is frozen, so later edits never change what was sent. Customers can look at
 * any earlier version and restore it (the content on screen is kept as a version first, so a restore can be undone).
 */
class ResumeVersions
{
    /** Edit versions kept per resume; application and restore copies are always kept. */
    public const KEEP = 30;

    public static function ready(): bool
    {
        static $ok = null;

        return $ok ??= Schema::hasTable('resume_versions');
    }

    /** Copies a resume row as it is now. Returns the version id. */
    public static function snapshot(object $row, string $kind = 'edit', ?string $applicationId = null): ?string
    {
        if (! self::ready()) {
            return null;
        }
        $id = (string) Str::uuid();
        DB::table('resume_versions')->insert(['id' => $id, 'resume_id' => $row->id, 'owner_id' => $row->owner_id, 'revision' => (int) $row->revision, 'kind' => $kind,
            'title' => mb_substr((string) $row->title, 0, 180), 'template' => mb_substr((string) $row->template, 0, 120), 'content_json' => (string) $row->content_json, 'application_id' => $applicationId, 'created_at' => now()]);
        if ($kind === 'edit') {
            // 3.65.0: the oldest version (the resume as it first arrived, "V1") is always kept, so its score stays visible.
            $firstId = DB::table('resume_versions')->where('resume_id', $row->id)->where('kind', 'edit')->orderBy('created_at')->orderBy('revision')->value('id');
            $old = DB::table('resume_versions')->where('resume_id', $row->id)->where('kind', 'edit')->where('id', '!=', $firstId)->orderByDesc('created_at')->orderByDesc('revision')->skip(self::KEEP)->take(500)->pluck('id');
            if ($old->isNotEmpty()) {
                DB::table('resume_versions')->whereIn('id', $old)->delete();
            }
        }

        return $id;
    }

    /** Called before a save overwrites a resume. */
    public static function beforeSave(int $ownerId, string $resumeId): void
    {
        $row = DB::table('resume_drafts')->where('id', $resumeId)->where('owner_id', $ownerId)->first();
        if ($row) {
            self::snapshot($row);
        }
    }

    /**
     * Freezes the resume an application uses once it is applied for (stage applied, interview or offer), and again if the
     * customer picks a different resume. A frozen copy is never changed.
     */
    public static function freezeForApplication(int $ownerId, string $applicationId): void
    {
        if (! self::ready()) {
            return;
        }
        $a = DB::table('applications')->where('id', $applicationId)->where('owner_id', $ownerId)->first();
        if (! $a || ! $a->resume_id || ! in_array($a->stage, ['applied', 'interview', 'offer'], true)) {
            return;
        }
        if ($a->resume_version_id && DB::table('resume_versions')->where('id', $a->resume_version_id)->value('resume_id') === $a->resume_id) {
            return;
        }
        $row = DB::table('resume_drafts')->where('id', $a->resume_id)->where('owner_id', $ownerId)->first();
        if ($row) {
            DB::table('applications')->where('id', $a->id)->update(['resume_version_id' => self::snapshot($row, 'application', $a->id)]);
        }
    }

    public static function list(int $ownerId, string $resumeId): array
    {
        abort_unless(DB::table('resume_drafts')->where('id', $resumeId)->where('owner_id', $ownerId)->exists(), 404);
        $apps = DB::table('applications')->where('owner_id', $ownerId)->whereNotNull('resume_version_id')->get(['resume_version_id', 'company', 'role'])->keyBy('resume_version_id');

        return DB::table('resume_versions')->where('resume_id', $resumeId)->where('owner_id', $ownerId)->orderByDesc('created_at')->orderByDesc('revision')->limit(100)
            ->get(['id', 'revision', 'kind', 'title', 'template', 'created_at'])
            ->map(fn ($v) => ['id' => $v->id, 'revision' => (int) $v->revision, 'kind' => $v->kind, 'title' => $v->title, 'savedAt' => (string) $v->created_at,
                'usedFor' => isset($apps[$v->id]) ? trim($apps[$v->id]->role.' · '.$apps[$v->id]->company, ' ·') : null])->all();
    }

    public static function show(int $ownerId, string $resumeId, string $versionId): array
    {
        $v = DB::table('resume_versions')->where('id', $versionId)->where('resume_id', $resumeId)->where('owner_id', $ownerId)->first();
        abort_unless($v, 404);

        return ['id' => $v->id, 'revision' => (int) $v->revision, 'kind' => $v->kind, 'savedAt' => (string) $v->created_at, 'data' => json_decode($v->content_json, true)];
    }

    /** Puts an earlier version back as the resume's current content (the current content is kept as a version first). */
    public static function restore(int $ownerId, string $resumeId, string $versionId): array
    {
        return DB::transaction(function () use ($ownerId, $resumeId, $versionId) {
            $row = DB::table('resume_drafts')->where('id', $resumeId)->where('owner_id', $ownerId)->lockForUpdate()->first();
            $v = DB::table('resume_versions')->where('id', $versionId)->where('resume_id', $resumeId)->where('owner_id', $ownerId)->first();
            abort_unless($row && $v, 404);
            self::snapshot($row, 'restore');
            $data = json_decode($v->content_json, true) ?: [];
            DB::table('resume_drafts')->where('id', $resumeId)->update(['content_json' => $v->content_json, 'title' => $v->title ?: $row->title, 'template' => $v->template ?: $row->template,
                'ats_score' => ResumeQuality::score($data)['score'], 'revision' => (int) $row->revision + 1, 'updated_at' => now()]);
            Settings::audit($ownerId, 'resume_restored', $resumeId.'|'.$versionId);

            return ['id' => $resumeId, 'revision' => (int) $row->revision + 1];
        });
    }
}
