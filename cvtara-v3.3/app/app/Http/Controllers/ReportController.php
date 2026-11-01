<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Export;
use App\Services\Settings;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Owner-only reports: server-side totals for the dashboard and a custom report builder with export. */
class ReportController extends Controller
{
    private const MAX_EXPORT = 50000;

    // Money that really reached the business: live gateway payments and manual payments with an amount received.
    private static function realMoney($q)
    {
        if (config('app.preview')) {
            return $q->whereNotIn('p.provider', ['referral_reward']); // demo environments count their sample payments
        }

        return $q->where('p.is_live', true)->whereNotIn('p.provider', ['demo', 'referral_reward']);
    }

    public function run(Request $r, string $path)
    {
        abort_unless($r->isMethod('GET'), 405);
        if ($path === 'report/meta') {
            return response()->json(['ok' => true, 'datasets' => array_map(fn ($d) => ['label' => $d['label'], 'columns' => $d['columns'], 'filters' => $d['filters'], 'defaults' => $d['defaults']], self::datasets())]);
        }
        if ($path === 'report/kpis') {
            return response()->json(['ok' => true, ...$this->kpis(in_array((int) $r->query('days', 30), [7, 30, 90], true) ? (int) $r->query('days', 30) : 30)]);
        }

        return $this->report($r);
    }

    private function kpis(int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $money = fn () => self::realMoney(DB::table('purchases as p'))->whereIn('p.status', ['paid', 'partially_refunded', 'refunded']);
        $net = (int) (clone $money())->selectRaw('COALESCE(SUM(p.amount_paise - p.refunded_paise),0) s')->value('s');
        $range = (int) (clone $money())->where('p.created_at', '>=', $since)->selectRaw('COALESCE(SUM(p.amount_paise - p.refunded_paise),0) s')->value('s');
        $rev = (clone $money())->where('p.created_at', '>=', $since)->selectRaw('substr(p.created_at,1,10) d, SUM(p.amount_paise - p.refunded_paise) s, COUNT(*) c')->groupBy('d')->get()->keyBy('d');
        $sig = DB::table('users')->where('created_at', '>=', $since)->selectRaw('substr(created_at,1,10) d, COUNT(*) c')->groupBy('d')->get()->keyBy('d');
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $series[] = ['date' => $d, 'signups' => (int) ($sig[$d]->c ?? 0), 'revenuePaise' => (int) ($rev[$d]->s ?? 0), 'orders' => (int) ($rev[$d]->c ?? 0)];
        }
        $users = User::count();
        $everPaid = (int) DB::table('purchases as p')->whereIn('p.status', ['paid', 'partially_refunded', 'refunded'])->whereNotNull('p.purchased_at')->whereNotIn('p.provider', ['referral_reward'])->distinct()->count('p.owner_id');
        $paidNow = (int) DB::table('purchases as p')->whereIn('p.status', ['paid', 'partially_refunded'])->where('p.access_until', '>', now())->whereNotIn('p.provider', ['referral_reward'])->distinct()->count('p.owner_id');

