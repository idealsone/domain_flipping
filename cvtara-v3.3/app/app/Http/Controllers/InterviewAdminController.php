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
        abort_unless($r->isMethod('POST'), 405);
        if ($path === 'interviews/seed') {
            return $ok(['added' => Interviews::seed()] + Interviews::adminList([]));
        }
        if ($path === 'interviews/approve') {
            $v = $r->validate(['ids' => 'nullable|array|max:500', 'ids.*' => 'integer', 'occupation' => 'nullable|string|max:80']);
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
            $v = $r->validate(['enabled' => 'required|boolean', 'questions' => 'nullable|integer|min:3|max:8', 'dailySessions' => 'nullable|integer|min:1|max:20']);

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
