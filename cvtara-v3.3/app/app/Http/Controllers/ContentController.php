<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContentController extends Controller
{
    public function pages(): array
    {
        $rows = [];
        $custom = Settings::get('blog');
        $seo = Settings::get('seo');
        $catalog = json_decode(file_get_contents(resource_path('data/catalog.json')), true);
        $base = ['/' => 'CVTara — Resume and cover-letter builder', '/templates.html' => 'Resume templates', '/cover-letters.html' => 'Cover-letter templates', '/blog.html' => 'Career guides & calculators', '/about.html' => 'About CVTara', '/contact.html' => 'Contact CVTara', '/privacy.html' => 'Privacy policy', '/terms.html' => 'Terms & conditions', '/refund.html' => 'Refund policy'];
        foreach ($base as $path => $title) {
            $rows[] = ['path' => $path, 'title' => $seo['pages'][$path]['title'] ?? $title, 'description' => $seo['pages'][$path]['description'] ?? 'CVTara career preparation tools.', 'included' => $seo['pages'][$path]['includeInSitemap'] ?? true];
        }foreach ($catalog['blog'] as $p) {
            $slug = $p['slug'];
            $override = $custom[$slug] ?? [];
            if (($override['published'] ?? true) === false) {
                continue;
            }$p = array_merge($p, $override);
            $path = '/'.($p['type'] === 'Calculator' ? 'calculators' : 'guides').'/'.$slug.'/';
            $rows[] = ['path' => $path, 'title' => $p['title'], 'description' => $p['description'], 'included' => $seo['pages'][$path]['includeInSitemap'] ?? true];
            unset($custom[$slug]);
        }foreach ($custom as $slug => $p) {
            if ($p['published']) {
                $rows[] = ['path' => '/guides/'.$slug.'/', 'title' => $p['title'], 'description' => $p['description'], 'included' => true];
            }
        }

        return $rows;
    }

    public function audit(): array
    {
        return array_map(function ($p) {
            $slug = preg_replace('#^/(guides|calculators)/|/$#', '', $p['path']);
            $post = Settings::get('blog')[$slug] ?? [];
            $body = $post['body'] ?? '';
            $primary = trim(explode(',', $post['keywords'] ?? str_replace('-', ' ', basename($slug)))[0]);
            $n = fn ($v) => $primary ? substr_count(mb_strtolower($v), mb_strtolower($primary)) : 0;
            $words = str_word_count(strip_tags($body));

            return [...$p, 'primary' => $primary, 'titleHits' => $n($p['title']), 'descriptionHits' => $n($p['description']), 'headingHits' => $n(implode(' ', preg_grep('/^#+ /', explode("\n", $body)))), 'bodyHits' => $n($body), 'altHits' => $n($post['imageAlt'] ?? ''), 'wordCount' => $words, 'score' => (int) (($p['title'] ? 20 : 0) + ($p['description'] ? 20 : 0) + ($words > 250 ? 20 : 0) + (! empty($post['imageAlt']) ? 20 : 0) + (! empty($post['faq']) ? 20 : 0))];
        }, $this->pages());
    }

    public function show(Request $r, string $path = '')
    {
        if ($path === 'sitemap.xml') {
            $seo = Settings::get('seo');
            abort_unless($seo['sitemapEnabled'], 404);
            $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($this->pages() as $p) {
                if ($p['included']) {
                    $xml .= '<url><loc>'.e(rtrim($seo['siteUrl'] ?: config('app.url'), '/').$p['path']).'</loc></url>';
                }
            }

            return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml']);
        }
        if ($path === 'robots.txt') {
            return response("User-agent: *\n".(Settings::get('seo')['searchIndexing'] ? "Disallow: /admin\nDisallow: /workspace\nDisallow: /api/\nDisallow: /login\nDisallow: /register\nDisallow: /forgot-password\nDisallow: /reset-password\nDisallow: /checkout.html\nDisallow: /install.php\nDisallow: /preview\nDisallow: /console/\nDisallow: /r/\nDisallow: /export/\nDisallow: /setup\nDisallow: /client-error\n" : "Disallow: /\n").'Sitemap: '.rtrim(Settings::get('seo')['siteUrl'] ?: config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']);
        }
        $aliases = ['resume-builder' => 'workspace.html?view=builder', 'resume-templates' => 'templates.html', 'cover-letter' => 'workspace.html?view=letter', 'resume-match' => 'workspace.html?view=match', 'admin' => 'admin.html', 'privacy-policy' => 'privacy.html', 'terms-and-conditions' => 'terms.html', 'about-us' => 'about.html', 'contact-us' => 'contact.html'];
        if (isset($aliases[$path])) {
            return redirect('/'.$aliases[$path]);
        }
        if ($path === 'article' || $path === 'article.html') {
            abort_unless(preg_match('#^[a-z0-9][a-z0-9/-]{0,160}$#', $r->query('slug', '')), 404);
            $slug = $r->query('slug');
            $catalog = json_decode(file_get_contents(resource_path('data/catalog.json')), true);
            $found = collect($catalog['blog'])->firstWhere('slug', $slug);

            return redirect('/'.(($found['type'] ?? '') === 'Calculator' ? 'calculators' : 'guides').'/'.$slug.'/', 301);
        }
        $admin = str_starts_with($path, 'admin');
        if ($admin || $path === 'workspace.html') {
            if ($admin && (! $r->user() || $r->user()->blocked || ! $r->user()->is_admin)) {
                return redirect('/'); // owner pages are invisible to everyone else
            }
            if (! $r->user()) {
                return redirect('/login');
            }abort_if($r->user()->blocked, 403);
        }
        if ($path === 'setup.html') {
            return redirect('/admin.html');
        }
        if ($path === 'index.html') {
            return redirect('/', 301);
        }if (preg_match('#^(guides|calculators)/[a-z0-9/-]+$#', $path)) {
            $path = rtrim($path, '/').'/';
        }
        $file = $path === '' ? 'index.html' : (str_ends_with($path, '/') ? $path.'index.html' : $path);
        $post = null;
        $slug = null;
        if (preg_match('#^(guides|calculators)/([a-z0-9/-]+)/?$#', $path, $m)) {
            $slug = rtrim($m[2], '/');
            $post = Settings::get('blog')[$slug] ?? null;
            abort_if($post && (! $post['published']), 404);
            abort_unless(Settings::get('features')['blog']['enabled'], 404);
        }
        $full = realpath(resource_path('frontend/'.$file));
        if (! $full && $post) {
            $full = resource_path('frontend/article.html');
        }abort_unless($full && str_starts_with($full, resource_path('frontend/')) && str_ends_with($full, '.html'), 404);
        $html = file_get_contents($full);
        if ($post) {
            $title = e($post['title']);
            $html = preg_replace_callback('#(<h1[^>]*id="articleTitle"[^>]*>).*?(</h1>)#s', fn ($m) => $m[1].$title.$m[2], $html);
            $copy = Str::markdown($post['body'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $faq = '';
            foreach (explode("\n", $post['faq'] ?? '') as $line) {
                $pair = explode('|', $line, 2);
                if (count($pair) === 2) {
                    $faq .= '<details><summary>'.e(trim($pair[0])).'</summary><p>'.e(trim($pair[1])).'</p></details>';
                }
            }$copy .= $faq ? '<section><h2>Frequently asked questions</h2>'.$faq.'</section>' : '';
            $html = preg_replace_callback('#<div id="articleContent"[^>]*>.*?</div></article>#s', fn () => '<div id="articleContent" data-editorial="true">'.$copy.'</div></article>', $html);
            $html = str_replace('data-page="article"', 'data-page="article" data-article-slug="'.e($slug).'"', $html);
        }
        $legal = Settings::get('legal');
        $legalKey = ['about.html' => 'about', 'privacy.html' => 'privacy', 'terms.html' => 'terms', 'refund.html' => 'refund'][$path] ?? null;
        if ($legalKey && ! empty($legal[$legalKey])) {
            $legalHtml = Str::markdown($legal[$legalKey], ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            $html = preg_match('#<main[^>]*class="legal-shell"#', $html)
                ? preg_replace_callback('#(<main[^>]*class="legal-shell"[^>]*>).*?(</main>)#s', fn ($m) => $m[1].$legalHtml.$m[2], $html)
                : preg_replace_callback('#(<article[^>]*>).*?(</article>)#s', fn ($m) => $m[1].$legalHtml.$m[2], $html);
        }
        $seo = Settings::get('seo');
        $siteBase = rtrim($seo['siteUrl'] ?: config('app.url'), '/');
        $abs = fn ($u) => preg_match('#^https?://#', $u) ? $u : $siteBase.$u;
        $uri = '/'.$path;
        $custom = $seo['pages'][$uri] ?? [];
        $title = $custom['title'] ?? $post['title'] ?? null;
        $description = $custom['description'] ?? $post['description'] ?? null;
        if ($title) {
            $html = preg_replace('#<title>.*?</title>#s', '<title>'.e($title).' | CVTara</title>', $html);
        }if ($description) {
            $html = preg_replace_callback('#<meta[^>]*name="description"[^>]*>#', fn ($m) => preg_match('/\sid="([^"]+)"/', $m[0], $id) ? '<meta name="description" id="'.$id[1].'" content="'.e($description).'">' : '<meta name="description" content="'.e($description).'">', $html);
        }
        $html = preg_replace('#<link[^>]*rel="canonical"[^>]*>|<meta[^>]*name="robots"[^>]*>#', '', $html);
        $index = $seo['searchIndexing'] && ! $admin && ! in_array($path, ['workspace.html', 'checkout.html']);
        $head = '<script>(function(){try{var t=localStorage.getItem("cvtara-theme");if(t==="dark"||(!t&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)){var r=document.documentElement;r.classList.add("dark");r.setAttribute("data-theme","dark");r.style.colorScheme="dark"}}catch(e){}})()</script><link rel="canonical" href="'.e($siteBase.$uri).'"><meta name="robots" content="'.($index ? 'index,follow' : 'noindex,nofollow').'"><meta name="csrf-token" content="'.e(csrf_token()).'"><meta name="cvtara-user" content="'.e((string) ($r->user()?->id ?? 'guest')).'"><link rel="stylesheet" href="/launch.css"><link rel="stylesheet" href="/experience.css"><link rel="stylesheet" href="/cvtara-ui.css"><link rel="stylesheet" href="/cvtara-ds.css"><script src="/error-report.js" defer></script><script src="/crash-guard.js" defer></script>';
        if ($path === 'workspace.html') {
            $head .= '<script src="/preview-guard.js" defer></script>';
        }
        $kw = trim((string) ($custom['keywords'] ?? $post['keywords'] ?? ''));
        if ($kw !== '') {
            $head .= '<meta name="keywords" content="'.e($kw).'">';
        }
        // Keep brand identity and sharing metadata in the server response, including without JavaScript.
        preg_match('#<title>(.*?)</title>#s', $html, $titleMatch);
        preg_match('#<meta[^>]*name="description"[^>]*content="([^"]*)"#', $html, $descriptionMatch);
        $pageTitle = html_entity_decode($titleMatch[1] ?? 'CVTara — Resume and cover-letter builder', ENT_QUOTES, 'UTF-8');
        $pageDescription = html_entity_decode($descriptionMatch[1] ?? 'Create your resume, write a matching cover letter and prepare your next application with CVTara.', ENT_QUOTES, 'UTF-8');
        $shareImage = ! empty($post['imageUrl']) ? $post['imageUrl'] : '/assets/brand/cvtara-social.png';
        $head .= '<meta property="og:site_name" content="CVTara"><meta property="og:title" content="'.e($pageTitle).'"><meta property="og:description" content="'.e($pageDescription).'"><meta property="og:url" content="'.e($siteBase.$uri).'"><meta property="og:type" content="'.($post ? 'article' : 'website').'"><meta property="og:image" content="'.e($abs($shareImage)).'"><meta property="og:image:alt" content="CVTara — Your next move starts here."><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="'.e($abs($shareImage)).'">';
        if ($path === '') {
            $head .= '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'CVTara', 'url' => $siteBase.'/', 'publisher' => ['@type' => 'Organization', 'name' => 'CVTara', 'logo' => $siteBase.'/assets/brand/cvtara-mark.svg', 'email' => 'support@cvtara.com', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Bangalore', 'addressCountry' => 'IN']]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).'</script>';
        }
        if ($post) {
            $image = $post['imageUrl'] ?? '';
            $schema = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'], 'description' => $post['description'], 'url' => $siteBase.$uri, 'author' => ['@type' => 'Organization', 'name' => $post['author'] ?? 'CVTara'], 'publisher' => ['@type' => 'Organization', 'name' => 'CVTara']];
            if ($image) {
                $schema['image'] = $abs($image);
            }$head .= '<script type="application/ld+json">'.json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).'</script>';
        }
        if (! $admin) {
            $html = preg_replace('#<script[^>]*src="/owner-(console|advanced)\.js"[^>]*></script>#', '', $html);
        }if ($path !== 'workspace.html' && ! $admin) {
            $html = preg_replace('#<script[^>]*src="/(workspace-activity|profile-onboarding|product-roadmap)\.js"[^>]*></script>#', '', $html);
        }$html = str_replace('</head>', $head.'</head>', $html);
        $scripts = '<script src="/experience.js" defer></script>';
        if ($path !== '' && $path !== 'index.html') {
            $scripts .= '<script src="/launch-ui.js" defer></script>';
        }
        if ($admin || $path === 'workspace.html') {
            $scripts .= '<script src="/referral-center.js" defer></script>';
        }
        if ($path === 'workspace.html') {
            $scripts .= '<script src="/reward-paywall.js" defer></script>';
        }
        if (! $admin && ! in_array($path, ['', 'workspace.html', 'checkout.html'])) {
            $scripts .= '<script src="/fomo-popup.js" defer></script>';
        }
        $html = str_replace('</body>', $scripts.'</body>', $html);

        if ($admin && is_file(public_path('setup-assistant.js'))) {
            $html = str_replace('</body>', '<script src="/setup-assistant.js" defer></script></body>', $html);
        }
        $html = preg_replace_callback('#<script(\s+[^>]*src="[^" ]+"[^>]*)>#', fn ($m) => preg_match('/\b(defer|async)\b/', $m[1]) ? $m[0] : '<script'.$m[1].' defer>', $html);
        $html = preg_replace_callback('#(src|href)="((?!https?:|//)[^"?]+\.(?:js|css))"#', function ($m) use ($uri) {
            $relative = str_starts_with($m[2], '/') ? $m[2] : dirname($uri).'/'.$m[2];
            $file = realpath(public_path($relative));
            $version = $file && str_starts_with($file, public_path().DIRECTORY_SEPARATOR) && is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '';

            return $m[1].'="'.$m[2].($version ? '?v='.$version : '').'"';
        }, $html);

        return response($html)->header('Cache-Control', $admin || $path === 'workspace.html' ? 'private, no-store' : 'no-cache');
    }
}
