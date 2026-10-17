<?php

namespace App\Http\Controllers;

use App\Services\AccountSecurity;
use App\Services\JobReader;
use App\Services\PageCache;
use App\Services\Settings;
use App\Services\Traffic;
use Illuminate\Http\Request;

/** Owner side of "Speed & traffic": ready-made pages, busy mode and the job link reader. Reached through /api/admin/speed… */
class SpeedAdminController extends Controller
{
    public const ROUTES = '#^speed(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $id = $r->user()->id;
        if ($path === 'speed/status' && $r->isMethod('GET')) {
            $c = JobReader::config();

            return $ok([
                'cache' => PageCache::stats(),
                'jobLinks' => (bool) (((array) Settings::get('speed', []))['jobLinks'] ?? false),
                'traffic' => Traffic::status(),
                'reader' => ['enabled' => JobReader::enabled(), 'provider' => $c['provider'] ?? 'scrapingbee', 'usage' => JobReader::usage(), 'configured' => ['scrapingbee' => ! empty($c['scrapingbeeApiKey']), 'zenrows' => ! empty($c['zenrowsApiKey']), 'custom' => ! empty($c['customUrlTemplate'])], 'customUrlTemplate' => $c['customUrlTemplate'] ?? ''],
            ]);
        }
        abort_unless($r->isMethod('POST'), 405);
        AccountSecurity::requireRecent($r);
        if ($path === 'speed/save') {
            $v = $r->validate(['busyMode' => 'required|in:off,on,auto', 'autoLoad' => 'required|numeric|min:0.5|max:50', 'pageCache' => 'required|boolean', 'jobLinks' => 'nullable|boolean']);
            $cur = (array) Settings::get('speed', []);
            Settings::put('speed', array_merge($cur, ['busyMode' => $v['busyMode'], 'autoLoad' => (float) $v['autoLoad'], 'jobLinks' => (bool) ($v['jobLinks'] ?? ($cur['jobLinks'] ?? false))]));
            PageCache::enable((bool) $v['pageCache']);
            Settings::audit($id, 'speed_settings_saved', $v['busyMode'].($v['pageCache'] ? ':cache' : ':nocache').(($v['jobLinks'] ?? false) ? ':joblinks' : ''));

            return $ok(['traffic' => Traffic::status(), 'cache' => PageCache::stats()]);
        }
        if ($path === 'speed/rebuild') {
            $res = PageCache::build();
            Settings::audit($id, 'pages_prebuilt', (string) $res['built']);

            return $ok(['result' => $res, 'cache' => PageCache::stats()]);
        }
        if ($path === 'speed/refresh') {
            // One click: drop the ready-made pages, compiled views and cached settings, then build every page fresh.
            $removed = PageCache::purge();
            foreach (['view:clear', 'route:clear', 'config:clear'] as $cmd) {
                try {
                    \Illuminate\Support\Facades\Artisan::call($cmd);
                } catch (\Throwable $e) {
                    // a missing cache folder is not a problem
                }
            }
            foreach (['public-activity', 'public-config', 'site-settings'] as $k) {
                \Illuminate\Support\Facades\Cache::forget($k);
            }
            $res = PageCache::build();
            Settings::audit($id, 'cache_refreshed', $removed.':'.$res['built']);

            return $ok(['removed' => $removed, 'result' => $res, 'cache' => PageCache::stats()]);
        }
        if ($path === 'speed/purge') {
            $n = PageCache::purge();
            Settings::audit($id, 'pages_cache_cleared', (string) $n);

            return $ok(['removed' => $n, 'cache' => PageCache::stats()]);
        }
        if ($path === 'speed/test-reader') {
            $v = $r->validate(['url' => 'required|url:http,https|max:1000']);
            abort_unless(JobReader::enabled(), 422, 'Switch the job reader on and save its key first.');
            try {
                $html = JobReader::read($v['url']);
            } catch (\Throwable $e) {
                abort(422, 'The reading service did not return the page: '.mb_substr($e->getMessage(), 0, 120));
            }

            return $ok(['bytes' => strlen($html), 'message' => 'The reading service returned '.number_format(strlen($html)).' bytes.']);
        }
        abort(404);
    }
}
