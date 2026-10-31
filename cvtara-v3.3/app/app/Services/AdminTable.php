<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * One page of an owner-console list: every console table asks the server for a single page (20 rows by default)
 * with its search, filters, date range and sort, and gets back that page plus the total. Nothing sends whole tables.
 *
 * Spec keys (all optional):
 *  search  => columns (or row keys) matched with LIKE / contains against ?q=
 *  filters => [param => column|key] exact match, or [param => fn ($query|$row, $value)] for anything else;
 *             a filter is skipped when its value is '' or 'all'
 *  date    => column (or row key) limited by ?from=Y-m-d and ?to=Y-m-d
 *  sorts   => [name => column|key] that ?sort= may pick; anything else falls back to 'sort'/'dir'
 *  key     => unique column added as the last sort so pages never overlap
 *  export  => optional ['title' => 'Orders', 'name' => 'cvtara-orders', 'columns' => [key => label], 'map' => fn (array $batch): array]
 *
 * Downloads: the same request with ?export=csv|xlsx|pdf answers with a file holding EVERY row that matches the
 * search, filters, date range and sort (not just the page on screen). Endpoints built on this class get it for free.
 */
final class AdminTable
{
    public const SIZES = [10, 20, 50, 100];

    public const PER = 20;

    /** Lists that are built in memory (page audit, Search Console rows, merged logs) never hold more than this. */
    public const MAX_ROWS = 5000;

    public static function query(Request $r, Builder $q, array $spec, ?callable $map = null): array
    {
        [$page, $per, $sort, $dir, $term] = self::params($r, $spec);
        if ($term !== '' && ! empty($spec['search'])) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $q->where(function ($w) use ($spec, $like) {
                foreach ($spec['search'] as $col) {
                    $w->orWhere($col, 'like', $like);
                }
            });
        }
        foreach ($spec['filters'] ?? [] as $param => $how) {
            $v = self::value($r, $param);
            if ($v !== null) {
                is_callable($how) ? $how($q, $v) : $q->where($how, $v);
            }
        }
        if (! empty($spec['date'])) {
            self::dates($r, $q, $spec['date']);
        }
        $total = (clone $q)->count();
        $page = min($page, max(1, (int) ceil($total / $per)));
        if ($sort !== null) {
            $q->orderBy($spec['sorts'][$sort], $dir);
        }
        if (! empty($spec['key'])) {
            $q->orderBy($spec['key'], $dir);
        }
        self::export($r, $q, $spec, $map, $total);
        $rows = $q->forPage($page, $per)->get();
        $rows = ($map ? $rows->map($map) : $rows)->values()->all();

