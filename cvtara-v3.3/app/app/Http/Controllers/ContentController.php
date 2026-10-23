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
        foreach (\App\Services\SeoPages::paths() as $path) {
            $page = \App\Services\SeoPages::find($path);
            $rows[] = ['path' => '/'.$path, 'title' => $seo['pages']['/'.$path]['title'] ?? $page['title'], 'description' => $seo['pages']['/'.$path]['description'] ?? $page['description'], 'included' => $seo['pages']['/'.$path]['includeInSitemap'] ?? $page['index']];
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

    /**
     * Ties a guide into the programmatic pages: links the first mention of a few key phrases (at most 3, only inside
     * paragraphs that have no link yet) and adds an "Explore" box chosen by the guide's topic.
     */
    private function articleLinks(string $slug, string $copy): array
    {
        $phrases = ['resume examples' => '/resume-examples/', 'cover letter' => '/cover-letter-examples/', 'interview questions' => '/interview-questions/', 'action verbs' => '/resume-words/', 'ATS' => '/ats-checker/', 'resume template' => '/resume-templates/ats-friendly/'];
        $added = 0;
        foreach ($phrases as $phrase => $url) {
            if ($added >= 3) {
                break;
            }
            $done = false;
            $copy = preg_replace_callback('#<p>((?:(?!</p>).)*)</p>#s', function ($m) use ($phrase, $url, &$done) {
                if ($done || str_contains($m[1], '<a ')) {
                    return $m[0];
                }
                $out = preg_replace('/\b('.preg_quote($phrase, '/').')\b/i', '<a href="'.$url.'">$1</a>', $m[1], 1, $n);
                if ($n) {
                    $done = true;
                }

                return '<p>'.$out.'</p>';
            }, $copy);
            $added += $done ? 1 : 0;
        }
        $topic = explode('/', $slug)[0];
        $sets = [
            'resume' => [['/resume-examples/', 'Resume examples by field'], ['/resume-words/', 'Better words for your resume'], ['/resume-templates/ats-friendly/', 'ATS-friendly templates'], ['/resume-examples/student/freshers/', 'Freshers resume example'], ['/ats-checker/', 'Free ATS check']],
            'cover-letter' => [['/cover-letter-examples/', 'Cover letter examples by industry'], ['/resume-examples/', 'Resume examples'], ['/ats-checker/', 'Free ATS check']],
            'interview' => [['/interview-questions/', 'Interview questions by role'], ['/interview-questions/freshers/', 'Interview questions for freshers'], ['/resume-examples/', 'Resume examples']],
            'jobs' => [['/resume-builder/india/', 'Resume tips by city'], ['/resume-examples/', 'Resume examples'], ['/interview-questions/', 'Interview questions'], ['/ats-checker/', 'Free ATS check']],
        ];
        $links = $sets[$topic] ?? [['/resume-builder/india/', 'Resume tips by city'], ['/resume-examples/', 'Resume examples by field'], ['/interview-questions/', 'Interview questions by role']];
        $explore = '<nav class="article-explore" aria-label="Explore"><h2>Explore next</h2><ul>'.implode('', array_map(fn ($l) => '<li><a href="'.$l[0].'">'.e($l[1]).'</a></li>', $links)).'</ul></nav>';

        return [$copy, $explore];
    }

    /**
     * Links search engines can follow without running JavaScript. The footer placeholder gets the same hub links the
     * scripted footer shows (the script replaces it), and the guide lists on the blog and guides pages are filled with
     * every published guide and calculator, which the script then redraws with filters.
     */
    private function serverLinks(string $path, string $html): string
    {
        $hubs = array_merge(\App\Services\SeoPages::HUBS, [['/templates.html', 'Template gallery'], ['/cover-letters.html', 'Cover letter designs'], ['/blog.html', 'Career blog'], ['/guides.html', 'Guides and calculators'], ['/#pricing', 'Pricing'], ['/about.html', 'About us'], ['/contact.html', 'Contact'], ['/privacy.html', 'Privacy policy'], ['/terms.html', 'Terms'], ['/refund.html', 'Refund policy']]);
        $nav = '<nav class="footer-links" aria-label="Site links"><ul>'.implode('', array_map(fn ($l) => '<li><a href="'.e($l[0]).'">'.e($l[1]).'</a></li>', $hubs)).'</ul></nav>';
        $html = preg_replace('#<div data-footer></div>#', '<div data-footer>'.addcslashes($nav, '\\$').'</div>', $html, 1);
        if (in_array($path, ['blog.html', 'guides.html'], true)) {
            $catalog = json_decode(file_get_contents(resource_path('data/catalog.json')), true);
            $custom = Settings::get('blog');
            $calc = $guide = [];
            foreach ($catalog['blog'] as $p) {
                $p = array_merge($p, $custom[$p['slug']] ?? []);
                if (($p['published'] ?? true) === false) {
                    continue;
                }
                $isCalc = $p['type'] === 'Calculator';
                ${$isCalc ? 'calc' : 'guide'}[] = '<a class="blog-card" href="/'.($isCalc ? 'calculators' : 'guides').'/'.e($p['slug']).'/"><b>'.e($p['title']).'</b></a>';
            }
            $fill = fn (string $id, array $items) => fn ($h) => preg_replace('#(<div class="blog-grid" id="'.$id.'">)(</div>)#', '$1'.addcslashes(implode('', $items), '\\$').'$2', $h, 1);
            $html = $path === 'blog.html' ? $fill('blogGrid', array_merge($calc, $guide))($html) : $fill('guidesGuideGrid', $guide)($fill('guidesCalcGrid', $calc)($html));
        }

        return $html;
    }

    /**
     * The site's own stylesheets in the page head, joined into one file in the same order, so a visitor's browser makes one
     * request instead of a dozen before it can draw the page. The file is named after the versions it holds, so it is
     * written once per release and cached for a year. If the folder cannot be written, the page keeps its separate links.
     */
    private function bundleStyles(string $html): string
    {
        $end = strpos($html, '</head>');
        if ($end === false || ! preg_match_all('#<link rel="stylesheet" href="(/[a-z0-9_-]+\.css)\?v=([a-f0-9]{12})">#', substr($html, 0, $end), $m, PREG_SET_ORDER) || count($m) < 2) {
            return $html;
        }
        $from = strpos($html, $m[0][0]);
        if (stripos(substr($html, $from, $end - $from), '<style') !== false) {
            return $html; // an inline style between the links depends on their order around it
        }
        // A sheet linked twice only counts where it last appears, so keeping that one copy gives the same result.
        $last = [];
        foreach ($m as $i => $x) {
            $last[$x[1]] = $i;
        }
        $m = array_values(array_filter($m, fn ($x, $i) => $last[$x[1]] === $i, ARRAY_FILTER_USE_BOTH));
        $name = substr(hash('sha256', implode('|', array_map(fn ($x) => $x[1].$x[2], $m))), 0, 16);
        $dir = public_path('assets/bundle');
        $file = $dir.'/'.$name.'.css';
        if (! is_file($file)) {
            $css = '';
            foreach ($m as $x) {
                $src = public_path(ltrim($x[1], '/'));
                if (! is_file($src)) {
                    return $html;
                }
                $css .= '/* '.$x[1]." */\n".file_get_contents($src)."\n";
            }
            if ((! is_dir($dir) && ! @mkdir($dir, 0755, true)) || @file_put_contents($file.'.tmp', $css) === false || ! @rename($file.'.tmp', $file)) {
                return $html;
            }
        }
        $head = substr($html, 0, $end);
        $first = true;
        $head = preg_replace_callback('#<link rel="stylesheet" href="/[a-z0-9_-]+\.css\?v=[a-f0-9]{12}">#', function () use (&$first, $name) {
            if (! $first) {
                return '';
            }
            $first = false;

            return '<link rel="stylesheet" href="/assets/bundle/'.$name.'.css?v='.substr($name, 0, 12).'">';
        }, $head);

        return $head.substr($html, $end);
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
            return response("User-agent: *\n".(Settings::get('seo')['searchIndexing'] ? "Disallow: /admin\nDisallow: /workspace\nDisallow: /api/\nDisallow: /login\nDisallow: /register\nDisallow: /forgot-password\nDisallow: /reset-password\nDisallow: /checkout.html\nDisallow: /install.php\nDisallow: /preview$\nDisallow: /preview/\nDisallow: /console/\nDisallow: /r/\nDisallow: /export/\nDisallow: /setup\nDisallow: /client-error\n" : "Disallow: /\n").'Sitemap: '.rtrim(Settings::get('seo')['siteUrl'] ?: config('app.url'), '/')."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain']);
        }
        $aliases = ['resume-builder' => 'workspace.html?view=builder', 'cover-letter' => 'workspace.html?view=letter', 'resume-match' => 'workspace.html?view=match', 'admin' => 'admin.html', 'privacy-policy' => 'privacy.html', 'terms-and-conditions' => 'terms.html', 'about-us' => 'about.html', 'contact-us' => 'contact.html'];
        if (isset($aliases[$path])) {
            return redirect(! $r->user() && str_starts_with($aliases[$path], 'workspace.html') ? '/register' : '/'.$aliases[$path]);
        }
        // Programmatic SEO pages (resume examples, city guides, interview questions...). Always served at a trailing-slash address.
        $seoPage = \App\Services\SeoPages::owns($path) ? \App\Services\SeoPages::find($path) : null;
        $query = $r->getQueryString() ? '?'.$r->getQueryString() : '';
        if ($seoPage) {
            if (! str_ends_with($r->getPathInfo(), '/')) {
                return response('', 301, ['Location' => $seoPage['path'].$query]); // a relative Location keeps the trailing slash
            }
            $path = ltrim($seoPage['path'], '/');
        } elseif (\App\Services\SeoPages::owns($path) && ($to = \App\Services\SeoPages::redirect($path))) {
            return response('', 301, ['Location' => $to]);
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
            if (! str_ends_with($r->getPathInfo(), '/')) {
                return response('', 301, ['Location' => '/'.rtrim($path, '/').'/'.$query]);
            }
            $path = rtrim($path, '/').'/';
        }
        $cacheable = \App\Services\PageCache::cacheable($r, $path);
        if ($cacheable && ($ready = \App\Services\PageCache::read($path)) !== null) {
            $res = response($ready)->header('Cache-Control', 'no-cache')->setEtag(md5($ready));
            $res->isNotModified($r);

            return $res;
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
        if ($seoPage) {
            $html = \App\Services\SeoPages::render($seoPage);
        } else {
            $full = realpath(resource_path('frontend/'.$file));
            if (! $full && $post) {
                $full = resource_path('frontend/article.html');
            }abort_unless($full && str_starts_with($full, resource_path('frontend/')) && str_ends_with($full, '.html'), 404);
            $html = file_get_contents($full);
        }
        if ($post) {
            $title = e($post['title']);
            // The cover: a drawn card in the topic's colours (cannot go black or break), or the owner's own picture with a safe fallback.
            $img = (string) ($post['imageUrl'] ?? '');
            $cat = (string) ($post['category'] ?? 'Career');
            $pal = [['#1e3a8a', '#3b82f6', '🧮'], ['#065f46', '#10b981', '📘'], ['#6d28d9', '#a78bfa', '💼'], ['#9a3412', '#fb923c', '🎯'], ['#0f766e', '#2dd4bf', '📈'], ['#9f1239', '#fb7185', '✍️'], ['#1d4ed8', '#38bdf8', '🔍']];
            [$c1, $c2, $emo] = $pal[crc32($cat) % count($pal)];
            if (($post['type'] ?? '') === 'Calculator') {
                $emo = '🧮';
            }
            $cover = ($img === '' || str_contains($img, 'cvtara-article-cover'))
                ? '<figure id="articleFeaturedCover" class="b2-cover" role="img" aria-label="'.e($post['title']).'" style="--g1:'.$c1.';--g2:'.$c2.'"><span>'.e(mb_strtoupper($cat)).'</span><strong>'.e($post['title']).'</strong><i aria-hidden="true">'.$emo.'</i></figure>'
                : '<figure id="articleFeaturedCover" class="owner-article-cover b2-photo" style="--g1:'.$c1.';--g2:'.$c2.'"><img src="'.e($img).'" alt="'.e($post['imageAlt'] ?? $post['title']).'" width="1400" height="788" loading="eager" decoding="async" onerror="this.closest(\'figure\').classList.add(\'is-broken\')"></figure>';
            $html = preg_replace('#<figure id="articleFeaturedCover".*?</figure>#s', '', $html, 1);
            $html = preg_replace('#(<h1[^>]*id="articleTitle"[^>]*>.*?</h1>)#s', '$1'.addcslashes($cover, '\\$'), $html, 1);
            // The 68 ready-made article files carry an old internal note and a fixed list of anchors; use the shared structure instead.
            $html = preg_replace('#<p style="font-size:1rem">Editorial structure reviewed[^<]*</p>#', '<p class="article-meta" id="articleMeta"></p>', $html);
            $html = preg_replace('#<aside class="toc">.*?</aside>#s', '<aside class="toc" id="articleToc"><b>On this page</b></aside>', $html, 1);
            $html = preg_replace_callback('#(<h1[^>]*id="articleTitle"[^>]*>).*?(</h1>)#s', fn ($m) => $m[1].$title.$m[2], $html);
            $copy = Str::markdown($post['body'] ?? '', ['html_input' => 'strip', 'allow_unsafe_links' => false]);
            // Headings get stable anchors; the "On this page" list is built from them (works without JavaScript).
            $toc = [];
            $used = [];
            $copy = preg_replace_callback('#<h([23])>(.*?)</h\1>#s', function ($m) use (&$toc, &$used) {
                $text = trim(strip_tags($m[2]));
                $id = Str::slug($text) ?: 'section';
                $id = isset($used[$id]) ? $id.'-'.(++$used[$id]) : tap($id, function ($i) use (&$used) {
                    $used[$i] = 1;
                });
                if ($m[1] === '2') {
                    $toc[] = [$id, $text];
                }

                return '<h'.$m[1].' id="'.e($id).'">'.$m[2].'</h'.$m[1].'>';
            }, $copy);
            $faq = '';
            $faqPairs = [];
            foreach (explode("\n", $post['faq'] ?? '') as $line) {
                $pair = explode('|', $line, 2);
                if (count($pair) === 2) {
                    $faq .= '<details><summary>'.e(trim($pair[0])).'</summary><p>'.e(trim($pair[1])).'</p></details>';
                    $faqPairs[] = [trim($pair[0]), trim($pair[1])];
                }
            }
            $isCalc = ($post['type'] ?? '') === 'Calculator';
            $words = str_word_count(strip_tags($copy));
            $minutes = max(1, (int) ceil($words / 200));
            $kind = $isCalc ? 'Calculator' : 'Guide';
            $meta = 'By <b>'.e($post['author'] ?? 'CVTara Editorial Team').'</b> · '.$minutes.' min read · '.$kind.(! empty($post['category']) ? ' · '.e($post['category']) : '');
            $html = preg_replace('#(<p class="article-meta" id="articleMeta">).*?(</p>)#s', '$1'.addcslashes($meta, '\\$').'$2', $html, 1);
            if ($isCalc) {
                array_unshift($toc, ['tool', 'Use the calculator']);
            }
            if ($faq) {
                $toc[] = ['faq', 'Frequently asked questions'];
            }
            $tocHtml = '<b>On this page</b>'.implode('', array_map(fn ($t) => '<a href="#'.e($t[0]).'">'.e($t[1]).'</a>', $toc));
            $html = preg_replace('#(<aside class="toc" id="articleToc">).*?(</aside>)#s', count($toc) >= 2 ? '$1'.addcslashes($tocHtml, '\\$').'$2' : '', $html, 1);
            // Related reading and a next step.
            $related = collect(Settings::get('blog'))->filter(fn ($p, $k) => $k !== $slug && ($p['published'] ?? false) && ($p['category'] ?? '') === ($post['category'] ?? '~'))->take(3);
            if ($related->count() < 3) {
                $related = $related->union(collect(Settings::get('blog'))->filter(fn ($p, $k) => $k !== $slug && ($p['published'] ?? false) && ($p['type'] ?? '') === ($post['type'] ?? '~'))->take(3 - $related->count()));
            }
            $after = '<aside class="article-cta"><div><b>Put this into practice</b><p>See exactly what your resume is missing for a real job, then build a tailored version and cover letter in minutes.</p></div><div class="article-cta-actions"><a class="button primary" href="/register">Check my resume free</a><a class="button secondary" href="/templates.html">Browse templates</a></div></aside>';
            if ($related->isNotEmpty()) {
                $after .= '<section class="article-related" aria-label="Related reading"><h2>Keep reading</h2><div>'.$related->map(fn ($p, $k) => '<a href="'.(($p['type'] ?? '') === 'Calculator' ? '/calculators/' : '/guides/').e($k).'/"><span>'.e($p['category'] ?? '').'</span><b>'.e($p['title']).'</b><small>'.e(Str::limit((string) ($p['description'] ?? ''), 110)).'</small></a>')->implode('').'</div></section>';
            }
            [$copy, $explore] = $this->articleLinks($slug, $copy);
            $copy .= ($faq ? '<section class="owner-faq" id="faq"><h2>Frequently asked questions</h2>'.$faq.'</section>' : '').$explore.$after;
            $crumbs = '<nav class="article-crumbs" aria-label="Breadcrumb"><ol><li><a href="/">Home</a></li><li><a href="/blog.html">Career guides</a></li><li><span aria-current="page">'.$title.'</span></li></ol></nav>';
            $html = preg_replace('#(<article class="article">)#', '$1'.addcslashes($crumbs, '\\$'), $html, 1);
            $postFaq = $faqPairs;
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
        $title = $custom['title'] ?? $post['title'] ?? $seoPage['title'] ?? null;
        $description = $custom['description'] ?? $post['description'] ?? $seoPage['description'] ?? null;
        if ($title) {
            $html = preg_replace('#<title>.*?</title>#s', '<title>'.e($title).' | CVTara</title>', $html);
        }if ($description) {
            $html = preg_replace_callback('#<meta[^>]*name="description"[^>]*>#', fn ($m) => preg_match('/\sid="([^"]+)"/', $m[0], $id) ? '<meta name="description" id="'.$id[1].'" content="'.e($description).'">' : '<meta name="description" content="'.e($description).'">', $html);
        }
        $html = preg_replace('#<link[^>]*rel="canonical"[^>]*>|<meta[^>]*name="robots"[^>]*>#', '', $html);
        $index = $seo['searchIndexing'] && ! $admin && ! in_array($path, ['workspace.html', 'checkout.html']);
        $follow = $index; // a programmatic page held back from search results still passes its links on
        $index = $index && ($seoPage['index'] ?? true);
        // Fonts load in parallel with the page's own styles (not chained behind them); the code font only where code is shown.
        $fonts = '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800'.($admin || in_array($path, ['workspace.html', 'admin-guide.html', 'customer-guide.html'], true) ? '&family=JetBrains+Mono:wght@400;600' : '').'&display=swap">';
        $head = '<script>(function(){try{var t=localStorage.getItem("cvtara-theme");if(t==="dark"||(!t&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)){var r=document.documentElement;r.classList.add("dark");r.setAttribute("data-theme","dark");r.style.colorScheme="dark"}}catch(e){}})()</script><link rel="canonical" href="'.e($siteBase.$uri).'"><meta name="robots" content="'.($index ? 'index,follow' : ($follow ? 'noindex,follow' : 'noindex,nofollow')).'"><meta name="csrf-token" content="'.e(csrf_token()).'"><meta name="cvtara-user" content="'.e((string) ($r->user()?->id ?? 'guest')).'">'.$fonts.'<link rel="stylesheet" href="/launch.css"><link rel="stylesheet" href="/experience.css"><link rel="stylesheet" href="/cvtara-ui.css"><link rel="stylesheet" href="/cvtara-ds.css"><link rel="stylesheet" href="/site-polish.css"><script src="/error-report.js" defer></script><script src="/crash-guard.js" defer></script>';
        if ($path === 'workspace.html') {
            $head .= '<script src="/preview-guard.js" defer></script>';
        }
        if (! $admin) {
            $head = str_replace('<script src="/error-report.js" defer></script>', '<script src="/csrf-lazy.js"></script><script src="/error-report.js" defer></script>', $head);
        }
        if ($post || in_array($path, ['blog.html', 'contact.html'])) {
            $head .= '<link rel="stylesheet" href="/blog-v2.css">';
        }
        if ($post) {
            $head .= '<link rel="stylesheet" href="/seo-pages.css">';
        }
        if ($path === 'workspace.html') {
            $head .= '<link rel="stylesheet" href="/support-widget.css">'; // the feedback button belongs to signed-in customers only
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
        if ($seoPage) {
            foreach (\App\Services\SeoPages::schema($seoPage, $siteBase) as $block) {
                $head .= '<script type="application/ld+json">'.json_encode($block, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'</script>';
            }
        }
        $head .= '<meta property="og:site_name" content="CVTara"><meta property="og:title" content="'.e($pageTitle).'"><meta property="og:description" content="'.e($pageDescription).'"><meta property="og:url" content="'.e($siteBase.$uri).'"><meta property="og:type" content="'.($post ? 'article' : 'website').'"><meta property="og:image" content="'.e($abs($shareImage)).'"><meta property="og:image:alt" content="CVTara — Your next move starts here."><meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="'.e($abs($shareImage)).'">';
        if ($path === '') {
            $head .= '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => 'CVTara', 'url' => $siteBase.'/', 'publisher' => ['@type' => 'Organization', 'name' => 'CVTara', 'logo' => $siteBase.'/assets/brand/cvtara-mark.svg', 'email' => 'support@cvtara.com', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Bangalore', 'addressCountry' => 'IN']]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).'</script>';
        }
        if ($post) {
            $image = $post['imageUrl'] ?? '';
            $schema = ['@context' => 'https://schema.org', '@type' => 'BlogPosting', 'headline' => $post['title'], 'description' => $post['description'], 'url' => $siteBase.$uri, 'author' => ['@type' => 'Organization', 'name' => $post['author'] ?? 'CVTara'], 'publisher' => ['@type' => 'Organization', 'name' => 'CVTara']];
            if ($image) {
                $schema['image'] = $abs($image);
            }$schema['mainEntityOfPage'] = $siteBase.$uri;
            $schema['publisher']['logo'] = ['@type' => 'ImageObject', 'url' => $siteBase.'/assets/brand/cvtara-logo.webp'];
            $schema['articleSection'] = $post['category'] ?? 'Career';
            $ld = [$schema, ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $siteBase.'/'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'Blog', 'item' => $siteBase.'/blog.html'], ['@type' => 'ListItem', 'position' => 3, 'name' => $post['title'], 'item' => $siteBase.$uri]]]];
            if (! empty($postFaq)) {
                $ld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn ($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $postFaq)];
            }
            foreach ($ld as $block) {
                $head .= '<script type="application/ld+json">'.json_encode($block, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).'</script>';
            }
        }
        if ($path === 'blog.html') {
            $items = collect(Settings::get('blog'))->filter(fn ($p) => $p['published'] ?? false)->map(fn ($p, $k) => ['@type' => 'ListItem', 'position' => 0, 'url' => $siteBase.(($p['type'] ?? '') === 'Calculator' ? '/calculators/' : '/guides/').$k.'/', 'name' => $p['title']])->values()->take(60)->map(fn ($x, $i) => ['position' => $i + 1] + $x)->all();
            $head .= '<script type="application/ld+json">'.json_encode(['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => 'CVTara career blog, guides and calculators', 'url' => $siteBase.'/blog.html', 'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => $items]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES).'</script>';
        }
        if (! $admin && $path !== 'workspace.html') {
            $html = $this->serverLinks($path, $html);
        }
        if (! $admin) {
            $html = preg_replace('#<script[^>]*src="/owner-(console|advanced)\.js"[^>]*></script>#', '', $html);
        }if ($path !== 'workspace.html' && ! $admin) {
            $html = preg_replace('#<script[^>]*src="/(workspace-activity|profile-onboarding|product-roadmap)\.js"[^>]*></script>#', '', $html);
        }$html = str_replace('</head>', $head.'</head>', $html);
        $scripts = '<script src="/experience.js" defer></script>';
        if ($path !== '' && $path !== 'index.html') {
            $scripts .= '<script src="/launch-ui.js" defer></script><script src="/upgrade-prompt.js" defer></script>';
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
        if (! $admin && ! in_array($path, ['workspace.html', 'checkout.html'])) {
            $scripts .= '<script src="/promo.js" defer></script>';
        }
        if ($path === 'workspace.html') {
            $scripts .= '<script src="/support-widget.js" defer></script>';
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

        if (! $admin) {
            $html = $this->bundleStyles($html);
        }

        if ($cacheable) {
            \App\Services\PageCache::write($path, $html);
        }

        return response($html)->header('Cache-Control', $admin || $path === 'workspace.html' ? 'private, no-store' : 'no-cache');
    }
}
