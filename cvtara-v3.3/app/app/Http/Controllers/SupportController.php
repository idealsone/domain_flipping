<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Feedback, bug reports and screenshots from the floating "Feedback" button in the customer portal. Signed-in customers send; only the owner reads. */
class SupportController extends Controller
{
    public const AREAS = [
        'resume-builder' => 'Resume Builder', 'ats-match' => 'ATS Match', 'cover-letters' => 'Cover Letters', 'applications' => 'Applications',
        'downloads' => 'Downloads & credits', 'billing' => 'Billing & Plans', 'account' => 'Account & login', 'website' => 'Website & pages', 'other' => 'Something else',
    ];

    public const KINDS = ['bug' => 'Something is broken', 'idea' => 'Suggestion', 'question' => 'Question', 'praise' => 'What I like'];

    private const MAX_FILES = 5;

    private const MAX_KB = 2048;

    public function options()
    {
        return response()->json(['ok' => true, 'areas' => self::AREAS, 'kinds' => self::KINDS, 'maxFiles' => self::MAX_FILES, 'maxKb' => self::MAX_KB]);
    }

    public function submit(Request $r)
    {
        // A body larger than the server accepts arrives empty; say so instead of a confusing "message required".
        $limit = $this->bytes(ini_get('post_max_size'));
        if ($limit > 0 && (int) $r->server('CONTENT_LENGTH', 0) > $limit) {
            return response()->json(['ok' => false, 'error' => 'These screenshots are too large to send together. Use fewer or smaller images.'], 413);
        }
        $u = $r->user();
        abort_unless($u, 401);
        $key = 'support:u'.$u->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['ok' => false, 'error' => 'You have sent several reports already. Please wait a while before sending another.'], 429);
        }
        $v = $r->validate([
            'area' => 'required|in:'.implode(',', array_keys(self::AREAS)),
            'kind' => 'required|in:'.implode(',', array_keys(self::KINDS)),
            'message' => 'required|string|min:10|max:4000',
            'rating' => 'nullable|integer|min:1|max:5',
            'page' => 'nullable|string|max:300',
            'viewport' => ['nullable', 'regex:/^[0-9]{2,5}x[0-9]{2,5}$/'],
            'images' => 'nullable|array|max:'.self::MAX_FILES,
            'images.*' => 'file|mimes:jpg,jpeg,png,webp|max:'.self::MAX_KB,
            'company_site' => 'nullable|max:0',
        ], [
            'message.min' => 'Please tell us a little more (at least 10 characters).',
            'images.max' => 'You can attach up to '.self::MAX_FILES.' screenshots.',
            'images.*.max' => 'Each screenshot must be 2 MB or smaller.',
            'images.*.mimes' => 'Screenshots must be JPG, PNG or WebP images.',
            'images.*.file' => 'One of the screenshots could not be uploaded. Try a smaller image.',
            'images.*.uploaded' => 'Each screenshot must be 2 MB or smaller.',
        ]);
        $files = $r->file('images', []);
        $prepared = [];
        foreach ($files as $f) {
            $prepared[] = $this->prepare($f);
        }
        $id = (string) Str::uuid();
        $page = preg_replace('/[^\x20-\x7E]/', '', (string) ($v['page'] ?? ''));
        $page = (string) parse_url($page, PHP_URL_PATH).(parse_url($page, PHP_URL_QUERY) ? '?'.parse_url($page, PHP_URL_QUERY) : '');
        $access = Settings::access($u->id);
        $plan = $access['paid'] ? (Settings::plans()[$access['plan']]['name'] ?? $access['plan']) : 'Free';
        DB::transaction(function () use ($id, $u, $v, $page, $r, $prepared, $plan) {
            DB::table('support_reports')->insert([
                'id' => $id, 'owner_id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'area' => $v['area'], 'kind' => $v['kind'], 'page' => mb_substr($page, 0, 300),
                'message' => $v['message'], 'rating' => $v['rating'] ?? null, 'status' => 'new', 'user_agent' => mb_substr((string) $r->userAgent(), 0, 255), 'viewport' => $v['viewport'] ?? null, 'plan' => $plan, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($prepared as $img) {
                $path = 'support/'.$id.'/'.Str::uuid().'.webp';
                Storage::disk('local')->put($path, $img['data']);
                DB::table('support_attachments')->insert(['id' => (string) Str::uuid(), 'report_id' => $id, 'path' => $path, 'bytes' => strlen($img['data']), 'width' => $img['w'], 'height' => $img['h'], 'created_at' => now()]);
            }
        });
        RateLimiter::hit($key, 3600);
        Settings::audit($u->id, 'support_report', $id);

        return response()->json(['ok' => true, 'id' => substr($id, 0, 8), 'message' => 'Thank you — your feedback reached our team.']);
    }

    private function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Validates the real image data, then stores a fresh WebP copy: metadata and anything hidden in the original file are dropped. */
    private function prepare(\Illuminate\Http\UploadedFile $f): array
    {
        $bad = \Illuminate\Validation\ValidationException::withMessages(['images' => 'One of the screenshots could not be read. Try saving it again as a PNG or JPG.']);
        $info = @getimagesize($f->getRealPath());
        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 50000000 || ! function_exists('imagewebp')) {
            throw $bad;
        }
        $im = @imagecreatefromstring((string) file_get_contents($f->getRealPath()));
        if (! $im) {
            throw $bad;
        }
        [$w, $h] = [imagesx($im), imagesy($im)];
        $scale = min(1, 2000 / max($w, $h));
        if ($scale < 1) {
            $im = imagescale($im, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))) ?: $im;
            [$w, $h] = [imagesx($im), imagesy($im)];
        }
        imagepalettetotruecolor($im);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        ob_start();
        imagewebp($im, null, 82);
        $data = (string) ob_get_clean();
        imagedestroy($im);
        if ($data === '') {
            throw $bad;
        }

        return ['data' => $data, 'w' => $w, 'h' => $h];
    }
}
