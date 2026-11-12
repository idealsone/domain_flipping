<?php

namespace App\Http\Controllers;

use App\Services\AdminTable;
use App\Services\TemplateLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 3.59.0 Template quality (V7 §15, V5.0 §5-§7): every design's licence, source, author, version and text-reading
 * test; ZIP package import with a validation report; approve / reject / retire / roll back; coverage per role group.
 * Reached through /api/admin/template-library… (content.edit; approving and importing need content.edit too).
 */
class TemplateAdminController extends Controller
{
    public const ROUTES = '#^template-library(/.*)?$#';

    public function run(Request $r, string $path)
    {
        $ok = fn ($x = []) => response()->json(['ok' => true, ...$x]);
        $u = $r->user();
        $get = $r->isMethod('GET');
        if ($path === 'template-library' && $get) {
            TemplateLibrary::sync();
            $rows = DB::table('template_library')->orderBy('kind')->orderBy('category')->orderBy('name')->get()->map(fn ($x) => TemplateLibrary::row($x))->all();

            return $ok(AdminTable::rows($r, $rows, [
                'search' => ['name', 'slug', 'category', 'author'],
                'filters' => ['kind' => 'kind', 'status' => 'status', 'origin' => 'origin', 'test' => fn ($row, $v) => $v === 'untested' ? $row['testPassed'] === null : ($v === 'passed' ? $row['testPassed'] === true : $row['testPassed'] === false),
                    'label' => fn ($row, $v) => $row['atsLabel'] === ($v === 'yes')],
                'sorts' => ['name' => 'name', 'category' => 'category', 'tested' => 'testedAt', 'version' => 'version'], 'sort' => 'category', 'dir' => 'asc',
                'export' => ['title' => 'Template library', 'columns' => ['name' => 'Design', 'kind' => 'Type', 'category' => 'Role group', 'version' => 'Version', 'licence' => 'Licence', 'source' => 'Source', 'author' => 'Author',
                    'status' => 'Status', 'origin' => 'Origin', 'atsLayout' => 'Text-first layout', 'singleColumn' => 'Single column', 'testPassed' => 'Reading test passed', 'testedAt' => 'Tested', 'atsLabel' => 'Shows ATS label']],
            ]) + ['summary' => TemplateLibrary::coverage()['totals']]);
        }
        if ($path === 'template-library/coverage' && $get) {
            $c = TemplateLibrary::coverage();

            return $ok(AdminTable::rows($r, $c['clusters'], ['search' => ['cluster'], 'sorts' => ['cluster' => 'cluster', 'resumes' => 'resumes', 'letters' => 'letters', 'gap' => 'resumeGap'], 'sort' => 'gap', 'dir' => 'desc',
                'export' => ['title' => 'Template coverage', 'columns' => ['cluster' => 'Role group', 'resumes' => 'Resume designs', 'letters' => 'Cover letter designs', 'atsReady' => 'Resume designs with ATS label', 'resumeGap' => 'Resume designs needed', 'letterGap' => 'Cover letters needed']]])
                + ['target' => $c['target'], 'totals' => $c['totals']]);
        }
        if ($path === 'template-library/packages' && $get) {
            return $ok(AdminTable::query($r, DB::table('template_packages'), ['search' => ['name', 'vendor'], 'filters' => ['status' => 'status'], 'date' => 'created_at', 'sorts' => ['sort' => 'id'], 'key' => 'id',
                'export' => ['title' => 'Template packages', 'map' => fn ($rows) => array_map(fn ($x) => ['Uploaded' => $x['created_at'], 'Package' => $x['name'], 'Vendor' => $x['vendor'], 'Schema' => $x['schema_version'], 'Status' => $x['status'],
                    'Problems' => implode('; ', (array) (json_decode((string) $x['report_json'], true)['errors'] ?? []))], $rows)]], fn ($x) => ['id' => $x->id, 'name' => $x->name, 'vendor' => $x->vendor, 'status' => $x->status, 'created_at' => $x->created_at,
                    'report' => json_decode((string) $x->report_json, true)]));
        }
        if ($path === 'template-library/import' && ! $get) {
            $f = $r->file('package');
            abort_unless($f && $f->isValid(), 422, 'Choose a ZIP package or a Word file to upload.');
            $ext = strtolower($f->getClientOriginalExtension());
            if ($ext === 'docx') {
                // 3.61.0 one design from a Word file
                $v = $r->validate(['name' => 'nullable|string|max:160', 'kind' => 'nullable|in:resume,letter', 'licence' => 'nullable|string|max:160', 'source' => 'nullable|string|max:200',
                    'author' => 'nullable|string|max:160', 'category' => 'nullable|string|max:80']);

                return $ok(['report' => TemplateLibrary::importDocx($f->getRealPath(), $v + ['fileName' => $f->getClientOriginalName()], $u->id)]);
            }
            abort_unless($ext === 'zip', 422, 'Upload a .zip package or a .docx Word file.');

            return $ok(['report' => TemplateLibrary::import($f->getRealPath(), $u->id)]);
        }
        if ($path === 'template-library/test-due' && ! $get) {
            return $ok(['tested' => TemplateLibrary::testDue(40)]);
        }
        if (preg_match('#^template-library/(pkg-[a-z0-9_-]+|[a-z0-9-]+)(?:/(preview|test|decide))?$#', $path, $m)) {
            $act = $m[2] ?? '';
            if ($act === '' && $get) {
                return $ok(['template' => TemplateLibrary::show($m[1])]);
            }
            if ($act === 'preview' && $get) {
                return response(TemplateLibrary::preview($m[1]), 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:", 'X-Robots-Tag' => 'noindex']);
            }
            if ($act === 'test' && ! $get) {
                return $ok(['test' => TemplateLibrary::test($m[1]), 'template' => TemplateLibrary::show($m[1])]);
            }
            if ($act === 'decide' && ! $get) {
                $v = $r->validate(['action' => 'required|in:approve,reject,retire,rollback', 'version' => 'nullable|integer|min:1']);

                return $ok(['template' => TemplateLibrary::decide($m[1], $v['action'], $u->id, $v['version'] ?? null)]);
            }
        }
        abort(404);
    }
}
