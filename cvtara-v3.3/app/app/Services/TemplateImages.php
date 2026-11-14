<?php

namespace App\Services;

/**
 * Picture previews of the resume and cover letter designs (3.74.0), so the designs can be found in image search.
 *
 * The site draws its previews with scripts, which image search does not collect. These are still pictures of the same
 * designs with the same fictional sample content, saved as WebP in public_html/assets/templates and assets/cover-letters
 * (tools/seo/template_images.cjs makes them from the gallery). Only designs that are published and have a picture are
 * listed, so a design the owner hides in the console also disappears from the pages and the sitemap.
 */
class TemplateImages
{
    private static ?array $cache = null;

    /** Resume designs: list of ['slug', 'name', 'src', 'w', 'h', 'alt', 'audience', 'ats', 'creative', 'photo']. */
    public static function resumes(): array
    {
        return self::all()['resumes'];
    }

    /** Cover letter designs: list of ['slug', 'name', 'src', 'w', 'h', 'alt', 'market']. */
    public static function letters(): array
    {
        return self::all()['letters'];
    }

    /** Up to $n resume designs that fit a template style page (/resume-templates/{style}/). */
    public static function forStyle(string $style, int $n = 6): array
    {
        $all = self::resumes();
        $fit = array_values(array_filter($all, fn ($t) => match ($style) {
            'ats-friendly', 'one-column', 'simple', 'classic', 'professional', 'corporate' => $t['ats'] && ! $t['creative'] && ! $t['photo'],
            'creative', 'modern' => $t['creative'],
            'with-photo' => $t['photo'],
            'fresher', 'experienced', 'freelance', 'career-change', 'senior', 'internship' => $t['audience'] === $style,
            default => true,
        }));
        $fit = $fit ?: $all;
        // a steady, different starting point per style, so neighbouring style pages do not show the same six pictures
        $start = $fit ? hexdec(substr(md5($style), 0, 6)) % count($fit) : 0;

        $pick = array_slice(array_merge(array_slice($fit, $start), array_slice($fit, 0, $start)), 0, $n);
        // "Career Change Bridge" is captioned "Bridge" on the career change page: the page already says the style
        $lead = '/^'.preg_quote(str_replace('-', ' ', $style), '/').'\\s+/i';

        return array_map(fn ($t) => ['name' => preg_replace($lead, '', $t['name']) ?: $t['name']] + $t, $pick);
    }

    /** The pictures a page shows (absolute path => list of images), for the image sitemap. */
    public static function forPage(string $path): array
    {
        if ($path === '/resume-templates/') {
            return self::resumes();
        }
        if ($path === '/cover-letter-examples/') {
            return self::letters();
        }
        if (preg_match('#^/resume-templates/([a-z0-9-]+)/$#', $path, $m) && isset(SeoPages::data()['templates'][$m[1]])) {
            return self::forStyle($m[1]);
        }

        return [];
    }

    /** A grid of linked pictures with captions, lazily loaded so the page itself stays as fast. */
    public static function grid(array $items, string $class = 'sp-gallery'): string
    {
        if (! $items) {
            return '';
        }
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        return '<ul class="'.$class.'">'.implode('', array_map(fn ($t) => '<li><a href="'.$e($t['href']).'"><img src="'.$e($t['src']).'" alt="'.$e($t['alt']).'" width="'.(int) $t['w'].'" height="'.(int) $t['h'].'" loading="lazy" decoding="async"><span>'.$e($t['name']).'</span></a></li>', $items)).'</ul>';
    }

    private static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $catalog = json_decode((string) @file_get_contents(resource_path('data/catalog.json')), true) ?: [];
        $lib = (array) Settings::get('library', []);
        $out = ['resumes' => [], 'letters' => []];
        foreach ($catalog['templates'] ?? [] as $t) {
            $slug = (string) ($t['slug'] ?? '');
            if ($slug === '' || ($t['publicPublished'] ?? true) === false || ($lib['templates'][$slug]['published'] ?? true) === false) {
                continue;
            }
            $src = '/assets/templates/'.$slug.'-resume-template.webp';
            if ($img = self::size($src)) {
                $name = self::clean((string) $t['name']);
                // caption and alt text use the design's own name, without its group (Creative, Career change...), so a style
                // page does not repeat its own topic under every picture
                $name = trim(explode('(', $name)[0]);
                $out['resumes'][] = ['slug' => $slug, 'name' => $name, 'src' => $src, 'w' => $img[0], 'h' => $img[1], 'href' => '/templates.html',
                    'alt' => $name.' resume template by CVTara, shown with a fictional sample', 'audience' => $t['audience'] ?? null,
                    'ats' => (bool) ($t['ats'] ?? false), 'creative' => (bool) ($t['creative'] ?? false), 'photo' => (bool) ($t['photo'] ?? false)];
            }
        }
        foreach ($catalog['coverLetters'] ?? [] as $l) {
            $slug = (string) ($l['slug'] ?? '');
            if ($slug === '' || ($l['publicPublished'] ?? true) === false || ($lib['letters'][$slug]['published'] ?? true) === false) {
                continue;
            }
            $src = '/assets/cover-letters/'.$slug.'-cover-letter.webp';
            if ($img = self::size($src)) {
                $name = self::clean((string) $l['name']);
                $out['letters'][] = ['slug' => $slug, 'name' => $name, 'src' => $src, 'w' => $img[0], 'h' => $img[1], 'href' => '/cover-letters.html',
                    'alt' => $name.' cover letter template by CVTara, shown with a fictional sample', 'market' => $l['market'] ?? null];
            }
        }

        return self::$cache = $out;
    }

    /** "Prism / Creative" reads as "Prism (Creative)"; "Prism / Cover letter" as "Prism". */
    private static function clean(string $name): string
    {
        $parts = array_map('trim', explode('/', $name, 2));
        if (count($parts) < 2) {
            return $name;
        }

        return strcasecmp($parts[1], 'Cover letter') === 0 ? $parts[0] : $parts[0].' ('.$parts[1].')';
    }

    private static function size(string $src): ?array
    {
        $file = public_path(ltrim($src, '/'));
        if (! is_file($file)) {
            return null;
        }
        $s = @getimagesize($file);

        return $s ? [(int) $s[0], (int) $s[1]] : null;
    }

    /** For tests: forget the cached lists after the settings change. */
    public static function flush(): void
    {
        self::$cache = null;
    }
}
