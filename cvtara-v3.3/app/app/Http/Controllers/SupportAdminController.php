<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Owner side of the feedback button: inbox, status, notes and screenshots. Reached through /api/admin/support… */
class SupportAdminController extends Controller
{
    public const ROUTES = '#^support(/.*)?$#';

    private const STATUSES = ['new', 'seen', 'working', 'resolved', 'closed'];

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        if ($path === 'support/summary' && $r->isMethod('GET')) {
            $days = in_array((int) $r->query('days', 30), [7, 30, 90], true) ? (int) $r->query('days', 30) : 30;
            $since = now()->subDays($days);
            $q = fn () => DB::table('support_reports');

            return $ok([
                'days' => $days,
                'byStatus' => $q()->groupBy('status')->selectRaw('status, count(*) c')->pluck('c', 'status'),
                'recent' => ['total' => $q()->where('created_at', '>=', $since)->count(), 'byKind' => $q()->where('created_at', '>=', $since)->groupBy('kind')->selectRaw('kind, count(*) c')->pluck('c', 'kind'), 'byArea' => $q()->where('created_at', '>=', $since)->groupBy('area')->selectRaw('area, count(*) c')->pluck('c', 'area'),
                    'avgRating' => ($a = $q()->where('created_at', '>=', $since)->whereNotNull('rating')->avg('rating')) ? round((float) $a, 1) : null],
                'areas' => SupportController::AREAS, 'kinds' => SupportController::KINDS,
            ]);
        }
        if ($path === 'support' && $r->isMethod('GET')) {
            $q = DB::table('support_reports as s')->leftJoin('users as u', 'u.id', '=', 's.owner_id');
            if (in_array($r->query('status'), self::STATUSES, true)) {
                $q->where('s.status', $r->query('status'));
            } elseif ($r->query('status') === 'open') {
                $q->whereIn('s.status', ['new', 'seen', 'working']);
            }
            foreach (['area' => SupportController::AREAS, 'kind' => SupportController::KINDS] as $k => $set) {
                if (isset($set[$r->query($k)])) {
                    $q->where('s.'.$k, $r->query($k));
                }
            }
            if (($term = trim((string) $r->query('q'))) !== '') {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($term, 0, 80)).'%';
                $q->where(fn ($w) => $w->where('s.message', 'like', $like)->orWhere('s.email', 'like', $like)->orWhere('s.name', 'like', $like)->orWhere('s.page', 'like', $like));
            }
            $total = (clone $q)->count();
            $per = min(50, max(5, (int) $r->query('per', 20)));
            $page = max(1, (int) $r->query('page', 1));
            $rows = $q->orderByRaw("case s.status when 'new' then 0 when 'seen' then 1 when 'working' then 2 else 3 end")->orderByDesc('s.created_at')->offset(($page - 1) * $per)->limit($per)
                ->get(['s.id', 's.owner_id', 's.name', 's.email', 's.area', 's.kind', 's.page', 's.message', 's.rating', 's.status', 's.plan', 's.created_at', 's.resolved_at']);
            $counts = DB::table('support_attachments')->whereIn('report_id', $rows->pluck('id'))->groupBy('report_id')->selectRaw('report_id, count(*) c')->pluck('c', 'report_id');

            return $ok(['total' => $total, 'page' => $page, 'per' => $per, 'rows' => $rows->map(fn ($x) => [...(array) $x, 'message' => mb_substr($x->message, 0, 220), 'images' => (int) ($counts[$x->id] ?? 0)])->all()]);
        }
        if (preg_match('#^support/file/([a-f0-9-]{36})$#', $path, $m) && $r->isMethod('GET')) {
            $a = DB::table('support_attachments')->where('id', $m[1])->first();
            abort_unless($a && Storage::disk('local')->exists($a->path), 404);

            return response(Storage::disk('local')->get($a->path), 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600', 'Content-Disposition' => 'inline; filename="screenshot.webp"', 'Content-Security-Policy' => "default-src 'none'"]);
        }
        if (preg_match('#^support/([a-f0-9-]{36})$#', $path, $m)) {
            $row = DB::table('support_reports')->where('id', $m[1])->first();
            abort_unless($row, 404);
            if ($r->isMethod('GET')) {
                if ($row->status === 'new') {
                    DB::table('support_reports')->where('id', $row->id)->update(['status' => 'seen', 'updated_at' => now()]);
                    $row->status = 'seen';
                }
                $files = DB::table('support_attachments')->where('report_id', $row->id)->orderBy('created_at')->get(['id', 'width', 'height', 'bytes'])->all();
                $history = $row->owner_id ? DB::table('support_reports')->where('owner_id', $row->owner_id)->where('id', '!=', $row->id)->count() : 0;

                return $ok(['report' => (array) $row, 'images' => $files, 'earlierFromSamePerson' => $history]);
            }
            $v = $r->validate(['action' => 'required|in:update,delete', 'status' => 'nullable|in:'.implode(',', self::STATUSES), 'adminNote' => 'nullable|string|max:3000']);
            if ($v['action'] === 'delete') {
                foreach (DB::table('support_attachments')->where('report_id', $row->id)->pluck('path') as $p) {
                    Storage::disk('local')->delete($p);
                }
                DB::table('support_attachments')->where('report_id', $row->id)->delete();
                DB::table('support_reports')->where('id', $row->id)->delete();
                Settings::audit($id, 'support_report_deleted', $row->id);

                return $ok();
            }
            $set = ['updated_at' => now()];
            if (! empty($v['status'])) {
                $set['status'] = $v['status'];
                $set['resolved_at'] = in_array($v['status'], ['resolved', 'closed'], true) ? ($row->resolved_at ?? now()) : null;
            }
            if (array_key_exists('adminNote', $v)) {
                $set['admin_note'] = $v['adminNote'];
            }
            DB::table('support_reports')->where('id', $row->id)->update($set);
            Settings::audit($id, 'support_report_updated', $row->id);

            return $ok();
        }
        abort(404);
    }
}