        return [
            'days' => $days, 'users' => $users, 'blocked' => User::where('blocked', true)->count(), 'signupsInRange' => array_sum(array_column($series, 'signups')),
            'signups7' => User::where('created_at', '>=', now()->subDays(7))->count(), 'paidUsers' => $paidNow, 'everPaid' => $everPaid,
            'conversionPct' => $users ? round($everPaid * 100 / $users, 1) : 0, 'netRevenuePaise' => $net, 'rangeRevenuePaise' => $range,
            'orders' => (int) (clone $money())->count(), 'ordersInRange' => (int) (clone $money())->where('p.created_at', '>=', $since)->count(),
            'avgOrderPaise' => ($c = (int) (clone $money())->count()) ? intdiv($net, $c) : 0, 'series' => $series,
            'note' => 'Revenue counts live gateway payments and manual payments with an amount received. Demo, test and zero-value grants are excluded.',
        ];
    }

    private static function inr($paise): string
    {
        return number_format(((int) $paise) / 100, 2, '.', '');
    }

    /** Dataset catalogue: label, selectable columns, supported filters, default columns. */
    private static function datasets(): array
    {
        return [
            'orders' => ['label' => 'Orders', 'filters' => ['q', 'status', 'plan', 'provider', 'from', 'to', 'min', 'max', 'live'], 'defaults' => ['order', 'date', 'customer', 'email', 'plan', 'amount_inr', 'status', 'provider'], 'columns' => [
                ['key' => 'order', 'label' => 'Order'], ['key' => 'date', 'label' => 'Date'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'email', 'label' => 'Email'], ['key' => 'plan', 'label' => 'Plan'],
                ['key' => 'amount_inr', 'label' => 'Amount (INR)', 'num' => true], ['key' => 'discount_inr', 'label' => 'Discount (INR)', 'num' => true], ['key' => 'reversed_inr', 'label' => 'Reversed by bank (INR)', 'num' => true], ['key' => 'net_inr', 'label' => 'Net (INR)', 'num' => true],
                ['key' => 'coupon', 'label' => 'Coupon'], ['key' => 'status', 'label' => 'Status'], ['key' => 'provider', 'label' => 'Payment source'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'live', 'label' => 'Real money'], ['key' => 'access_until', 'label' => 'Access until']]],
            'users' => ['label' => 'Users', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['id', 'name', 'email', 'joined', 'plan', 'status'], 'columns' => [
                ['key' => 'id', 'label' => 'ID', 'num' => true], ['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'phone', 'label' => 'Phone'], ['key' => 'city', 'label' => 'City'], ['key' => 'level', 'label' => 'Experience level'],
                ['key' => 'profession', 'label' => 'Profession'], ['key' => 'joined', 'label' => 'Joined'], ['key' => 'plan', 'label' => 'Current plan'], ['key' => 'paid', 'label' => 'Paid now'], ['key' => 'status', 'label' => 'Status'], ['key' => 'resumes', 'label' => 'Resumes', 'num' => true]]],
            'commissions' => ['label' => 'Referral commissions', 'filters' => ['q', 'from', 'to'], 'defaults' => ['date', 'referrer', 'buyer', 'amount_inr', 'rate', 'reversed_inr'], 'columns' => [
                ['key' => 'date', 'label' => 'Date'], ['key' => 'referrer', 'label' => 'Referrer'], ['key' => 'referrer_email', 'label' => 'Referrer email'], ['key' => 'buyer', 'label' => 'Buyer'], ['key' => 'amount_inr', 'label' => 'Commission (INR)', 'num' => true], ['key' => 'rate', 'label' => 'Rate %', 'num' => true], ['key' => 'reversed_inr', 'label' => 'Reversed (INR)', 'num' => true], ['key' => 'purchase', 'label' => 'Order']]],
            'withdrawals' => ['label' => 'Withdrawals', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['date', 'customer', 'amount_inr', 'method', 'destination', 'status'], 'columns' => [
                ['key' => 'date', 'label' => 'Requested'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'email', 'label' => 'Email'], ['key' => 'amount_inr', 'label' => 'Amount (INR)', 'num' => true], ['key' => 'method', 'label' => 'Method'], ['key' => 'destination', 'label' => 'Destination (masked)'], ['key' => 'status', 'label' => 'Status'], ['key' => 'reference', 'label' => 'Reference'], ['key' => 'paid_at', 'label' => 'Paid at']]],
            'errors' => ['label' => 'Error log', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['last_seen', 'level', 'source', 'message', 'occurrences'], 'columns' => [
                ['key' => 'last_seen', 'label' => 'Last seen'], ['key' => 'first_seen', 'label' => 'First seen'], ['key' => 'level', 'label' => 'Level'], ['key' => 'source', 'label' => 'Source'], ['key' => 'message', 'label' => 'Message'], ['key' => 'url', 'label' => 'Page'], ['key' => 'occurrences', 'label' => 'Times', 'num' => true], ['key' => 'resolved', 'label' => 'Resolved']]],
            'scans' => ['label' => 'ATS checks', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['date', 'customer', 'job_title', 'company', 'source', 'score', 'status'], 'columns' => [
                ['key' => 'date', 'label' => 'Checked'], ['key' => 'customer', 'label' => 'Customer'], ['key' => 'email', 'label' => 'Email'], ['key' => 'job_title', 'label' => 'Job title'], ['key' => 'company', 'label' => 'Company'], ['key' => 'source', 'label' => 'Job source'], ['key' => 'score', 'label' => 'Match %', 'num' => true], ['key' => 'status', 'label' => 'Status'], ['key' => 'job_url', 'label' => 'Job link']]],
            'daily' => ['label' => 'Daily summary', 'filters' => ['from', 'to'], 'defaults' => ['date', 'signups', 'orders', 'revenue_inr', 'reversed_inr', 'net_inr', 'ats_checks', 'resumes', 'page_views', 'visitors'], 'columns' => [
                ['key' => 'date', 'label' => 'Date'], ['key' => 'signups', 'label' => 'Sign-ups', 'num' => true], ['key' => 'orders', 'label' => 'Paid orders', 'num' => true], ['key' => 'revenue_inr', 'label' => 'Revenue (INR)', 'num' => true], ['key' => 'reversed_inr', 'label' => 'Reversed (INR)', 'num' => true], ['key' => 'net_inr', 'label' => 'Net (INR)', 'num' => true],
                ['key' => 'ats_checks', 'label' => 'ATS checks', 'num' => true], ['key' => 'resumes', 'label' => 'Resumes created', 'num' => true], ['key' => 'page_views', 'label' => 'Page views', 'num' => true], ['key' => 'visitors', 'label' => 'Visitors', 'num' => true], ['key' => 'emails_sent', 'label' => 'Emails sent', 'num' => true], ['key' => 'support', 'label' => 'Support reports', 'num' => true]]],
            'traffic' => ['label' => 'Pages visited', 'filters' => ['q', 'from', 'to'], 'defaults' => ['path', 'views', 'visitors'], 'columns' => [
                ['key' => 'path', 'label' => 'Page'], ['key' => 'views', 'label' => 'Views', 'num' => true], ['key' => 'visitors', 'label' => 'Visitors', 'num' => true]]],
            'sources' => ['label' => 'Traffic sources', 'filters' => ['from', 'to'], 'defaults' => ['source', 'views', 'visitors'], 'columns' => [
                ['key' => 'source', 'label' => 'Source'], ['key' => 'views', 'label' => 'Views', 'num' => true], ['key' => 'visitors', 'label' => 'Visitors', 'num' => true]]],
            'activity' => ['label' => 'Product activity', 'filters' => ['q', 'from', 'to'], 'defaults' => ['event', 'count', 'people'], 'columns' => [
                ['key' => 'event', 'label' => 'Action'], ['key' => 'count', 'label' => 'Times', 'num' => true], ['key' => 'people', 'label' => 'Signed-in people', 'num' => true]]],
            'reviews' => ['label' => 'User reviews', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['date', 'name', 'rating', 'review', 'status', 'source'], 'columns' => [
                ['key' => 'date', 'label' => 'Added'], ['key' => 'name', 'label' => 'Shown as'], ['key' => 'email', 'label' => 'Account email'], ['key' => 'rating', 'label' => 'Stars', 'num' => true], ['key' => 'review', 'label' => 'Review'], ['key' => 'status', 'label' => 'Status'], ['key' => 'source', 'label' => 'Source'], ['key' => 'consent', 'label' => 'Consent']]],
            'support' => ['label' => 'Support reports', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['date', 'name', 'email', 'area', 'kind', 'message', 'status'], 'columns' => [
                ['key' => 'date', 'label' => 'Sent'], ['key' => 'name', 'label' => 'Name'], ['key' => 'email', 'label' => 'Email'], ['key' => 'plan', 'label' => 'Plan'], ['key' => 'area', 'label' => 'Area'], ['key' => 'kind', 'label' => 'Kind'], ['key' => 'page', 'label' => 'Page'], ['key' => 'rating', 'label' => 'Rating', 'num' => true], ['key' => 'message', 'label' => 'Message'], ['key' => 'status', 'label' => 'Status'], ['key' => 'resolved_at', 'label' => 'Resolved']]],
            'growth' => ['label' => 'Growth reports', 'filters' => ['status', 'from', 'to'], 'defaults' => ['day', 'slot', 'band', 'report'], 'columns' => [
                ['key' => 'day', 'label' => 'Day'], ['key' => 'slot', 'label' => 'Report'], ['key' => 'band', 'label' => 'Status'], ['key' => 'report', 'label' => 'Report text'], ['key' => 'sent', 'label' => 'Written at']]],
            'mail' => ['label' => 'Email queue', 'filters' => ['q', 'status', 'from', 'to'], 'defaults' => ['date', 'recipient', 'subject', 'trigger', 'status'], 'columns' => [
                ['key' => 'date', 'label' => 'Created'], ['key' => 'recipient', 'label' => 'To'], ['key' => 'subject', 'label' => 'Subject'], ['key' => 'trigger', 'label' => 'Type'], ['key' => 'status', 'label' => 'Status'], ['key' => 'attempts', 'label' => 'Attempts', 'num' => true], ['key' => 'error', 'label' => 'Error'], ['key' => 'sent_at', 'label' => 'Sent']]],
        ];
    }

    private function report(Request $r)
    {
        $sets = self::datasets();
        $set = (string) $r->query('dataset', 'orders');
        abort_unless(isset($sets[$set]), 422, 'Unknown report.');
        $meta = $sets[$set];
        $allKeys = array_column($meta['columns'], 'key');
        $cols = $r->query('columns') === 'ALL' ? $allKeys : (array_values(array_intersect($allKeys, array_filter(explode(',', (string) $r->query('columns', ''))))) ?: $meta['defaults']);
        $page = max(1, (int) $r->query('page', 1));
        $format = in_array($r->query('format'), ['csv', 'xls', 'xlsx', 'pdf', 'json'], true) ? $r->query('format') : 'json';
        if (in_array($set, self::SUMMARIES, true)) {
            return $this->summaryReport($r, $set, $meta, $cols, $format);
        }
        $per = $format === 'json' ? min(500, max(10, (int) $r->query('per', 50))) : self::MAX_EXPORT;
        $dir = $r->query('dir') === 'asc' ? 'asc' : 'desc';
        [$q, $sortable, $map, $totalsQ] = $this->build($set, $r);
        $sort = (string) $r->query('sort', '');
        $q->orderBy(isset($sortable[$sort]) ? $sortable[$sort] : $sortable['_default'], isset($sortable[$sort]) ? $dir : 'desc');
        $total = (clone $totalsQ)->count();
        $rows = $q->forPage($format === 'json' ? $page : 1, $per)->get();
        $out = $this->shape($set, $rows, $map);
        $out = array_map(fn ($row) => array_intersect_key($row, array_flip($cols)) + ($set === 'orders' && isset($row['id']) ? ['id' => $row['id']] : []), $out);
        $columns = array_values(array_filter($meta['columns'], fn ($c) => in_array($c['key'], $cols, true)));
        $columns = array_values(array_map(fn ($k) => collect($columns)->firstWhere('key', $k), $cols));
        $sums = null;
        if ($set === 'orders') {
            $agg = (clone $totalsQ)->selectRaw('COALESCE(SUM(p.amount_paise),0) a, COALESCE(SUM(p.refunded_paise),0) b, COALESCE(SUM(p.discount_paise),0) d')->first();
            $sums = ['amount_inr' => (float) self::inr($agg->a), 'reversed_inr' => (float) self::inr($agg->b), 'discount_inr' => (float) self::inr($agg->d), 'net_inr' => (float) self::inr($agg->a - $agg->b)];
        }
        Settings::audit($r->user()->id, 'report_'.($format === 'json' ? 'viewed' : 'exported'), $set.':'.$total);
        if ($format === 'json') {
            return response()->json(['ok' => true, 'dataset' => $set, 'columns' => $columns, 'rows' => $out, 'total' => $total, 'page' => $page, 'per' => $per, 'sums' => $sums]);
        }
        abort_if($total > self::MAX_EXPORT, 422, 'Too many rows to export at once. Narrow the dates.');
        if ($format !== 'xls') {
            return $this->file($r, $set, $format, $columns, $out, $total);
        }
        $name = 'cvtara-'.$set.'-'.now()->format('Ymd-His');
        $head = array_map(fn ($c) => $c['label'], $columns);
        $html = '<html><head><meta charset="utf-8"></head><body><table border="1"><tr>'.implode('', array_map(fn ($h) => '<th>'.e($h).'</th>', $head)).'</tr>';
        foreach ($out as $row) {
            $html .= '<tr>'.implode('', array_map(fn ($c) => '<td>'.e((string) self::safeCell($row[$c['key']] ?? '')).'</td>', $columns)).'</tr>';
        }

        return response($html.'</table></body></html>', 200, ['Content-Type' => 'application/vnd.ms-excel; charset=utf-8', 'Content-Disposition' => 'attachment; filename="'.$name.'.xls"', 'Cache-Control' => 'private, no-store']);
    }

    /** Data sets that are totals worked out per day / page / source rather than one row per record. */
    private const SUMMARIES = ['daily', 'traffic', 'sources', 'activity'];

    private function file(Request $r, string $set, string $format, array $columns, array $rows, int $total)
    {
        Export::guard($format, $total);
        $x = Export::open($format, 'cvtara-'.$set, self::datasets()[$set]['label'], array_column($columns, 'label', 'key'));
        $x->add($rows);

        return $x->finish(Export::notes($r, ['q' => 'Search', 'live' => 'Real money only', 'min' => 'Min ₹', 'max' => 'Max ₹']));
    }

    private function summaryReport(Request $r, string $set, array $meta, array $cols, string $format)
    {
        $tz = 'Asia/Kolkata';
        $day = fn ($k, $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query($k, '')) ? (string) $r->query($k) : $def;
        $to = $day('to', now()->tz($tz)->toDateString());
        $from = $day('from', Carbon::parse($to)->subDays(29)->toDateString());
        abort_if($from > $to, 422, 'The From date is after the To date.');
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 1100, 422, 'Pick a range of three years or less.');
        $range = fn ($q, $col) => $q->where($col, '>=', $from.' 00:00:00')->where($col, '<=', $to.' 23:59:59');
        $term = trim((string) $r->query('q', ''));
        $rows = [];
        if ($set === 'daily') {
            $per = fn ($q, $col, $sel = 'COUNT(*) c') => $range($q, $col)->selectRaw('substr('.$col.',1,10) d, '.$sel)->groupBy('d')->get()->keyBy('d');
            $sig = $per(DB::table('users'), 'created_at');
            $rev = $per(self::realMoney(DB::table('purchases as p'))->whereIn('p.status', ['paid', 'partially_refunded', 'refunded']), 'p.created_at', 'COUNT(*) c, SUM(p.amount_paise) a, SUM(p.refunded_paise) b');
            $ats = $per(DB::table('ats_scans')->where('status', 'done'), 'created_at');
            $res = $per(DB::table('resume_drafts'), 'created_at');
            $pv = $per(DB::table('page_views'), 'created_at', 'COUNT(*) c, COUNT(DISTINCT visitor_id) v');
            $mail = $per(DB::table('mail_outbox')->where('status', 'sent'), 'created_at');
            $sup = $per(DB::table('support_reports'), 'created_at');
            for ($d = Carbon::parse($to); $d->toDateString() >= $from; $d->subDay()) {
                $k = $d->toDateString();
                $a = (int) ($rev[$k]->a ?? 0);
                $b = (int) ($rev[$k]->b ?? 0);
                $rows[] = ['date' => $k, 'signups' => (int) ($sig[$k]->c ?? 0), 'orders' => (int) ($rev[$k]->c ?? 0), 'revenue_inr' => (float) self::inr($a), 'reversed_inr' => (float) self::inr($b), 'net_inr' => (float) self::inr($a - $b),
                    'ats_checks' => (int) ($ats[$k]->c ?? 0), 'resumes' => (int) ($res[$k]->c ?? 0), 'page_views' => (int) ($pv[$k]->c ?? 0), 'visitors' => (int) ($pv[$k]->v ?? 0), 'emails_sent' => (int) ($mail[$k]->c ?? 0), 'support' => (int) ($sup[$k]->c ?? 0)];
            }
        } elseif ($set === 'traffic' || $set === 'sources') {
            $col = $set === 'traffic' ? 'path' : 'source';
            $q = $range(DB::table('page_views'), 'created_at');
            if ($term !== '' && $set === 'traffic') {
                $q->where('path', 'like', $this->like($term));
            }
            $rows = $q->selectRaw($col.', COUNT(*) views, COUNT(DISTINCT visitor_id) visitors')->groupBy($col)->orderByDesc('views')->limit(self::MAX_EXPORT)->get()
                ->map(fn ($x) => [$col => $x->$col, 'views' => (int) $x->views, 'visitors' => (int) $x->visitors])->all();
        } else {
            $q = $range(DB::table('activity_events'), 'created_at');
            if ($term !== '') {
                $q->where('event', 'like', $this->like($term));
            }
            $rows = $q->selectRaw('event, COUNT(*) c, COUNT(DISTINCT owner_id) p')->groupBy('event')->orderByDesc('c')->get()
                ->map(fn ($x) => ['event' => str_replace('_', ' ', $x->event), 'count' => (int) $x->c, 'people' => (int) $x->p])->all();
        }
        $sort = (string) $r->query('sort', '');
        if (in_array($sort, array_column($meta['columns'], 'key'), true)) {
            $dir = $r->query('dir') === 'asc' ? 1 : -1;
            usort($rows, fn ($a, $b) => (is_numeric($a[$sort]) && is_numeric($b[$sort]) ? $a[$sort] <=> $b[$sort] : strnatcasecmp((string) $a[$sort], (string) $b[$sort])) * $dir);
        }
        $total = count($rows);
        $columns = array_values(array_filter(array_map(fn ($k) => collect($meta['columns'])->firstWhere('key', $k), $cols)));
        $sums = null;
        if ($set === 'daily') {
            foreach (array_filter($meta['columns'], fn ($c) => ! empty($c['num'])) as $c) {
                $sums[$c['key']] = round(array_sum(array_column($rows, $c['key'])), 2);
            }
        }
        Settings::audit($r->user()->id, 'report_'.($format === 'json' ? 'viewed' : 'exported'), $set.':'.$total);
        $rows = array_map(fn ($row) => array_intersect_key($row, array_flip($cols)), $rows);
        if ($format === 'json') {
            $per = min(500, max(10, (int) $r->query('per', 50)));
            $page = max(1, (int) $r->query('page', 1));

            return response()->json(['ok' => true, 'dataset' => $set, 'columns' => $columns, 'rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => $total, 'page' => $page, 'per' => $per, 'sums' => $sums, 'range' => ['from' => $from, 'to' => $to]]);
        }
        if ($format === 'xls') {
            $format = 'xlsx';
        }
        if ($sums) {
            $rows[] = ['date' => 'Total'] + array_intersect_key($sums, array_flip($cols));
        }
        $r->query->set('from', $from);
        $r->query->set('to', $to);

        return $this->file($r, $set, $format, $columns, $rows, $total);
    }

    // Spreadsheet programs run text that starts with = + - @ as a formula; make exported text inert.
    private static function safeCell($v)
    {
        return is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && ! is_numeric($v) ? "'".$v : $v;
    }

    private function dates($q, Request $r, string $col)
    {
        if ($r->query('from') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('from'))) {
            $q->where($col, '>=', $r->query('from').' 00:00:00');
        }
        if ($r->query('to') && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r->query('to'))) {
            $q->where($col, '<=', $r->query('to').' 23:59:59');
        }

        return $q;
    }

    private function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }

    /** @return array{0:Builder,1:array,2:mixed,3:Builder} */
    private function build(string $set, Request $r): array
    {
        $term = trim((string) $r->query('q', ''));
        if ($set === 'orders') {
            $q = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.owner_id');
            $this->dates($q, $r, 'p.created_at');
            foreach (['status' => 'p.status', 'plan' => 'p.plan', 'provider' => 'p.provider'] as $k => $col) {
                if ($r->query($k) && $r->query($k) !== 'all') {
                    $q->where($col, $r->query($k));
                }
            }
            if ($r->query('live') === '1') {
                self::realMoney($q);
            }
            if (is_numeric($r->query('min'))) {
                $q->where('p.amount_paise', '>=', (int) round($r->query('min') * 100));
            }
            if (is_numeric($r->query('max'))) {
                $q->where('p.amount_paise', '<=', (int) round($r->query('max') * 100));
            }
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('u.email', 'like', $l)->orWhere('u.name', 'like', $l)->orWhere('p.id', 'like', $l)->orWhere('p.payment_reference', 'like', $l)->orWhere('p.provider_order_id', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['p.*', 'u.email as u_email', 'u.name as u_name']), ['_default' => 'p.created_at', 'date' => 'p.created_at', 'amount_inr' => 'p.amount_paise', 'status' => 'p.status', 'plan' => 'p.plan', 'customer' => 'u.name', 'email' => 'u.email', 'provider' => 'p.provider'], null, $count];
        }
        if ($set === 'users') {
            $q = DB::table('users as u')->leftJoin('profiles as pr', 'pr.owner_id', '=', 'u.id');
            $this->dates($q, $r, 'u.created_at');
            $st = $r->query('status');
            if ($st === 'blocked') {
                $q->where('u.blocked', true);
            } elseif ($st === 'owner') {
                $q->where('u.is_admin', true);
            } elseif (in_array($st, ['paid', 'normal'], true)) {
                $paid = DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded'])->where('access_until', '>', now())->where('provider', '!=', 'referral_reward')->select('owner_id');
                $st === 'paid' ? $q->whereIn('u.id', $paid) : $q->whereNotIn('u.id', $paid);
            }
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('u.email', 'like', $l)->orWhere('u.name', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['u.id', 'u.name', 'u.email', 'u.created_at', 'u.blocked', 'u.is_admin', 'pr.phone', 'pr.city', 'pr.experience_level', 'pr.profession']), ['_default' => 'u.id', 'id' => 'u.id', 'name' => 'u.name', 'email' => 'u.email', 'joined' => 'u.created_at'], null, $count];
        }
        if ($set === 'commissions') {
            $q = DB::table('referral_commissions as c')->join('users as a', 'a.id', '=', 'c.referrer_id')->join('users as b', 'b.id', '=', 'c.buyer_id');
            $this->dates($q, $r, 'c.created_at');
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('a.email', 'like', $l)->orWhere('a.name', 'like', $l)->orWhere('b.email', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['c.*', 'a.name as a_name', 'a.email as a_email', 'b.name as b_name']), ['_default' => 'c.created_at', 'date' => 'c.created_at', 'amount_inr' => 'c.amount_paise'], null, $count];
        }
        if ($set === 'withdrawals') {
            $q = DB::table('withdrawals as w')->join('users as u', 'u.id', '=', 'w.owner_id');
            $this->dates($q, $r, 'w.created_at');
            if ($r->query('status') && $r->query('status') !== 'all') {
                $q->where('w.status', $r->query('status'));
            }
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('u.email', 'like', $l)->orWhere('u.name', 'like', $l)->orWhere('w.reference', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['w.id', 'w.amount_paise', 'w.method', 'w.destination_masked', 'w.status', 'w.reference', 'w.created_at', 'w.paid_at', 'u.name as u_name', 'u.email as u_email']), ['_default' => 'w.created_at', 'date' => 'w.created_at', 'amount_inr' => 'w.amount_paise', 'status' => 'w.status'], null, $count];
        }
        if ($set === 'scans') {
            $q = DB::table('ats_scans as a')->join('users as u', 'u.id', '=', 'a.owner_id');
            $this->dates($q, $r, 'a.created_at');
            if (in_array($r->query('status'), ['done', 'void', 'reserved'], true)) {
                $q->where('a.status', $r->query('status'));
            }
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('u.email', 'like', $l)->orWhere('u.name', 'like', $l)->orWhere('a.job_title', 'like', $l)->orWhere('a.company', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['a.id', 'a.source', 'a.job_url', 'a.job_title', 'a.company', 'a.score', 'a.status', 'a.created_at', 'u.name as u_name', 'u.email as u_email']), ['_default' => 'a.created_at', 'date' => 'a.created_at', 'score' => 'a.score', 'status' => 'a.status'], null, $count];
        }
        if ($set === 'errors') {
            $q = DB::table('error_logs as e');
            $this->dates($q, $r, 'e.last_seen');
            $st = $r->query('status');
            if (in_array($st, ['error', 'warning', 'info'], true)) {
                $q->where('e.level', $st);
            } elseif ($st === 'open') {
                $q->whereNull('e.resolved_at');
            }
            if ($term !== '') {
                $q->where('e.message', 'like', $this->like($term));
            }
            $count = clone $q;

            return [$q->select('e.*'), ['_default' => 'e.last_seen', 'last_seen' => 'e.last_seen', 'level' => 'e.level', 'source' => 'e.source', 'occurrences' => 'e.occurrences'], null, $count];
        }
        if ($set === 'reviews') {
            $q = DB::table('feedback as f')->leftJoin('users as u', 'u.id', '=', 'f.owner_id');
            $this->dates($q, $r, 'f.created_at');
            if (in_array($r->query('status'), ['pending', 'published', 'hidden'], true)) {
                $q->where('f.status', $r->query('status'));
            }
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('f.display_name', 'like', $l)->orWhere('f.content', 'like', $l)->orWhere('u.email', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['f.created_at', 'f.display_name', 'f.rating', 'f.content', 'f.status', 'f.source', 'f.consent', 'u.email as u_email']), ['_default' => 'f.created_at', 'date' => 'f.created_at', 'rating' => 'f.rating', 'status' => 'f.status', 'name' => 'f.display_name'], null, $count];
        }
        if ($set === 'support') {
            $q = DB::table('support_reports as s');
            $this->dates($q, $r, 's.created_at');
            if (in_array($r->query('status'), ['new', 'seen', 'working', 'resolved', 'closed'], true)) {
                $q->where('s.status', $r->query('status'));
            } elseif ($r->query('status') === 'open') {
                $q->whereIn('s.status', ['new', 'seen', 'working']);
            }
            if ($term !== '') {
                $l = $this->like($term);
                $q->where(fn ($w) => $w->where('s.message', 'like', $l)->orWhere('s.email', 'like', $l)->orWhere('s.name', 'like', $l)->orWhere('s.page', 'like', $l));
            }
            $count = clone $q;

            return [$q->select(['s.created_at', 's.name', 's.email', 's.plan', 's.area', 's.kind', 's.page', 's.rating', 's.message', 's.status', 's.resolved_at']), ['_default' => 's.created_at', 'date' => 's.created_at', 'status' => 's.status', 'rating' => 's.rating', 'area' => 's.area'], null, $count];
        }
        if ($set === 'growth') {
            $q = DB::table('growth_reports as g');
            $this->dates($q, $r, 'g.created_at');
            if (is_string($r->query('status')) && preg_match('/^[a-z0-9]{1,10}$/', $r->query('status'))) {
                $q->where('g.slot', $r->query('status'));
            }
            $count = clone $q;

            return [$q->select(['g.day', 'g.slot', 'g.band', 'g.body', 'g.created_at']), ['_default' => 'g.created_at', 'day' => 'g.day', 'slot' => 'g.slot', 'band' => 'g.band'], null, $count];
        }
        $q = DB::table('mail_outbox as m');
        $this->dates($q, $r, 'm.created_at');
        if ($r->query('status') && $r->query('status') !== 'all') {
            $q->where('m.status', $r->query('status'));
        }
        if ($term !== '') {
            $l = $this->like($term);
            $q->where(fn ($w) => $w->where('m.recipient', 'like', $l)->orWhere('m.subject', 'like', $l));
        }
        $count = clone $q;

        return [$q->select(['m.id', 'm.recipient', 'm.subject', 'm.trigger', 'm.status', 'm.attempts', 'm.error', 'm.created_at', 'm.sent_at']), ['_default' => 'm.created_at', 'date' => 'm.created_at', 'status' => 'm.status'], null, $count];
    }

    private function shape(string $set, $rows, $map): array
    {
        $acc = $res = $plans = null;
        if ($set === 'users') {
            $ids = $rows->pluck('id')->all();
            $acc = $ids ? Settings::accessMany($ids) : [];
            $res = $ids ? DB::table('resume_drafts')->whereIn('owner_id', $ids)->groupBy('owner_id')->selectRaw('owner_id, count(*) c')->pluck('c', 'owner_id') : collect();
            $plans = Settings::plans();
        }

        return $rows->map(function ($x) use ($set, $acc, $res, $plans) {
            return match ($set) {
                'orders' => ['order' => 'ORD-'.strtoupper(substr($x->id, 0, 6)), 'date' => $x->created_at, 'customer' => $x->u_name, 'email' => $x->u_email, 'plan' => $x->plan, 'amount_inr' => (float) self::inr($x->amount_paise), 'discount_inr' => (float) self::inr($x->discount_paise), 'reversed_inr' => (float) self::inr($x->refunded_paise), 'net_inr' => (float) self::inr($x->amount_paise - $x->refunded_paise), 'coupon' => $x->coupon_code, 'status' => $x->status, 'provider' => $x->provider, 'reference' => $x->payment_reference ?: $x->provider_order_id, 'live' => $x->is_live ? 'Yes' : 'No', 'access_until' => $x->access_until, 'id' => $x->id],
                'users' => ['id' => (int) $x->id, 'name' => $x->name, 'email' => $x->email, 'phone' => $x->phone, 'city' => $x->city, 'level' => $x->experience_level, 'profession' => $x->profession, 'joined' => $x->created_at, 'plan' => ($acc[$x->id]['paid'] ?? false) ? ($plans[$acc[$x->id]['plan']]['name'] ?? $acc[$x->id]['plan']) : 'Normal user', 'paid' => ($acc[$x->id]['paid'] ?? false) ? 'Yes' : 'No', 'status' => $x->blocked ? 'Blocked' : ($x->is_admin ? 'Owner' : 'Active'), 'resumes' => (int) ($res[$x->id] ?? 0)],
                'commissions' => ['date' => $x->created_at, 'referrer' => $x->a_name, 'referrer_email' => $x->a_email, 'buyer' => $x->b_name, 'amount_inr' => (float) self::inr($x->amount_paise), 'rate' => $x->rate_bps / 100, 'reversed_inr' => (float) self::inr($x->reversed_paise), 'purchase' => 'ORD-'.strtoupper(substr($x->purchase_id, 0, 6))],
                'withdrawals' => ['date' => $x->created_at, 'customer' => $x->u_name, 'email' => $x->u_email, 'amount_inr' => (float) self::inr($x->amount_paise), 'method' => $x->method, 'destination' => $x->destination_masked, 'status' => $x->status, 'reference' => $x->reference, 'paid_at' => $x->paid_at],
                'scans' => ['date' => $x->created_at, 'customer' => $x->u_name, 'email' => $x->u_email, 'job_title' => $x->job_title, 'company' => $x->company, 'source' => $x->source, 'score' => $x->score, 'status' => $x->status, 'job_url' => $x->job_url],
                'reviews' => ['date' => $x->created_at, 'name' => $x->display_name, 'email' => $x->u_email, 'rating' => (int) $x->rating, 'review' => $x->content, 'status' => $x->status, 'source' => $x->source === 'dummy' ? 'Sample' : $x->source, 'consent' => $x->consent ? 'Yes' : 'No'],
                'support' => ['date' => $x->created_at, 'name' => $x->name, 'email' => $x->email, 'plan' => $x->plan, 'area' => $x->area, 'kind' => $x->kind, 'page' => $x->page, 'rating' => $x->rating === null ? '' : (int) $x->rating, 'message' => $x->message, 'status' => $x->status, 'resolved_at' => $x->resolved_at],
                'growth' => ['day' => $x->day, 'slot' => $x->slot, 'band' => $x->band, 'report' => trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/h\d|/tr)[^>]*>#i', "\n", (string) $x->body)), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'sent' => $x->created_at],
                'errors' => ['last_seen' => $x->last_seen, 'first_seen' => $x->first_seen, 'level' => $x->level, 'source' => $x->source, 'message' => $x->message, 'url' => $x->url, 'occurrences' => (int) $x->occurrences, 'resolved' => $x->resolved_at ? 'Yes' : 'No'],
                default => ['date' => $x->created_at, 'recipient' => $x->recipient, 'subject' => $x->subject, 'trigger' => $x->trigger, 'status' => $x->status, 'attempts' => (int) $x->attempts, 'error' => $x->error, 'sent_at' => $x->sent_at],
            };
        })->all();
    }
}
