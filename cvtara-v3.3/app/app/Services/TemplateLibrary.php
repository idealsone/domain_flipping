<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The resume and cover-letter template library (V7 §15, V5.0 §5-§7).
 *  - Every design carries licence, source, author, version, preview, export formats and a test result.
 *  - A design is only called ATS-friendly when it is a text-first, single-column layout AND its exported PDF and Word
 *    files were read back and gave the right words, in the right order, with special characters intact.
 *  - Designs can be imported as a versioned ZIP package; they arrive as drafts, are tested, and go live only when a
 *    person approves them. Each version is kept, so a design can be rolled back.
 *  - A coverage report shows how many designs each role group has against the target of five.
 * Imported designs render with one of the built-in layouts (their "base"), with their own name, colour and font.
 */
class TemplateLibrary
{
    public const TARGET_PER_CLUSTER = 5;

    public const SECTIONS = ['summary', 'experience', 'education', 'projects', 'skills', 'certifications', 'languages', 'awards'];

    public const FONTS = ['Arial', 'Georgia', 'Calibri', 'Helvetica', 'Verdana', 'Times New Roman'];

    public const FORMATS = ['pdf', 'docx', 'print'];

    private const BUILTIN = ['licence' => 'CVTara original (all rights held by CVTara)', 'source' => 'Built in', 'author' => 'CVTara team'];

    /** Same rule Documents::html uses to put skills in a side column. */
    public static function twoColumn(string $slug): bool
    {
        return (bool) preg_match('/sidebar|suite-(prism|folio|vector|forma)/', $slug);
    }

    public static function ready(): bool
    {
        return Schema::hasTable('template_library');
    }

    private static ?array $catalog = null;

    public static function catalog(): array
    {
        return self::$catalog ??= (array) json_decode((string) file_get_contents(resource_path('data/catalog.json')), true);
    }

