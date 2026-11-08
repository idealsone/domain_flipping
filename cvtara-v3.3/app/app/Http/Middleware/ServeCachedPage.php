<?php

namespace App\Http\Middleware;

use App\Services\PageCache;
use Closure;
use Illuminate\Http\Request;

/**
 * Runs before the session starts. A visitor with no session cookie who asks for a public page that has a ready-made copy gets it
 * immediately: no session is created, no database is touched.
 */
class ServeCachedPage
{
    public function handle(Request $r, Closure $next)
    {
        if ($r->isMethod('GET') && $r->query() === [] && ! $r->cookies->has(config('session.cookie')) && ! $r->hasHeader('Authorization')) {
            $path = trim($r->path(), '/');
            $path = $path === '/' ? '' : $path;
            if (preg_match('#^(guides|calculators)/#', $path)) {
                $path = rtrim($path, '/').'/';
            }
            // An address without its trailing slash is answered by the controller with a permanent redirect.
            if (str_ends_with($path, '/') && ! str_ends_with($r->getPathInfo(), '/')) {
                return $next($r);
            }
            if (PageCache::enabledFast() && PageCache::pathAllowed($path) && ($html = PageCache::read($path)) !== null) {
                $res = response($html)->header('Cache-Control', 'no-cache')->setEtag(md5($html));
                $res->isNotModified($r);

                return $res;
            }
        }

        return $next($r);
    }
}
