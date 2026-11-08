<?php

namespace App\Services;

use App\Http\Controllers\ContentController;
use Illuminate\Http\Request;

/**
 * Ready-made copies of the public pages. A visitor who is not signed in is given the finished HTML straight from disk (by the web
 * server when it can, by PHP otherwise), so a rush of visitors costs almost nothing: no database reads, no page assembly.
 * Pages are rebuilt automatically when the owner saves content, and on demand from Owner Console → Speed & traffic.
 */
class PageCache
{
    public static function root(): string
    {
        return public_path('_pages');
    }

    public static function settings(): array
    {
        return array_merge(['busyMode' => 'off', 'autoLoad' => 2.5], (array) Settings::get('speed', []), ['pageCache' => self::enabled()]);
    }

    /** The on/off switch is a marker file, so checking it never touches the database. */
    public static function enabled(): bool
    {
        return ! is_file(self::root().'/.disabled');
    }

    public static function enable(bool $on): void
    {
        if ($on) {
            @unlink(self::root().'/.disabled');

            return;
        }
        self::purge();
        if (! is_dir(self::root())) {
            @mkdir(self::root(), 0755, true);
        }
        @file_put_contents(self::root().'/.disabled', '1');
    }

    /** Cheap check used before the session starts. */
    public static function enabledFast(): bool
    {
        return self::enabled() && is_file(self::root().'/.stamp');
    }

    public static function pathAllowed(string $path): bool
    {
        return self::file($path) !== null && ! preg_match('#^(admin|workspace|checkout|setup|login|register|password|api|console|preview|export|webhooks|support|r/|article)#', $path);
    }

    /** Pages that look the same for every visitor. Signed-in pages, the owner area, checkout and anything with a query string are never stored. */
    public static function cacheable(Request $r, string $path): bool
    {
        return self::enabled() && $r->isMethod('GET') && ! $r->user() && $r->query() === [] && self::pathAllowed($path);
    }

    public static function file(string $path): ?string
    {
        if ($path !== '' && ! preg_match('#^[a-z0-9][a-z0-9/_.-]{0,160}$#', $path)) {
            return null;
        }
        if (str_contains($path, '..') || str_contains($path, '//')) {
            return null;
        }
        if ($path === '') {
            return self::root().'/index.html';
        }
        if (str_ends_with($path, '/')) {
            return self::root().'/'.$path.'index.html';
        }

        return str_ends_with($path, '.html') ? self::root().'/'.$path : null;
    }

    /** Changes with every release, so pages built by an older version are never served. */
    public static function stamp(): string
    {
        static $s;

        return $s ??= substr(sha1((string) @file_get_contents(base_path('../BUILD_INFO.json')).(string) @file_get_contents(base_path('BUILD_INFO.json')).config('app.url')), 0, 12);
    }

    public static function read(string $path): ?string
    {
        $f = self::file($path);
        if (! $f || ! is_file($f)) {
            return null;
        }
        $built = @file_get_contents(self::root().'/.stamp');
        if ($built !== self::stamp().':') {
            return null;
        }
        $html = @file_get_contents($f);

        return $html === false || $html === '' ? null : $html;
    }

    /** Headers the web server adds when it serves a stored page itself (PHP is not involved, so its security headers must be repeated here). */
    private static function htaccess(): string
    {
        $csp = str_replace('"', '\\"', \App\Http\Middleware\SecurityHeaders::CSP);

        return "# Written by CVTara. Pages in this folder are ready-made copies of the public pages.\n<IfModule mod_headers.c>\n"
            ."Header set Cache-Control \"no-cache\"\n"
            ."Header set X-Content-Type-Options \"nosniff\"\n"
            ."Header set Referrer-Policy \"strict-origin-when-cross-origin\"\n"
            ."Header set Permissions-Policy \"camera=(), microphone=(), geolocation=()\"\n"
            ."Header set Content-Security-Policy \"{$csp}\"\n"
            ."Header set Strict-Transport-Security \"max-age=31536000; includeSubDomains\"\n</IfModule>\n"
            ."<FilesMatch \"^\\.\">\nRequire all denied\n</FilesMatch>\n";
    }

    private static function prepareDir(): void
    {
        if (! is_dir(self::root())) {
            @mkdir(self::root(), 0755, true);
        }
        if (! is_file(self::root().'/.htaccess') || md5((string) @file_get_contents(self::root().'/.htaccess')) !== md5(self::htaccess())) {
            @file_put_contents(self::root().'/.htaccess', self::htaccess());
        }
    }

    /** Removes anything that belongs to one visitor, then stores the page. */
    public static function write(string $path, string $html): void
    {
        $f = self::file($path);
        if (! $f) {
            return;
        }
        $html = preg_replace('#(<meta name="csrf-token" content=")[^"]*(")#', '$1$2', $html);
        $html = preg_replace('#(<meta name="cvtara-user" content=")[^"]*(")#', '$1guest$2', $html);
        $dir = dirname($f);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return;
        }
        self::prepareDir();
        if (! is_file(self::root().'/.stamp')) {
            @file_put_contents(self::root().'/.stamp', self::stamp().':');
        }
        $tmp = $f.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (@file_put_contents($tmp, $html) !== false) {
            @rename($tmp, $f);
        }
        @unlink($tmp);
    }

    public static function purge(): int
    {
        $n = 0;
        $root = self::root();
        if (! is_dir($root)) {
            return 0;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } elseif (! in_array($f->getFilename(), ['.disabled', '.htaccess'], true) && @unlink($f->getPathname())) {
                $n++;
            }
        }

        return $n;
    }

    public static function stats(): array
    {
        $root = self::root();
        $n = 0;
        $bytes = 0;
        $built = null;
        if (is_dir($root)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.html')) {
                    $n++;
                    $bytes += $f->getSize();
                    $built = max($built ?? 0, $f->getMTime());
                }
            }
        }

        return ['pages' => $n, 'bytes' => $bytes, 'builtAt' => $built ? date('c', $built) : null, 'enabled' => self::enabled()];
    }

    /** Every public page a visitor can open. */
    public static function paths(): array
    {
        $out = [''];
        foreach (glob(resource_path('frontend/*.html')) ?: [] as $f) {
            $name = basename($f);
            if (preg_match('#^(admin|workspace|checkout|setup|index|article)#', $name)) {
                continue;
            }
            $out[] = $name;
        }
        foreach (app(ContentController::class)->pages() as $p) {
            $path = ltrim($p['path'], '/');
            if ($path !== '' && preg_match('#^(guides|calculators)/#', $path)) {
                $out[] = $path;
            }
        }
        foreach (SeoPages::paths() as $path) {
            $out[] = $path;
        }

        return array_values(array_unique($out));
    }

    /** Renders every public page and stores it. Returns counts. */
    public static function build(): array
    {
        @set_time_limit(600);
        self::purge();
        self::prepareDir();
        @file_put_contents(self::root().'/.stamp', self::stamp().':');
        $ok = 0;
        $failed = [];
        foreach (self::paths() as $path) {
            $p = $path;
            if ($path !== '' && preg_match('#^(guides|calculators)/#', $path)) {
                $p = rtrim($path, '/').'/';
            }
            try {
                $req = Request::create('/'.$p, 'GET');
                $res = app(ContentController::class)->show($req, rtrim($p, '/') === $p ? $p : $p);
                if ($res->getStatusCode() === 200) {
                    self::write($p, (string) $res->getContent());
                    $ok++;
                }
            } catch (\Throwable $e) {
                $failed[] = $p;
            }
        }

        return ['built' => $ok, 'skipped' => $failed];
    }
}
