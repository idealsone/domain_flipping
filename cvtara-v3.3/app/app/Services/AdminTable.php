<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
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
            if ($from = self::day($r, 'from')) {
                $q->where($spec['date'], '>=', $from.' 00:00:00');
            }
            if ($to = self::day($r, 'to')) {
                $q->where($spec['date'], '<=', $to.' 23:59:59');
            }
        }
        $total = (clone $q)->count();
        $page = min($page, max(1, (int) ceil($total / $per)));
        if ($sort !== null) {
            $q->orderBy($spec['sorts'][$sort], $dir);
        }
        if (! empty($spec['key'])) {
            $q->orderBy($spec['key'], $dir);
        }
        $rows = $q->forPage($page, $per)->get();
        $rows = ($map ? $rows->map($map) : $rows)->values()->all();

        return self::out($rows, $total, $page, $per, $sort, $dir);
    }

    /** The same contract for lists that only exist in memory. */
    public static function rows(Request $r, array $rows, array $spec): array
    {
        [$page, $per, $sort, $dir, $term] = self::params($r, $spec);
        $rows = array_slice(array_values($rows), 0, self::MAX_ROWS);
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

        return self::out(array_slice(array_values($rows), ($page - 1) * $per, $per), $total, $page, $per, $sort, $dir);
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
