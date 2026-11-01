<?php
// Starts the revenue and offer counts from this moment (the owner's "Start counting from now"). Deletes nothing.
$h = '/home/u280039231/domains/cvtara.com';
require $h.'/cvtara_app/vendor/autoload.php';
$app = require $h.'/cvtara_app/bootstrap/app.php';
$app->usePublicPath($h.'/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\Settings;
use Illuminate\Support\Facades\DB;

$owner = DB::table('users')->where('is_admin', true)->orderBy('id')->value('id');
$ref = new ReflectionMethod(App\Http\Controllers\ReportController::class, 'kpis');
$ref->setAccessible(true);
$before = $ref->invoke(app(App\Http\Controllers\ReportController::class), 30);
echo 'before: revenue paise '.$before['netRevenuePaise'].' orders '.$before['orders'].' paid users '.$before['paidUsers'].PHP_EOL;
$money = fn () => DB::table('purchases')->whereIn('status', ['paid', 'partially_refunded', 'refunded'])->where('amount_paise', '>', 0);
echo 'orders in table: '.DB::table('purchases')->count().', paid with money: '.$money()->count().', of which live gateway: '.$money()->where('is_live', true)->whereIn('provider', ['razorpay', 'phonepe'])->count().', manual: '.$money()->where('provider', 'manual')->count().PHP_EOL;
if (Settings::statsSince()) {
    echo 'was already set: '.Settings::statsSince()->toIso8601String().PHP_EOL;
}
$baseline = [];
foreach (DB::table('promo_stats')->selectRaw('campaign_id, kind, sum(n) as n')->groupBy('campaign_id', 'kind')->get() as $row) {
    $baseline[$row->campaign_id][$row->kind] = (int) $row->n;
}
$at = now()->utc()->toIso8601String();
Settings::put('statsReset', ['at' => $at, 'by' => $owner, 'promoBaseline' => $baseline]);
Settings::audit($owner, 'stats_fresh_start', $at.' (deploy 3.29.3)');
Illuminate\Support\Facades\Cache::forget('admin-live');
$after = $ref->invoke(app(App\Http\Controllers\ReportController::class), 30);
echo 'counting since '.$after['countingSince'].PHP_EOL;
echo 'after: revenue paise '.$after['netRevenuePaise'].' orders '.$after['orders'].' paid users '.$after['paidUsers'].PHP_EOL;
echo 'offers seen now: '.array_sum(array_map(fn ($s) => $s['shown'], App\Services\Promos::stats())).' (before: '.array_sum(array_map(fn ($k) => $k['shown'] ?? 0, $baseline)).')'.PHP_EOL;
$g = App\Services\Growth::month();
echo 'growth month purchases '.$g['metrics']['purchases'].' gross '.$g['metrics']['grossPaise'].PHP_EOL;
echo 'orders still in table: '.DB::table('purchases')->count().PHP_EOL;
