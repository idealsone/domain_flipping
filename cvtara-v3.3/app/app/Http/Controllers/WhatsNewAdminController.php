<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\WhatsNew;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Owner side of "What's new": the dated notes customers see after an update. Reached through /api/admin/whats-new… */
class WhatsNewAdminController extends Controller
{
    public const ROUTES = '#^whats-new(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        if ($path === 'whats-new' && $r->isMethod('GET')) {
            return $ok(['entries' => WhatsNew::all(), 'tags' => WhatsNew::TAGS, 'stats' => $this->stats()]);
        }
        abort_unless($r->isMethod('POST') && $path === 'whats-new/save', $r->isMethod('POST') ? 404 : 405);
        AccountSecurity::requireRecent($r);
        $v = $r->validate([
            'entries' => 'present|array|max:100',
            'entries.*.id' => 'nullable|string|max:60',
            'entries.*.date' => 'required|date_format:Y-m-d',
            'entries.*.tag' => 'required|in:'.implode(',', array_keys(WhatsNew::TAGS)),
            'entries.*.title' => 'required|string|min:3|max:120',
            'entries.*.body' => 'required|string|min:3|max:600',
            'entries.*.published' => 'required|boolean',
        ], ['entries.*.title.required' => 'Every note needs a title.', 'entries.*.body.required' => 'Every note needs a short description.', 'entries.*.date.date_format' => 'Use a date like 2026-11-01.']);

        foreach ($v['entries'] as $e) {
            // customer-facing text says "CVTara team" and never names AI or the tools behind it (owner rule, 24 Oct 2026)
            abort_if(preg_match(WhatsNew::BANNED, $e['title'].' '.$e['body']), 422, 'Customer-facing text says "CVTara team" and never mentions AI or the tools behind it. Please reword "'.$e['title'].'".');
        }

        return $ok(['entries' => WhatsNew::save($v['entries'], $r->user()->id), 'stats' => $this->stats()]);
    }

    private function stats(): array
    {
        return ['readers30' => DB::table('activity_events')->where('event', WhatsNew::SEEN_EVENT)->where('created_at', '>=', now()->subDays(30))->distinct('owner_id')->count('owner_id')];
    }
}
