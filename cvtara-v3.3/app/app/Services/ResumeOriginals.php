<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 3.61.0 The original file a customer uploaded to build a resume (V7 §11 R1). Kept in private storage for 90 days so
 * the customer can download it again; the customer can delete it at any time; it is erased with the account and when
 * the 90 days end (daily cron). Only the customer can read it. One file per resume: a new upload replaces the old one.
 */
class ResumeOriginals
{
    public const DAYS = 90;

    public const MAX_BYTES = 5 * 1024 * 1024;

    private const MIME = ['pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'doc' => 'application/msword', 'txt' => 'text/plain; charset=utf-8'];

    public static function ready(): bool
    {
        return Schema::hasTable('resume_originals');
    }

    /** What the file really is, from its first bytes (the name and the browser's type are not trusted). */
    public static function kind(string $bytes): ?string
    {
        if (str_starts_with($bytes, '%PDF-')) {
            return 'pdf';
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            return str_contains(substr($bytes, 0, 4000), '[Content_Types].xml') || str_contains($bytes, 'word/') ? 'docx' : null;
        }
        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            return 'doc';
        }
        if (! str_contains($bytes, "\0") && mb_check_encoding($bytes, 'UTF-8')) {
            return 'txt';
        }

        return null;
    }

    public static function store(User $u, string $resumeId, string $base64, string $fileName): array
    {
        abort_unless(self::ready(), 503);
        abort_unless(DB::table('resume_drafts')->where('id', $resumeId)->where('owner_id', $u->id)->exists(), 404);
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['file' => 'The file could not be read.']);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'The file is larger than 5 MB, so it was not kept. Your resume was still filled in.']);
        }
        $kind = self::kind($bytes);
        if (! $kind) {
            throw ValidationException::withMessages(['file' => 'Only PDF, Word and text files are kept.']);
        }
        $name = trim(preg_replace('/[^\pL\pN ._()\-]+/u', '_', basename(str_replace('\\', '/', $fileName))), ' ._') ?: 'resume';
        $name = mb_substr(preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name), 0, 150).'.'.$kind;
        $id = (string) Str::uuid();
        $path = 'originals/'.$u->id.'/'.$id.'.'.$kind;
        Storage::disk('local')->put($path, $bytes);
        foreach (DB::table('resume_originals')->where('owner_id', $u->id)->where('resume_id', $resumeId)->get() as $old) {
            self::erase($old);
        }
        DB::table('resume_originals')->insert(['id' => $id, 'owner_id' => $u->id, 'resume_id' => $resumeId, 'file_name' => $name, 'kind' => $kind, 'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'path' => $path, 'expires_at' => now()->addDays(self::DAYS), 'created_at' => now(), 'updated_at' => now()]);
        Settings::audit($u->id, 'resume_original_kept', $id);

        return self::row(DB::table('resume_originals')->where('id', $id)->first());
    }

    public static function list(int $uid): array
    {
        if (! self::ready()) {
            return [];
        }

        return DB::table('resume_originals as o')->leftJoin('resume_drafts as d', 'd.id', '=', 'o.resume_id')->where('o.owner_id', $uid)->where('o.expires_at', '>', now())
            ->orderByDesc('o.created_at')->get(['o.*', 'd.title as resume_title'])->map(fn ($r) => self::row($r))->all();
    }

    private static function row(object $r): array
    {
        return ['id' => $r->id, 'resumeId' => $r->resume_id, 'resumeTitle' => $r->resume_title ?? null, 'fileName' => $r->file_name, 'kind' => $r->kind, 'size' => (int) $r->size,
            'uploadedAt' => (string) $r->created_at, 'deleteOn' => substr((string) $r->expires_at, 0, 10)];
    }

    public static function download(User $u, string $id)
    {
        $r = self::ready() ? DB::table('resume_originals')->where('id', $id)->where('owner_id', $u->id)->where('expires_at', '>', now())->first() : null;
        abort_unless($r && Storage::disk('local')->exists($r->path), 404);

        return response(Storage::disk('local')->get($r->path), 200, ['Content-Type' => self::MIME[$r->kind] ?? 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $r->file_name).'"', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public static function delete(User $u, string $id): void
    {
        $r = self::ready() ? DB::table('resume_originals')->where('id', $id)->where('owner_id', $u->id)->first() : null;
        abort_unless($r, 404);
        self::erase($r);
        Settings::audit($u->id, 'resume_original_deleted', $id);
    }

    private static function erase(object $r): void
    {
        Storage::disk('local')->delete($r->path);
        DB::table('resume_originals')->where('id', $r->id)->delete();
    }

    /** Every file of one customer (account deletion). */
    public static function eraseOwner(int $uid): void
    {
        if (self::ready()) {
            foreach (DB::table('resume_originals')->where('owner_id', $uid)->get() as $r) {
                self::erase($r);
            }
        }
    }

    /** Daily cron: files past their 90 days are erased. */
    public static function purge(): int
    {
        if (! self::ready()) {
            return 0;
        }
        $n = 0;
        foreach (DB::table('resume_originals')->where('expires_at', '<=', now())->limit(500)->get() as $r) {
            self::erase($r);
            $n++;
        }

        return $n;
    }
}
