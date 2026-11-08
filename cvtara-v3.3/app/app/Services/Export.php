<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner-console downloads: one writer for CSV, Excel (.xlsx) and PDF so every list and report exports the same way.
 *
 *   $x = Export::open('xlsx', 'cvtara-orders', 'Orders');
 *   $x->add($rows);              // as many batches as needed; columns come from the first batch unless given
 *   return $x->finish(['Status: paid', 'From 2026-10-01 to 2026-10-31']);
 *
 * Rows are written to a temporary file as they arrive, so a 50,000-row export never sits in memory twice.
 * PDF is meant for reading and printing and stops at MAX_PDF rows; CSV and Excel take up to MAX_ROWS.
 */
final class Export
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public const MAX_ROWS = 50000;

    public const MAX_PDF = 3000;

    /** Columns that never leave the server, whatever list they turn up in. */
    private const HIDDEN = '/(password|passwd|token|secret|otp|remember|api_?key|private_?key|_hash$|^hash$|signature)/i';

    private string $fmt;

    private string $name;

    private string $title;

    /** @var array<string,string>|null key => label */
    private ?array $cols;

    private $fh = null;

    private string $tmp = '';

    private string $html = '';

    private int $count = 0;

    private function __construct(string $fmt, string $name, string $title, ?array $cols)
    {
        $this->fmt = $fmt;
        $this->name = preg_replace('/[^a-z0-9._-]+/i', '-', $name).'-'.now()->tz('Asia/Kolkata')->format('Ymd-His');
        $this->title = $title;
        $this->cols = $cols;
    }

    /** The format asked for with ?export=csv|xlsx|pdf (xls is read as xlsx), or null when this is a normal page request. */
    public static function format(Request $r): ?string
    {
        $f = strtolower((string) $r->query('export', ''));
        $f = $f === 'xls' || $f === 'excel' ? 'xlsx' : $f;

        return in_array($f, self::FORMATS, true) ? $f : null;
    }

    /** Refuse an export that is too big before any row is read. */
    public static function guard(string $fmt, int $total): void
    {
        abort_if($total > self::MAX_ROWS, 422, 'That is '.number_format($total).' rows, more than '.number_format(self::MAX_ROWS).' in one file. Pick a shorter date range or add a filter.');
        abort_if($fmt === 'pdf' && $total > self::MAX_PDF, 422, 'PDF is limited to '.number_format(self::MAX_PDF).' rows ('.number_format($total).' match). Download CSV or Excel instead, or pick a shorter date range.');
    }

    /** @param array<string,string>|null $cols key => label; null means "work it out from the first rows" */
    public static function open(string $fmt, string $name, string $title, ?array $cols = null): self
    {
        abort_unless(in_array($fmt, self::FORMATS, true), 422, 'Unknown file type.');
        $x = new self($fmt, $name, $title, $cols);
        if ($fmt !== 'pdf') {
            $x->tmp = tempnam(sys_get_temp_dir(), 'cvx');
            $x->fh = fopen($x->tmp, 'w+');
        }
        if ($cols !== null) {
            $x->head();
        }

        return $x;
    }

    /** @param iterable<array|object> $rows */
    public function add(iterable $rows): void
    {
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : (array) $row;
            if ($this->cols === null) {
                $this->cols = self::columns($row);
                $this->head();
            }
            if (++$this->count > self::MAX_ROWS || ($this->fmt === 'pdf' && $this->count > self::MAX_PDF)) {
                $this->count--;

                return;
            }
            $cells = [];
            foreach ($this->cols as $k => $label) {
                $cells[] = self::cell($k, $row[$k] ?? null);
            }
            $this->line($cells);
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @param string[] $notes filter and date-range lines printed above the rows (PDF) or kept out of the data (CSV/Excel) */
    public function finish(array $notes = []): Response
    {
        if ($this->cols === null) {
            $this->cols = ['empty' => 'No rows'];
            $this->head();
        }
        if ($this->fmt === 'pdf') {
            return $this->pdf($notes);
        }
        if ($this->fmt === 'xlsx') {
            fwrite($this->fh, '</sheetData><autoFilter ref="A1:'.self::ref(count($this->cols) - 1).max(1, $this->count + 1).'"/></worksheet>');
            fclose($this->fh);
            $file = $this->xlsx($this->tmp, $notes);
            @unlink($this->tmp);

            return self::send($file, $this->name.'.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        fclose($this->fh);

        return self::send($this->tmp, $this->name.'.csv', 'text/csv; charset=utf-8');
    }

    /** Text lines describing the filters in force, for the PDF heading and the Excel "About" sheet. */
    public static function notes(Request $r, array $labels = []): array
    {
        $out = [];
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '');
        if ($from !== '' || $to !== '') {
            $out[] = 'Dates: '.($from !== '' ? $from : 'start').' to '.($to !== '' ? $to : 'today');
        } else {
            $out[] = 'Dates: all time';
        }
        foreach ($r->query() as $k => $v) {
            if (! is_scalar($v) || in_array($k, ['export', 'page', 'per', 'from', 'to', 'sort', 'dir', 'columns', 'format', 'dataset', '_'], true)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v === '' || $v === 'all') {
                continue;
            }
            $out[] = ($labels[$k] ?? ($k === 'q' ? 'Search' : self::label((string) $k))).': '.mb_substr($v, 0, 120);
        }

        return $out;
    }

    /** "created_at" -> "Created at", "amount_paise" -> "Amount (₹)" */
    public static function label(string $k): string
    {
        $paise = str_ends_with($k, '_paise');
        $k = preg_replace('/_paise$|_json$/', '', $k);
        $k = preg_replace('/([a-z])([A-Z])/', '$1 $2', $k);
        $t = ucfirst(strtolower(trim(str_replace('_', ' ', $k))));

        return $paise ? $t.' (₹)' : $t;
    }

    /** @return array<string,string> */
    public static function columns(array $row): array
    {
        $cols = [];
        foreach ($row as $k => $v) {
            if (! is_string($k) || preg_match(self::HIDDEN, $k)) {
                continue;
            }
            $cols[$k] = self::label($k);
        }

        return $cols ?: ['empty' => 'No columns'];
    }

    private static function cell(string $k, $v)
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (str_ends_with($k, '_paise') && is_numeric($v)) {
            return round(((int) $v) / 100, 2);
        }
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_int($v) || is_float($v)) {
            return $v;
        }
        $v = (string) $v;

        return mb_strlen($v) > 32000 ? mb_substr($v, 0, 32000).'…' : $v;
    }

    private function head(): void
    {
        if ($this->fmt === 'csv') {
            fwrite($this->fh, "\xEF\xBB\xBF");
            fputcsv($this->fh, array_values($this->cols), ',', '"', '');
        } elseif ($this->fmt === 'xlsx') {
            $w = '';
            foreach (array_values($this->cols) as $i => $l) {
                $w .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(60, max(10, mb_strlen($l) + 4)).'" customWidth="1"/>';
            }
            fwrite($this->fh, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$w.'</cols><sheetData>');
            $this->xrow(array_values($this->cols), 1, true);
        } else {
            $this->html = '<thead><tr>'.implode('', array_map(fn ($l) => '<th>'.e($l).'</th>', $this->cols)).'</tr></thead><tbody>';
        }
    }

    private function line(array $cells): void
    {
        if ($this->fmt === 'csv') {
            // Spreadsheet programs run text that starts with = + - @ as a formula; make exported text inert.
            fputcsv($this->fh, array_map(fn ($c) => is_string($c) && $c !== '' && strpbrk($c[0], "=+-@\t\r") !== false && ! is_numeric($c) ? "'".$c : $c, $cells), ',', '"', '');
        } elseif ($this->fmt === 'xlsx') {
            $this->xrow($cells, $this->count + 1, false);
        } else {
            $this->html .= '<tr>'.implode('', array_map(fn ($c) => '<td'.(is_int($c) || is_float($c) ? ' class="n"' : '').'>'.e(mb_strlen((string) $c) > 400 ? mb_substr((string) $c, 0, 400).'…' : (string) $c).'</td>', $cells)).'</tr>';
        }
    }

    private static function ref(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + (($i - 1) % 26)).$s;
        }

        return $s;
    }

    private static function xml(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xrow(array $cells, int $n, bool $bold): void
    {
        $x = '<row r="'.$n.'">';
        foreach ($cells as $i => $c) {
            $r = self::ref($i).$n;
            if (! $bold && (is_int($c) || is_float($c))) {
                $x .= '<c r="'.$r.'"><v>'.$c.'</v></c>';
            } elseif ((string) $c !== '') {
                $x .= '<c r="'.$r.'" t="inlineStr"'.($bold ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::xml((string) $c).'</t></is></c>';
            }
        }
        fwrite($this->fh, $x.'</row>');
    }

    private function xlsx(string $sheet, array $notes): string
    {
        abort_unless(class_exists(\ZipArchive::class), 503, 'Excel export needs the PHP zip extension on the server. Download CSV instead.');
        $file = tempnam(sys_get_temp_dir(), 'cvz');
        $z = new \ZipArchive;
        abort_unless($z->open($file, \ZipArchive::OVERWRITE) === true, 500, 'Could not build the Excel file.');
        $about = [[$this->title], ['Downloaded '.now()->tz('Asia/Kolkata')->format('d M Y, h:i A').' IST'], ['Rows: '.$this->count], ...array_map(fn ($n) => [$n], $notes)];
        $aboutXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="1" width="80" customWidth="1"/></cols><sheetData>';
        foreach ($about as $i => $row) {
            $aboutXml .= '<row r="'.($i + 1).'"><c r="A'.($i + 1).'" t="inlineStr"'.($i === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.self::xml((string) $row[0]).'</t></is></c></row>';
        }
        $aboutXml .= '</sheetData></worksheet>';
        $sheetName = self::xml(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $this->title), 0, 31) ?: 'Data');
        $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$sheetName.'" sheetId="1" r:id="rId1"/><sheet name="About" sheetId="2" r:id="rId2"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\''.str_replace("'", "''", $sheetName).'\'!$A$1:$'.self::ref(count($this->cols) - 1).'$'.max(1, $this->count + 1).'</definedName></definedNames></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n".'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF9"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $z->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $z->addFromString('xl/worksheets/sheet2.xml', $aboutXml);
        $z->close();

        return $file;
    }

    private function pdf(array $notes): Response
    {
        $when = now()->tz('Asia/Kolkata')->format('d M Y, h:i A').' IST';
        $wide = count($this->cols) > 6;
        $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:26px 24px}body{font-family:"DejaVu Sans",sans-serif;font-size:'.($wide ? '7.5' : '9').'px;color:#0f172a}h1{font-size:15px;margin:0 0 2px}p.m{margin:0 0 8px;color:#475569;font-size:9px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #cbd5e1;padding:3px 4px;text-align:left;vertical-align:top;word-wrap:break-word}th{background:#e8eef9}td.n{text-align:right;white-space:nowrap}tr:nth-child(even) td{background:#f8fafc}</style></head><body>'
            .'<h1>CVTara · '.e($this->title).'</h1><p class="m">Downloaded '.e($when).' · '.number_format($this->count).' rows'.($notes ? ' · '.e(implode(' · ', $notes)) : '').'</p>'
            .'<table>'.$this->html.($this->count ? '' : '<tr><td colspan="'.count($this->cols).'">No rows match these filters.</td></tr>').'</tbody></table></body></html>';
        $o = new Options;
        $o->set('isRemoteEnabled', false);
        $o->set('defaultFont', 'DejaVu Sans');
        $o->set('tempDir', sys_get_temp_dir());
        $pdf = new Dompdf($o);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $wide ? 'landscape' : 'portrait');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$this->name.'.pdf"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private static function send(string $file, string $name, string $type): BinaryFileResponse
    {
        return response()->download($file, $name, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'])->deleteFileAfterSend(true);
    }
}