    /** Lists every built-in design once (new built-ins are added; nothing already listed is changed). */
    public static function sync(): int
    {
        if (! self::ready()) {
            return 0;
        }
        $cat = self::catalog();
        $have = DB::table('template_library')->pluck('slug')->flip();
        $family = [];
        foreach ($cat['templates'] ?? [] as $t) {
            $family[$t['premiumFamily'] ?? ''] ??= $t['category'] ?? '';
        }
        $rows = [];
        foreach ([['resume', $cat['templates'] ?? []], ['letter', $cat['coverLetters'] ?? []]] as [$kind, $list]) {
            foreach ($list as $t) {
                if (isset($have[$t['slug']])) {
                    continue;
                }
                $rows[] = self::BUILTIN + ['slug' => $t['slug'], 'kind' => $kind, 'name' => mb_substr($t['name'], 0, 160), 'base' => $t['slug'],
                    'category' => mb_substr((($kind === 'resume' ? (string) ($t['category'] ?? '') : (string) ($family[$t['premiumFamily'] ?? ''] ?? '')) ?: 'General'), 0, 80),
                    'preview' => ($kind === 'resume' ? '/templates.html#' : '/cover-letters.html#').$t['slug'], 'compat_json' => json_encode(self::FORMATS),
                    'ats_layout' => $kind === 'letter' ? true : ! empty($t['ats']), 'single_column' => ! self::twoColumn($t['slug']), 'status' => 'published', 'origin' => 'builtin',
                    'content_hash' => hash('sha256', json_encode($t)), 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('template_library')->insertOrIgnore($chunk);
        }

        return count($rows);
    }

    // ------------------------------------------------------------------ text-reading test

    /** Demo data, clearly labelled, with the characters that used to break exports. */
    public static function demo(string $kind, string $slug, array $def = []): array
    {
        $tokens = (array) ($def['style_tokens'] ?? []);
        $base = ['template' => $slug, 'accent' => $tokens['accent'] ?? '#1557d2', 'font' => $tokens['font'] ?? 'Arial',
            'personal' => ['name' => "Aarav D'Souza-Iyer", 'role' => 'Data Analyst (demo data)', 'email' => 'demo.candidate@example.com', 'phone' => '+91 90000 00000', 'city' => 'Pune']];
        if ($kind === 'letter') {
            return $base + ['date' => '12 November 2026', 'recipient' => 'Hiring Manager', 'company' => 'Demo & Sons Pvt. Ltd.', 'subject' => 'Application for Data Analyst (demo)', 'salutation' => 'Dear Hiring Manager,',
                'opening' => 'I am applying for the Data Analyst role – this is demo text used to test the design.', 'evidence' => 'I cut report time by 40%? Yes: from 6 hours to 3.5 hours [measured].',
                'motivation' => 'Your "customer first" approach & clear reporting appeal to me.', 'closing' => 'I would welcome a conversation (in person or online).', 'signoff' => 'Kind regards,'];
        }
        $order = array_values(array_intersect((array) ($def['section_order'] ?? ['summary', 'experience', 'projects', 'education', 'skills', 'certifications']), self::SECTIONS));

        return $base + ['order' => $order ?: ['summary', 'experience', 'education', 'skills'],
            'summary' => "Analyst who asks \"why?\" – and finds answers [fast] & clearly.\nSaved ₹4,50,000 a year (demo data).",
            'experience' => [['role' => 'Data Analyst', 'company' => 'Demo Retail Ltd', 'city' => 'Pune', 'start' => 'Jan 2022', 'end' => 'Mar 2024', 'bullets' => "Built 12 dashboards (Power BI) – used weekly by 40 managers\nCut report time by 40%? Yes: 6 hours → 3.5 hours"]],
            'projects' => [['name' => 'Stock forecast (demo)', 'tools' => 'Python, SQL', 'details' => 'Forecast weekly stock for 30 stores [pilot]']],
            'education' => [['degree' => 'B.Com (Hons.)', 'school' => 'Demo University', 'end' => '2021']],
            'skills' => 'SQL, Excel, Power BI, Python', 'certifications' => [['name' => 'Data Analysis Certificate (demo)', 'issuer' => 'Demo Institute', 'date' => '2023']]];
    }

    private const HEADINGS = ['summary' => 'Profile', 'experience' => 'Experience', 'education' => 'Education', 'projects' => 'Projects', 'skills' => 'Skills', 'certifications' => 'Certifications', 'languages' => 'Languages', 'awards' => 'Awards & volunteering'];

    /** Renders the demo document to PDF and Word, reads the text back and checks it. */
    public static function test(string $slug): array
    {
        $row = DB::table('template_library')->where('slug', $slug)->first();
        abort_unless($row, 404, 'No such template.');
        $def = json_decode((string) $row->definition_json, true) ?: [];
        $kind = $row->kind;
        $d = self::demo($kind, $row->base ?: $slug, $def);
        $problems = [];
        $pdfText = $docxText = '';
        try {
            $o = new \Dompdf\Options();
            $o->set('isRemoteEnabled', false);
            $o->set('isPhpEnabled', false);
            $o->set('chroot', storage_path());
            $pdf = new \Dompdf\Dompdf($o);
            $pdf->loadHtml(Documents::html($d, $kind, false));
            $pdf->setPaper('A4');
            $pdf->render();
            $pdfText = PdfText::extract($pdf->output());
        } catch (\Throwable $e) {
            $problems[] = 'PDF could not be made: '.mb_substr($e->getMessage(), 0, 150);
        }
        try {
            $file = tempnam(storage_path('app'), 'tpl');
            \PhpOffice\PhpWord\IOFactory::createWriter(Documents::word($d, $kind, false), 'Word2007')->save($file);
            $docxText = self::docxText($file);
            @unlink($file);
        } catch (\Throwable $e) {
            $problems[] = 'Word file could not be made: '.mb_substr($e->getMessage(), 0, 150);
        }
        $expect = $kind === 'letter'
            ? ["Aarav D'Souza-Iyer", 'demo.candidate@example.com', 'Demo & Sons Pvt. Ltd.', 'this is demo text', '40%? Yes', '[measured]', '"customer first"', '(in person or online)', 'Kind regards,']
            : ["Aarav D'Souza-Iyer", 'demo.candidate@example.com', '"why?" – and finds answers [fast] & clearly', '₹4,50,000', 'Built 12 dashboards (Power BI) – used weekly by 40 managers', '6 hours → 3.5 hours', 'B.Com (Hons.)', 'SQL, Excel, Power BI, Python'];
        $checks = [];
        foreach (['pdf' => $pdfText, 'docx' => $docxText] as $fmt => $text) {
            $flat = preg_replace('/\s+/u', ' ', $text);
            $missing = array_values(array_filter($expect, fn ($w) => ! str_contains($flat, $w)));
            $order = true;
            if ($kind === 'resume') {
                // The section headings must come back in the order the customer set.
                $pos = -1;
                foreach ($d['order'] as $k) {
                    $p = $fmt === 'pdf' ? mb_stripos($flat, self::HEADINGS[$k], max(0, $pos)) : mb_stripos($flat, ucfirst($k), max(0, $pos));
                    if ($p === false || $p < $pos) {
                        $order = false;
                        break;
                    }
                    $pos = $p;
                }
            }
            $dates = $kind === 'letter' || $fmt === 'docx' || (bool) preg_match('/Demo Retail Ltd[^\n]*Jan 2022 – Mar 2024/u', $text);
            $checks[$fmt] = ['characters' => strlen($text), 'missing' => $missing, 'sectionOrder' => $order, 'datesWithEmployer' => $dates, 'ok' => $text !== '' && ! $missing && $order && $dates];
            if (! $checks[$fmt]['ok']) {
                $problems[] = strtoupper($fmt).': '.($text === '' ? 'no text could be read' : implode('; ', array_filter([$missing ? 'missing '.implode(', ', array_slice($missing, 0, 3)) : '', $order ? '' : 'sections out of order', $dates ? '' : 'dates not next to the employer'])));
            }
        }
        $passed = ! $problems;
        $result = ['passed' => $passed, 'checks' => $checks, 'problems' => $problems, 'singleColumn' => (bool) $row->single_column, 'sample' => mb_substr($pdfText, 0, 1500), 'demoData' => true, 'checkedAt' => now()->toIso8601String()];
        DB::table('template_library')->where('slug', $slug)->update(['test_passed' => $passed, 'test_json' => json_encode($result), 'tested_version' => $row->version, 'tested_at' => now(), 'updated_at' => now()]);

        return $result;
    }

    /** Plain text of a .docx, one paragraph per line. */
    public static function docxText(string $file): string
    {
        $z = new \ZipArchive();
        if ($z->open($file) !== true) {
            return '';
        }
        $xml = (string) $z->getFromName('word/document.xml');
        $z->close();
        $xml = preg_replace('#</w:p>#', "\n", $xml);
        $xml = preg_replace('#<w:tab/>#', "\t", $xml);

        return trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    /** Tests designs never tested (or changed since). Called from the scheduler, a few a minute. */
    public static function testDue(int $limit = 8): int
    {
        if (! self::ready()) {
            return 0;
        }
        self::sync();
        $n = 0;
        foreach (DB::table('template_library')->whereIn('status', ['published', 'draft'])->where(fn ($q) => $q->whereNull('tested_version')->orWhereColumn('tested_version', '!=', 'version'))->orderBy('slug')->limit($limit)->pluck('slug') as $slug) {
            self::test($slug);
            $n++;
        }

        return $n;
    }

    /** For the public site: which designs may show the ATS label, and the imported designs that are live. */
    public static function publicData(): array
    {
        if (! self::ready()) {
            return ['ats' => null, 'imported' => ['templates' => [], 'letters' => []]];
        }
        $rows = DB::table('template_library')->get(['slug', 'kind', 'name', 'base', 'category', 'ats_layout', 'single_column', 'test_passed', 'status', 'origin', 'definition_json']);
        $ats = [];
        $imp = ['templates' => [], 'letters' => []];
        foreach ($rows as $r) {
            if ($r->kind === 'resume') {
                $ats[$r->slug] = (bool) $r->ats_layout && (bool) $r->single_column && (bool) $r->test_passed;
            }
            if ($r->origin === 'package' && $r->status === 'published') {
                $def = json_decode((string) $r->definition_json, true) ?: [];
                $imp[$r->kind === 'resume' ? 'templates' : 'letters'][] = ['slug' => $r->slug, 'name' => $r->name, 'base' => $r->base, 'category' => $r->category,
                    'accent' => $def['style_tokens']['accent'] ?? null, 'font' => $def['style_tokens']['font'] ?? null, 'ats' => $ats[$r->slug] ?? false, 'sectionOrder' => $def['section_order'] ?? null];
            }
        }

        return ['ats' => $ats, 'imported' => $imp];
    }

    /** Whether a customer may save a document with this design. */
    public static function exists(string $kind, string $slug): bool
    {
        $list = self::catalog()[$kind === 'resume' ? 'templates' : 'coverLetters'] ?? [];
        if (collect($list)->contains('slug', $slug)) {
            return true;
        }

        // An imported design that was approved once stays usable for saving while a newer version waits for approval.
        return self::ready() && DB::table('template_library')->where(['slug' => $slug, 'kind' => $kind, 'origin' => 'package'])
            ->where(fn ($q) => $q->where('status', 'published')->orWhere(fn ($w) => $w->where('status', 'draft')->whereNotNull('approved_at')))->exists();
    }

    /** The built-in design an imported one renders with (or the slug itself). */
    public static function base(string $slug): string
    {
        if (! str_starts_with($slug, 'pkg-') || ! self::ready()) {
            return $slug;
        }

        return (string) (DB::table('template_library')->where('slug', $slug)->value('base') ?: $slug);
    }

    // ------------------------------------------------------------------ coverage

    /** Designs per role group against the target of five (published ones only). */
    public static function coverage(): array
    {
        self::sync();
        $rows = DB::table('template_library')->where('status', 'published')->select('category', 'kind', 'ats_layout', 'single_column', 'test_passed')->get();
        $out = [];
        foreach ($rows as $r) {
            $c = $r->category ?: 'General';
            $out[$c] ??= ['cluster' => $c, 'resumes' => 0, 'letters' => 0, 'atsReady' => 0];
            $out[$c][$r->kind === 'resume' ? 'resumes' : 'letters']++;
            if ($r->kind === 'resume' && $r->ats_layout && $r->single_column && $r->test_passed) {
                $out[$c]['atsReady']++;
            }
        }
        foreach ($out as &$o) {
            $o['resumeGap'] = max(0, self::TARGET_PER_CLUSTER - $o['resumes']);
            $o['letterGap'] = max(0, self::TARGET_PER_CLUSTER - $o['letters']);
        }
        unset($o);
        usort($out, fn ($a, $b) => ($b['resumeGap'] + $b['letterGap']) <=> ($a['resumeGap'] + $a['letterGap']) ?: strcmp($a['cluster'], $b['cluster']));

        return ['target' => self::TARGET_PER_CLUSTER, 'clusters' => $out,
            'totals' => ['resumes' => $rows->where('kind', 'resume')->count(), 'letters' => $rows->where('kind', 'letter')->count(), 'tested' => DB::table('template_library')->whereNotNull('tested_at')->count(),
                'failed' => DB::table('template_library')->where('test_passed', false)->count(), 'atsReady' => array_sum(array_column($out, 'atsReady'))]];
    }

    // ------------------------------------------------------------------ ZIP package import

    private const ALLOWED = [
        '#^manifest\.json$#' => 'json', '#^templates/(resume|cover-letter)/[A-Za-z0-9_.-]+\.template\.json$#' => 'template', '#^layouts/[A-Za-z0-9_.-]+\.html$#' => 'html',
        '#^styles/[A-Za-z0-9_.-]+\.json$#' => 'json', '#^styles/[A-Za-z0-9_.-]+\.css$#' => 'css', '#^assets/previews/[A-Za-z0-9_.-]+\.(png|jpe?g|webp)$#' => 'image',
        '#^assets/fonts/[A-Za-z0-9_.-]+\.(woff2?|ttf|otf)$#' => 'font', '#^source-docx/[A-Za-z0-9_.-]+\.docx$#' => 'docx', '#^tests/[A-Za-z0-9_.-]+\.json$#' => 'json',
    ];

    private const MANIFEST = ['package_schema_version', 'package_name', 'vendor', 'created_at', 'locale', 'taxonomy_version', 'template_count', 'checksum', 'compatibility'];

    private const TEMPLATE = ['template_id', 'version', 'document_type', 'industry_ids', 'job_family_ids', 'occupation_ids', 'experience_band', 'layout_family', 'ATS_mode', 'section_order',
        'field_bindings', 'conditional_sections', 'style_tokens', 'export_formats', 'preview_asset', 'status', 'changelog'];

    /**
     * Validates a package and imports its designs as drafts. Returns the validation report; nothing goes live here.
     * The package checksum is the SHA-256 of every templates/… JSON file's bytes, joined in path order.
     */
    public static function import(string $path, ?int $by = null): array
    {
        self::sync();
        $errors = $warnings = $result = [];
        $hash = hash_file('sha256', $path);
        $z = new \ZipArchive();
        abort_unless(filesize($path) <= 20 * 1024 * 1024, 422, 'The package is larger than 20 MB.');
        abort_unless($z->open($path) === true, 422, 'This is not a ZIP file.');
        $files = [];
        $total = 0;
        try {
            abort_if($z->numFiles > 300, 422, 'The package has more than 300 files.');
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                $name = (string) $st['name'];
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $rel = preg_replace('#^[^/]+/(?=manifest\.json$|templates/|layouts/|styles/|assets/|source-docx/|tests/)#', '', $name); // allow one top folder
                $total += (int) $st['size'];
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    $errors[] = "$name: unsafe path";

                    continue;
                }
                $type = null;
                foreach (self::ALLOWED as $re => $t) {
                    if (preg_match($re, $rel)) {
                        $type = $t;
                        break;
                    }
                }
                if (! $type) {
                    $errors[] = "$rel: file type not allowed in a template package";

                    continue;
                }
                $files[$rel] = ['type' => $type, 'index' => $i];
            }
            abort_if($total > 50 * 1024 * 1024, 422, 'The package unpacks to more than 50 MB.');
            $read = fn ($rel) => (string) $z->getFromIndex($files[$rel]['index']);
            foreach ($files as $rel => $f) {
                $body = $read($rel);
                if ($f['type'] === 'html' && preg_match('#<script|<iframe|<object|<embed|\son\w+\s*=|javascript:|(src|href)\s*=\s*["\']?\s*(https?:)?//#i', $body)) {
                    $errors[] = "$rel: scripts, frames, event handlers or remote files are not allowed";
                }
                if ($f['type'] === 'css' && preg_match('#@import|url\(\s*["\']?\s*(https?:|//|javascript:|data:text)|expression\s*\(|behavior\s*:|-moz-binding#i', $body)) {
                    $errors[] = "$rel: remote or executable CSS is not allowed";
                }
                if ($f['type'] === 'docx') {
                    $tmp = tempnam(storage_path('app'), 'pkd');
                    file_put_contents($tmp, $body);
                    $dz = new \ZipArchive();
                    if ($dz->open($tmp) !== true) {
                        $errors[] = "$rel: not a valid Word file";
                    } else {
                        for ($k = 0; $k < $dz->numFiles; $k++) {
                            if (preg_match('#vbaProject\.bin|activeX|\.bin$#i', (string) $dz->getNameIndex($k))) {
                                $errors[] = "$rel: Word files with macros or embedded programs are not allowed";
                                break;
                            }
                        }
                        $dz->close();
                    }
                    @unlink($tmp);
                }
                if ($f['type'] === 'image' && ! @getimagesizefromstring($body)) {
                    $errors[] = "$rel: not a valid image";
                }
            }
            $manifest = isset($files['manifest.json']) ? json_decode($read('manifest.json'), true) : null;
            if (! is_array($manifest)) {
                $errors[] = 'manifest.json is missing or not valid JSON';
                $manifest = [];
            }
            foreach (self::MANIFEST as $k) {
                if (! array_key_exists($k, $manifest)) {
                    $errors[] = "manifest.json: $k is missing";
                }
            }
            $fonts = array_filter(array_keys($files), fn ($p) => $files[$p]['type'] === 'font');
            if ($fonts && empty($manifest['font_licences'])) {
                $errors[] = 'Fonts are included but manifest.json lists no font_licences';
            }
            $tplFiles = array_values(array_filter(array_keys($files), fn ($p) => $files[$p]['type'] === 'template'));
            sort($tplFiles);
            $sum = hash('sha256', implode('', array_map($read, $tplFiles)));
            if (! empty($manifest['checksum']) && ! hash_equals(strtolower((string) $manifest['checksum']), $sum)) {
                $errors[] = 'manifest.json: checksum does not match the template files';
            }
            if (isset($manifest['template_count']) && (int) $manifest['template_count'] !== count($tplFiles)) {
                $errors[] = 'manifest.json: template_count is '.(int) $manifest['template_count'].' but the package has '.count($tplFiles);
            }
            $cat = self::catalog();
            $bases = ['resume' => collect($cat['templates'] ?? [])->keyBy('slug'), 'letter' => collect($cat['coverLetters'] ?? [])->keyBy('slug')];
            $parsed = [];
            foreach ($tplFiles as $rel) {
                $t = json_decode($read($rel), true);
                if (! is_array($t)) {
                    $errors[] = "$rel: not valid JSON";

                    continue;
                }
                foreach (self::TEMPLATE as $k) {
                    if (! array_key_exists($k, $t)) {
                        $errors[] = "$rel: $k is missing";
                    }
                }
                $kind = ($t['document_type'] ?? '') === 'cover_letter' ? 'letter' : (($t['document_type'] ?? '') === 'resume' ? 'resume' : null);
                if (! $kind || ($kind === 'letter') !== str_contains($rel, '/cover-letter/')) {
                    $errors[] = "$rel: document_type must be resume or cover_letter and match its folder";

                    continue;
                }
                if (! preg_match('/^[A-Za-z0-9_-]{3,60}$/', (string) ($t['template_id'] ?? ''))) {
                    $errors[] = "$rel: template_id must be 3-60 letters, digits, - or _";

                    continue;
                }
                foreach (['licence', 'source', 'author'] as $k) {
                    if (trim((string) ($t[$k] ?? $manifest[$k] ?? ($k === 'author' ? ($manifest['vendor'] ?? '') : ''))) === '') {
                        $errors[] = "$rel: $k is missing (on the template or in manifest.json)";
                    }
                }
                if (! isset($bases[$kind][(string) ($t['layout_family'] ?? '')])) {
                    $errors[] = "$rel: layout_family must name a CVTara ".($kind === 'resume' ? 'resume' : 'cover letter').' design to build on';
                }
                $tok = (array) ($t['style_tokens'] ?? []);
                if (isset($tok['accent']) && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $tok['accent'])) {
                    $errors[] = "$rel: style_tokens.accent must be a colour like #1557d2";
                }
                if (isset($tok['font']) && ! in_array($tok['font'], self::FONTS, true)) {
                    $errors[] = "$rel: style_tokens.font must be one of ".implode(', ', self::FONTS);
                }
                if ($kind === 'resume' && (! is_array($t['section_order'] ?? null) || array_diff($t['section_order'], self::SECTIONS))) {
                    $errors[] = "$rel: section_order may only use ".implode(', ', self::SECTIONS);
                }
                if (! is_array($t['export_formats'] ?? null) || array_diff($t['export_formats'], self::FORMATS)) {
                    $errors[] = "$rel: export_formats may only use pdf, docx, print";
                }
                if (! is_int($t['version'] ?? null) || $t['version'] < 1) {
                    $errors[] = "$rel: version must be a whole number from 1";
                }
                $parsed[] = [$rel, $kind, $t];
            }
            if (! $tplFiles) {
                $errors[] = 'The package has no templates';
            }
            $pid = DB::table('template_packages')->insertGetId(['name' => mb_substr((string) ($manifest['package_name'] ?? ''), 0, 160), 'vendor' => mb_substr((string) ($manifest['vendor'] ?? ''), 0, 160),
                'schema_version' => mb_substr((string) ($manifest['package_schema_version'] ?? ''), 0, 20), 'file_hash' => $hash, 'manifest_json' => json_encode($manifest), 'status' => $errors ? 'rejected' : 'imported',
                'uploaded_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            if (! $errors) {
                foreach ($parsed as [$rel, $kind, $t]) {
                    $result[] = self::store($kind, $t, $manifest, $pid, $by);
                }
            }
        } finally {
            $z->close();
        }
        $report = ['packageId' => $pid ?? null, 'ok' => ! $errors, 'errors' => array_slice($errors, 0, 100), 'warnings' => $warnings, 'templates' => $result, 'checksum' => $sum ?? null];
        if (isset($pid)) {
            DB::table('template_packages')->where('id', $pid)->update(['report_json' => json_encode($report)]);
        }
        Settings::audit($by, 'template_package_'.($errors ? 'rejected' : 'imported'), (string) ($manifest['package_name'] ?? ''));

        return $report;
    }

    private const DOCX_HEADINGS = [
        'summary' => '/^(professional\s+|career\s+)?(summary|profile|objective|about( me)?|career objective|personal statement)$/i',
        'experience' => '/^(work\s+|professional\s+|relevant\s+)?(experience|employment( history)?|work history|career history|internships?)$/i',
        'education' => '/^(education|academic( background| qualifications)?|qualifications|educational qualifications)$/i',
        'projects' => '/^(academic\s+|key\s+)?projects?$/i',
        'skills' => '/^(key\s+|technical\s+|core\s+)?(skills|competencies|expertise|strengths)$/i',
        'certifications' => '/^(certifications?|certificates?|courses|trainings?|licen[cs]es)( (and|&) (courses|trainings?))?$/i',
        'languages' => '/^languages?( known)?$/i',
        'awards' => '/^(awards?|achievements?|honou?rs|accomplishments|volunteering|extra[- ]curricular( activities)?)(\s*(and|&)\s*\w+)?$/i',
    ];

    /**
     * 3.61.0 Import a design from a Word file (V5.0 §6; owner OK 12 Nov 2026). The file is checked (no macros or
     * embedded programs, size), then its look is read: section order from its headings, the main font, the accent
     * colour of the headings, and whether it is laid out in one column or two. It becomes a draft built on the closest
     * CVTara design, tested like any other, and goes live only when the owner approves it. Text in the Word file is a
     * sample and is not stored.
     */
    public static function importDocx(string $path, array $meta, ?int $by = null): array
    {
        self::sync();
        $errors = $warnings = [];
        abort_unless(filesize($path) <= 10 * 1024 * 1024, 422, 'The Word file is larger than 10 MB.');
        $z = new \ZipArchive();
        abort_unless($z->open($path) === true && $z->locateName('word/document.xml') !== false, 422, 'This is not a Word (.docx) file.');
        for ($k = 0; $k < $z->numFiles; $k++) {
            if (preg_match('#vbaProject\.bin|activeX|\.bin$|oleObject#i', (string) $z->getNameIndex($k))) {
                $errors[] = 'Word files with macros or embedded programs are not allowed';
                break;
            }
        }
        $doc = (string) $z->getFromName('word/document.xml');
        $styles = (string) $z->getFromName('word/styles.xml');
        $z->close();
        $kind = ($meta['kind'] ?? 'resume') === 'letter' ? 'letter' : 'resume';
        foreach (['licence', 'source', 'author'] as $k) {
            if (trim((string) ($meta[$k] ?? '')) === '') {
                $errors[] = "Enter the $k for this design";
            }
        }
        $read = self::readDocx($doc, $styles);
        if ($kind === 'resume' && count($read['sections']) < 2) {
            $errors[] = 'Fewer than two section headings were found (for example Experience, Education, Skills). Use plain headings in the Word file.';
        }
        if ($read['images']) {
            $warnings[] = 'The Word file has pictures or text boxes; they are not copied. Customers add their own photo where the design allows it.';
        }
        if ($read['fontNote']) {
            $warnings[] = $read['fontNote'];
        }
        $cat = self::catalog();
        $resumes = collect($cat['templates'] ?? []);
        $letters = collect($cat['coverLetters'] ?? []);
        $base = $read['twoColumn']
            ? ($resumes->first(fn ($t) => self::twoColumn($t['slug']) && empty($t['photo']))['slug'] ?? $resumes->first(fn ($t) => self::twoColumn($t['slug']))['slug'] ?? null)
            : ($resumes->first(fn ($t) => ! self::twoColumn($t['slug']) && ! empty($t['ats']) && empty($t['photo']) && empty($t['creative']))['slug'] ?? null);
        if ($kind === 'letter') {
            $base = $base && $letters->contains('slug', 'letter-'.Str::after($base, 'suite-')) ? 'letter-'.Str::after($base, 'suite-') : ($letters->first()['slug'] ?? null);
        }
        if (! $base) {
            $errors[] = 'No CVTara design is available to build on.';
        }
        $name = trim((string) ($meta['name'] ?? '')) ?: Str::headline(pathinfo((string) ($meta['fileName'] ?? 'Word design'), PATHINFO_FILENAME));
        $hash = hash_file('sha256', $path);
        // The id follows what was read (not the file bytes), so the same design saved again is found as a duplicate.
        $id = Str::slug(mb_substr($name, 0, 40)).'-'.substr(hash('sha256', json_encode([$kind, $name, $read['sections'], $read['font'], $read['accent'], $read['twoColumn']])), 0, 8);
        $order = $kind === 'resume' ? array_values(array_unique(array_merge($read['sections'], array_diff(['summary', 'experience', 'education', 'skills'], $read['sections'])))) : [];
        $t = ['template_id' => 'docx-'.$id, 'name' => mb_substr($name, 0, 160), 'version' => 1, 'document_type' => $kind === 'letter' ? 'cover_letter' : 'resume', 'industry_ids' => [], 'job_family_ids' => [],
            'occupation_ids' => [], 'experience_band' => 'all', 'layout_family' => $base, 'ATS_mode' => ! $read['twoColumn'] && ! $read['images'], 'section_order' => $order, 'field_bindings' => [],
            'conditional_sections' => [], 'style_tokens' => array_filter(['font' => $read['font'], 'accent' => $read['accent']]), 'export_formats' => self::FORMATS, 'preview_asset' => '', 'status' => 'draft',
            'changelog' => 'Imported from the Word file '.mb_substr((string) ($meta['fileName'] ?? ''), 0, 120), 'category' => mb_substr(trim((string) ($meta['category'] ?? '')) ?: 'General', 0, 80),
            'licence' => trim((string) ($meta['licence'] ?? '')), 'source' => trim((string) ($meta['source'] ?? '')), 'author' => trim((string) ($meta['author'] ?? ''))];
        $manifest = ['package_name' => 'Word file: '.mb_substr((string) ($meta['fileName'] ?? $name), 0, 120), 'vendor' => $t['author'], 'package_schema_version' => 'docx-1'];
        $pid = DB::table('template_packages')->insertGetId(['name' => mb_substr($manifest['package_name'], 0, 160), 'vendor' => mb_substr($t['author'], 0, 160), 'schema_version' => 'docx-1', 'file_hash' => $hash,
            'manifest_json' => json_encode($manifest + ['read' => $read]), 'status' => $errors ? 'rejected' : 'imported', 'uploaded_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        $result = $errors ? [] : [self::store($kind, $t, $manifest, $pid, $by)];
        $report = ['packageId' => $pid, 'ok' => ! $errors, 'errors' => $errors, 'warnings' => $warnings, 'templates' => $result,
            'read' => ['sections' => $read['sections'], 'font' => $read['font'], 'accent' => $read['accent'], 'twoColumn' => $read['twoColumn'], 'base' => $base]];
        DB::table('template_packages')->where('id', $pid)->update(['report_json' => json_encode($report)]);
        Settings::audit($by, 'template_docx_'.($errors ? 'rejected' : 'imported'), $name);

        return $report;
    }

    /** Reads the look of a Word file: headings in order, main font, heading colour, columns, pictures. */
    public static function readDocx(string $doc, string $styles = ''): array
    {
        $prev = libxml_use_internal_errors(true);
        $x = new \DOMDocument();
        $okDoc = $doc !== '' && $x->loadXML($doc, LIBXML_NONET);
        $sections = [];
        $fonts = [];
        $accent = null;
        $twoColumn = false;
        $images = false;
        if ($okDoc) {
            $xp = new \DOMXPath($x);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $images = $xp->query('//w:drawing|//w:pict|//w:txbxContent')->length > 0;
            foreach ($xp->query('//w:sectPr/w:cols[@w:num]') as $c) {
                $twoColumn = $twoColumn || (int) $c->getAttribute('w:num') > 1;
            }
            foreach ($xp->query('//w:body/w:tbl') as $tbl) {
                // A table whose row holds two cells with real text is a two-column layout.
                foreach ($xp->query('w:tr', $tbl) as $tr) {
                    $filled = 0;
                    foreach ($xp->query('w:tc', $tr) as $tc) {
                        $filled += mb_strlen(trim($tc->textContent)) > 20 ? 1 : 0;
                    }
                    if ($filled >= 2) {
                        $twoColumn = true;
                        break 2;
                    }
                }
            }
            foreach ($xp->query('//w:rFonts/@w:ascii') as $f) {
                $fonts[$f->value] = ($fonts[$f->value] ?? 0) + 1;
            }
            foreach ($xp->query('//w:p') as $p) {
                $text = trim(preg_replace('/\s+/u', ' ', $p->textContent));
                $text = trim($text, " :•-–|");
                if ($text === '' || mb_strlen($text) > 45) {
                    continue;
                }
                foreach (self::DOCX_HEADINGS as $sec => $re) {
                    if (preg_match($re, $text) && ! in_array($sec, $sections, true)) {
                        $sections[] = $sec;
                        if (! $accent) {
                            foreach ($xp->query('.//w:color/@w:val', $p) as $c) {
                                $v = strtolower($c->value);
                                if (preg_match('/^[0-9a-f]{6}$/', $v) && ! (substr($v, 0, 2) === substr($v, 2, 2) && substr($v, 2, 2) === substr($v, 4, 2))) {
                                    $accent = '#'.$v; // a real colour, not black, white or grey
                                    break;
                                }
                            }
                        }
                        break;
                    }
                }
            }
        }
        if ($styles !== '' && preg_match('#<w:docDefaults>.*?w:ascii="([^"]+)"#s', $styles, $m)) {
            $fonts[$m[1]] = ($fonts[$m[1]] ?? 0) + 1000; // the document's default font weighs most
        }
        libxml_use_internal_errors($prev);
        arsort($fonts);
        $raw = (string) (array_key_first($fonts) ?? '');
        $font = null;
        $note = null;
        foreach (self::FONTS as $f) {
            if ($raw !== '' && stripos($raw, $f) === 0) {
                $font = $f;
            }
        }
        if (! $font && $raw !== '') {
            $font = preg_match('/times|garamond|cambria|georgia|book|serif/i', $raw) ? 'Georgia' : 'Calibri';
            $note = "The font $raw is not available on CVTara, so $font is used instead.";
        }

        return ['sections' => $sections, 'font' => $font, 'fontNote' => $note, 'accent' => $accent, 'twoColumn' => $twoColumn, 'images' => $images];
    }

    /** One template from a package: new (draft), a duplicate (skipped) or a new version of an imported one (draft). */
    private static function store(string $kind, array $t, array $manifest, int $pid, ?int $by): array
    {
        $slug = 'pkg-'.Str::lower($t['template_id']);
        $def = array_intersect_key($t, array_flip(self::TEMPLATE)) + ['licence' => $t['licence'] ?? $manifest['licence'] ?? '', 'source' => $t['source'] ?? $manifest['source'] ?? '', 'author' => $t['author'] ?? $manifest['author'] ?? $manifest['vendor'] ?? ''];
        $h = hash('sha256', json_encode($def));
        $old = DB::table('template_library')->where('slug', $slug)->first();
        if ($old && ($old->content_hash === $h || DB::table('template_versions')->where(['slug' => $slug, 'content_hash' => $h])->exists())) {
            return ['slug' => $slug, 'name' => $t['name'] ?? $t['template_id'], 'result' => 'duplicate', 'note' => 'Already imported with the same content; skipped.'];
        }
        if (! $old && DB::table('template_library')->where('content_hash', $h)->exists()) {
            return ['slug' => $slug, 'name' => $t['name'] ?? $t['template_id'], 'result' => 'duplicate', 'note' => 'Another template has exactly the same content; skipped.'];
        }
        $version = $old ? max((int) $old->version, (int) DB::table('template_versions')->where('slug', $slug)->max('version')) + 1 : 1;
        DB::table('template_versions')->insert(['slug' => $slug, 'version' => $version, 'content_hash' => $h, 'definition_json' => json_encode($def), 'package_id' => $pid, 'created_by' => $by, 'created_at' => now()]);
        $row = ['kind' => $kind, 'name' => mb_substr((string) ($t['name'] ?? Str::headline($t['template_id'])), 0, 160), 'base' => $t['layout_family'],
            'category' => mb_substr((string) ($t['category'] ?? $t['job_family_ids'][0] ?? 'General'), 0, 80), 'licence' => mb_substr((string) $def['licence'], 0, 160), 'source' => mb_substr((string) $def['source'], 0, 200),
            'author' => mb_substr((string) $def['author'], 0, 160), 'preview' => '/api/admin/template-library/'.$slug.'/preview', 'compat_json' => json_encode($t['export_formats']),
            'ats_layout' => (bool) $t['ATS_mode'], 'single_column' => ! self::twoColumn((string) $t['layout_family']), 'origin' => 'package', 'package_id' => $pid, 'updated_at' => now()];
        if ($old) {
            // A new version is a draft until approved. Documents customers already made with the design keep working.
            DB::table('template_library')->where('slug', $slug)->update(['test_passed' => null, 'tested_version' => null, 'status' => 'draft',
                'definition_json' => json_encode($def), 'content_hash' => $h, 'version' => $version] + $row);
        } else {
            DB::table('template_library')->insert($row + ['slug' => $slug, 'version' => $version, 'status' => 'draft', 'content_hash' => $h, 'definition_json' => json_encode($def), 'created_at' => now()]);
        }
        $test = self::test($slug);

        return ['slug' => $slug, 'name' => $row['name'], 'result' => $old ? 'new_version' : 'new', 'version' => $version, 'testPassed' => $test['passed'], 'problems' => $test['problems']];
    }

    /** Approve (publish), reject or retire a design; roll back to an earlier version. */
    public static function decide(string $slug, string $action, ?int $by, ?int $version = null): array
    {
        $r = DB::table('template_library')->where('slug', $slug)->first();
        abort_unless($r, 404, 'No such template.');
        if ($action === 'approve') {
            abort_unless($r->origin === 'package', 422, 'Built-in designs are already live.');
            $t = $r->tested_version === $r->version && $r->test_passed !== null ? json_decode((string) $r->test_json, true) : self::test($slug);
            abort_unless($t['passed'] ?? false, 422, 'This design did not pass the text-reading test: '.implode('; ', $t['problems'] ?? []));
            DB::table('template_library')->where('slug', $slug)->update(['status' => 'published', 'approved_by' => $by, 'approved_at' => now(), 'updated_at' => now()]);
        } elseif ($action === 'reject') {
            abort_unless($r->status === 'draft', 422, 'Only a draft can be rejected.');
            DB::table('template_library')->where('slug', $slug)->update(['status' => 'rejected', 'updated_at' => now()]);
        } elseif ($action === 'retire') {
            DB::table('template_library')->where('slug', $slug)->update(['status' => 'retired', 'updated_at' => now()]);
        } elseif ($action === 'rollback') {
            $v = DB::table('template_versions')->where(['slug' => $slug, 'version' => (int) $version])->first();
            abort_unless($v, 422, 'No such version.');
            $def = json_decode($v->definition_json, true);
            DB::table('template_library')->where('slug', $slug)->update(['definition_json' => $v->definition_json, 'content_hash' => $v->content_hash, 'version' => (int) DB::table('template_versions')->where('slug', $slug)->max('version') + 1,
                'base' => $def['layout_family'] ?? $r->base, 'ats_layout' => (bool) ($def['ATS_mode'] ?? $r->ats_layout), 'single_column' => ! self::twoColumn((string) ($def['layout_family'] ?? $r->base)),
                'test_passed' => null, 'tested_version' => null, 'status' => 'draft', 'updated_at' => now()]);
            DB::table('template_versions')->insert(['slug' => $slug, 'version' => (int) DB::table('template_versions')->where('slug', $slug)->max('version') + 1, 'content_hash' => $v->content_hash,
                'definition_json' => $v->definition_json, 'package_id' => $v->package_id, 'created_by' => $by, 'created_at' => now()]);
            self::test($slug);
        } else {
            abort(422, 'Unknown action.');
        }
        Settings::audit($by, 'template_'.$action, $slug);

        return self::show($slug);
    }

    public static function show(string $slug): array
    {
        $r = DB::table('template_library')->where('slug', $slug)->first();
        abort_unless($r, 404, 'No such template.');

        return self::row($r) + ['test' => json_decode((string) $r->test_json, true), 'definition' => json_decode((string) $r->definition_json, true),
            'versions' => DB::table('template_versions')->where('slug', $slug)->orderByDesc('version')->get(['version', 'content_hash', 'package_id', 'created_at'])->map(fn ($v) => ['version' => (int) $v->version, 'hash' => substr($v->content_hash, 0, 12), 'packageId' => $v->package_id, 'at' => AgentLog::iso($v->created_at)])->all()];
    }

    public static function row($r): array
    {
        $r = (object) $r;

        return ['slug' => $r->slug, 'kind' => $r->kind, 'name' => $r->name, 'base' => $r->base, 'category' => $r->category, 'version' => (int) $r->version, 'licence' => $r->licence, 'source' => $r->source,
            'author' => $r->author, 'preview' => $r->preview, 'formats' => json_decode((string) $r->compat_json, true) ?: [], 'atsLayout' => (bool) $r->ats_layout, 'singleColumn' => (bool) $r->single_column,
            'testPassed' => $r->test_passed === null ? null : (bool) $r->test_passed, 'testedAt' => AgentLog::iso($r->tested_at), 'atsLabel' => (bool) $r->ats_layout && (bool) $r->single_column && (bool) $r->test_passed,
            'status' => $r->status, 'origin' => $r->origin];
    }

    /** HTML preview of a design with the demo data (for the console). */
    public static function preview(string $slug): string
    {
        $r = DB::table('template_library')->where('slug', $slug)->first();
        abort_unless($r, 404);

        return Documents::html(self::demo($r->kind, $r->base ?: $slug, json_decode((string) $r->definition_json, true) ?: []), $r->kind, false);
    }
}
