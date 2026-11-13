<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\Interviews;
use App\Services\Settings;
use App\Services\WhatsNew;
use Illuminate\Http\Request;

/**
 * 3.49.0 Owner side of interview practice: the question bank (approve, edit as a new version, retire), the on/off switch
 * and limits, recent sessions with customer challenges, and complimentary mock interviews. Reached through
 * /api/admin/interviews… (permissions in config/permissions.php).
 */
class InterviewAdminController extends Controller
{
    public const ROUTES = '#^interviews(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $u = $r->user();
        if ($path === 'interviews' && $r->isMethod('GET')) {
            $f = $r->validate(['occupation' => 'nullable|string|max:80', 'status' => 'nullable|in:draft,approved,retired']);

            return $ok(Interviews::adminList($f));
        }
        if ($path === 'interviews/questions' && $r->isMethod('GET')) {
            return $ok(Interviews::questionsPage($r));
        }
        if ($path === 'interviews/sessions' && $r->isMethod('GET')) {
            return $ok(Interviews::sessionsPage($r));
        }
        // 3.73.0 the large question bank: patterns, sample questions, approval and the on/off switch.
        if ($path === 'interviews/bank/patterns' && $r->isMethod('GET')) {
            return $ok(\App\Services\InterviewBank::patternsPage($r));
        }
        if (preg_match('#^interviews/bank/patterns/([PR]\d{2})$#', $path, $m) && $r->isMethod('GET')) {
            return $ok(\App\Services\InterviewBank::samples($m[1]));
        }
        if ($path === 'interviews/bank/status' && $r->isMethod('POST')) {
            $v = $r->validate(['codes' => 'nullable|array|max:200', 'codes.*' => 'string|max:8', 'all' => 'sometimes|boolean', 'status' => 'required|in:approved,withdrawn']);
            abort_if(empty($v['all']) && empty($v['codes']), 422, 'Choose at least one pattern.');
            $n = \App\Services\InterviewBank::setStatus($u, ! empty($v['all']) ? '*' : $v['codes'], $v['status']);

            return $ok(['changed' => $n] + Interviews::adminList([]));
        }
        if ($path === 'interviews/bank/settings' && $r->isMethod('POST')) {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['enabled' => 'required|boolean']);
            \App\Services\InterviewBank::saveSettings($v);
            Settings::audit($u->id, 'interview_bank_settings', $v['enabled'] ? 'on' : 'off');

            return $ok(Interviews::adminList([]));
        }
        if ($path === 'interviews/bank/sync' && $r->isMethod('POST')) {
            $p = \App\Services\InterviewBank::syncPatterns();
            $ro = \App\Services\InterviewBank::syncRoles();

            return $ok(['addedPatterns' => $p, 'addedRoles' => $ro] + Interviews::adminList([]));
        }
        if ($path === 'interviews/template' && $r->isMethod('GET')) {
            return response(Interviews::importTemplate(), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="interview-questions-blank.csv"']);
        }
        abort_unless($r->isMethod('POST'), 405);
        if ($path === 'interviews/import') {
            $v = $r->validate(['text' => 'nullable|string|max:3000000', 'sheet' => 'nullable|string|max:11000000', 'approve' => 'sometimes|boolean']);
            if (! empty($v['sheet'])) {
                // An Excel (.xlsx) file, sent as base64: its first sheet becomes tab-separated rows.
                $v['text'] = \App\Services\Outreach::sheetText((string) base64_decode($v['sheet'], true));
                abort_if($v['text'] === '', 422, 'That Excel file could not be read. Save it as .xlsx or CSV and try again.');
            }
            abort_if(trim((string) ($v['text'] ?? '')) === '', 422, 'Choose a file first.');
            $res = Interviews::import($u, (string) $v['text'], (bool) ($v['approve'] ?? false));
            Settings::audit($u->id, 'interview_questions_imported', $res['added'].' added, '.$res['approved'].' approved');

            return $ok(['import' => $res] + Interviews::adminList([]));
        }
        if ($path === 'interviews/seed') {
            return $ok(['added' => Interviews::seed()] + Interviews::adminList([]));
        }
        if ($path === 'interviews/approve') {
            $v = $r->validate(['ids' => 'nullable|array|max:2000', 'ids.*' => 'integer', 'occupation' => 'nullable|string|max:80']);
            $n = Interviews::approve($u, $v['ids'] ?? [], $v['occupation'] ?? null);
            Settings::audit($u->id, 'interview_questions_approved', (string) $n);

            return $ok(['approved' => $n] + Interviews::adminList([]));
        }
        if (preg_match('#^interviews/questions/(\d+)(/retire)?$#', $path, $m)) {
            if (! empty($m[2])) {
                Interviews::retire((int) $m[1]);

                return $ok(Interviews::adminList([]));
            }
            $v = $r->validate(['text' => 'required|string|min:10|max:600', 'guidance' => 'required|string|min:10|max:1200', 'example' => 'nullable|string|max:2000', 'source' => 'nullable|string|max:160', 'rights' => 'nullable|in:original,licensed,public']);
            abort_if(preg_match(WhatsNew::BANNED, $v['text'].' '.$v['guidance'].' '.($v['example'] ?? '')), 422, 'Customer-facing text never mentions AI or the tools behind it. Please reword it.');
            Interviews::edit($u, (int) $m[1], $v);

            return $ok(Interviews::adminList([]));
        }
        if ($path === 'interviews/settings') {
            AccountSecurity::requireRecent($r);
            $v = $r->validate(['enabled' => 'required|boolean', 'questions' => 'nullable|integer|min:3|max:10', 'dailySessions' => 'nullable|integer|min:1|max:20', 'fullMock' => 'sometimes|boolean',
                'minutes' => 'nullable|integer|min:10|max:60', 'releaseDelay' => 'nullable|integer|min:0|max:120', 'modelAnswersDaily' => 'nullable|integer|min:0|max:50']);

            return $ok(['settings' => Interviews::saveSettings(array_filter($v, fn ($x) => $x !== null)), 'available' => Interviews::available()]);
        }
        if ($path === 'interviews/grant') {
            $v = $r->validate(['email' => 'required|email|max:190', 'count' => 'required|integer|min:1|max:50', 'reason' => 'required|string|min:3|max:300']);
            $target = User::where('email', mb_strtolower(trim($v['email'])))->first();
            abort_unless($target, 404, 'No customer with that email.');
            Interviews::grant($u, $target, (int) $v['count'], $v['reason']);

            return $ok(['left' => \App\Services\UsageLedger::left($target->id, Interviews::RESOURCE, Interviews::FIELD)]);
        }
        abort(404);
    }
}