        return self::out($rows, $total, $page, $per, $sort, $dir);
    }

    /** The same contract for lists that only exist in memory. */
    public static function rows(Request $r, array $rows, array $spec): array
    {
        [$page, $per, $sort, $dir, $term] = self::params($r, $spec);
        $rows = array_slice(array_values($rows), 0, self::exporting($r) ? Export::MAX_ROWS : self::MAX_ROWS);
        $get = fn ($row, $k) => is_array($row) ? ($row[$k] ?? null) : ($row->$k ?? null);
        if ($term !== '' && ! empty($spec['search'])) {
            $needle = mb_strtolower($term);
            $rows = array_filter($rows, function ($row) use ($spec, $needle, $get) {
                foreach ($spec['search'] as $k) {
                    if (str_contains(mb_strtolower((string) $get($row, $k)), $needle)) {
                        return true;
                    }
                }

                return false;
            });
        }
        foreach ($spec['filters'] ?? [] as $param => $how) {
            $v = self::value($r, $param);
            if ($v !== null) {
                $rows = array_filter($rows, fn ($row) => is_callable($how) ? $how($row, $v) : (string) $get($row, $how) === $v);
            }
        }
        if (! empty($spec['date'])) {
            $from = self::day($r, 'from');
            $to = self::day($r, 'to');
            $rows = array_filter($rows, function ($row) use ($spec, $from, $to, $get) {
                $d = substr((string) $get($row, $spec['date']), 0, 10);

                return (! $from || $d >= $from) && (! $to || $d <= $to);
            });
        }
        if ($sort !== null) {
            $k = $spec['sorts'][$sort];
            usort($rows, function ($a, $b) use ($k, $dir, $get) {
                $x = $get($a, $k);
                $y = $get($b, $k);
                $c = is_numeric($x) && is_numeric($y) ? $x <=> $y : strnatcasecmp((string) $x, (string) $y);

                return $dir === 'asc' ? $c : -$c;
            });
        }
        $total = count($rows);
        $page = min($page, max(1, (int) ceil($total / $per)));
        if ($fmt = Export::format($r)) {
            Export::guard($fmt, $total);
            $x = self::writer($r, $fmt, $spec);
            foreach (array_chunk(array_values($rows), 1000) as $batch) {
                $x->add(self::shape($spec, $batch));
            }
            self::send($r, $x, $spec);
        }

        return self::out(array_slice(array_values($rows), ($page - 1) * $per, $per), $total, $page, $per, $sort, $dir);
    }

    /** Limits a hand-built list query to ?from= / ?to= (Y-m-d, whole days). */
    public static function dates(Request $r, Builder $q, string $col): Builder
    {
        if ($from = self::day($r, 'from')) {
            $q->where($col, '>=', $from.' 00:00:00');
        }
        if ($to = self::day($r, 'to')) {
            $q->where($col, '<=', $to.' 23:59:59');
        }

        return $q;
    }

    /**
     * For lists that build their own (already filtered and ordered) query: when the request has ?export=, answer with
     * a file of every matching row and stop; otherwise do nothing and let the endpoint return its page as before.
     */
    public static function export(Request $r, Builder $q, array $spec = [], ?callable $map = null, ?int $total = null): void
    {
        if (! ($fmt = Export::format($r))) {
            return;
        }
        Export::guard($fmt, $total ?? (clone $q)->count());
        $x = self::writer($r, $fmt, $spec);
        for ($p = 1; ($batch = (clone $q)->forPage($p, 1000)->get())->isNotEmpty(); $p++) {
            $x->add(self::shape($spec, ($map ? $batch->map($map) : $batch)->values()->all()));
            if ($batch->count() < 1000) {
                break;
            }
        }
        self::send($r, $x, $spec);
    }

    /** True when this request asks for a file rather than a page. */
    public static function exporting(Request $r): bool
    {
        return Export::format($r) !== null;
    }

    private static function writer(Request $r, string $fmt, array $spec): Export
    {
        $e = $spec['export'] ?? [];
        $title = $e['title'] ?? self::title($r);

        return Export::open($fmt, $e['name'] ?? 'cvtara-'.strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)), $title, $e['columns'] ?? null);
    }

    private static function shape(array $spec, array $rows): array
    {
        $rows = array_map(fn ($x) => is_array($x) ? $x : (array) $x, $rows);

        return isset($spec['export']['map']) ? ($spec['export']['map'])($rows) : $rows;
    }

    /** Stops the request here and answers with the file: the endpoint's own JSON code never runs. */
    private static function send(Request $r, Export $x, array $spec): never
    {
        Settings::audit($r->user()?->id, 'list_exported', mb_substr(self::title($r).':'.$x->count(), 0, 190));

        throw new HttpResponseException($x->finish(Export::notes($r, $spec['export']['labels'] ?? [])));
    }

    /** "api/admin/referrals/withdrawals" -> "Referrals withdrawals" when the endpoint gives no title. */
    private static function title(Request $r): string
    {
        $p = preg_replace('#^api/admin/#', '', $r->path());

        return ucfirst(trim(str_replace(['/', '-', '_'], ' ', $p))) ?: 'Report';
    }

    private static function params(Request $r, array $spec): array
    {
        $per = (int) $r->query('per', $spec['per'] ?? self::PER);
        $per = in_array($per, self::SIZES, true) ? $per : self::PER;
        $page = max(1, min(100000, (int) $r->query('page', 1)));
        $sorts = $spec['sorts'] ?? [];
        $sort = (string) $r->query('sort', '');
        $askedDir = strtolower((string) $r->query('dir', ''));
        if (! array_key_exists($sort, $sorts)) {
            $sort = isset($spec['sort']) && array_key_exists($spec['sort'], $sorts) ? $spec['sort'] : null;
            $askedDir = '';
        }
        $dir = in_array($askedDir, ['asc', 'desc'], true) ? $askedDir : ($spec['dir'] ?? 'desc');

        return [$page, $per, $sort, $dir, mb_substr(trim((string) $r->query('q', '')), 0, 120)];
    }

    private static function value(Request $r, string $param): ?string
    {
        $v = $r->query($param);
        if (! is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' || $v === 'all' ? null : mb_substr($v, 0, 120);
    }

    private static function day(Request $r, string $param): ?string
    {
        $v = (string) $r->query($param, '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    private static function out(array $rows, int $total, int $page, int $per, ?string $sort, string $dir): array
    {
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'pages' => max(1, (int) ceil($total / $per)), 'sort' => $sort, 'dir' => $dir];
    }
}
