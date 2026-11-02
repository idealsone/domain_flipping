"""Error Log resilience (3.27.2): busy-database retries, one row per fault, entries kept while MySQL is unreachable."""
import json, os, subprocess
NAME = "Error log and busy database"

PHP = r'''<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\ErrorLog; use Illuminate\Support\Facades\DB;
$out = [];
$out['connector'] = get_class(app('db.connector.mysql'));
$busy = new PDOException('SQLSTATE[HY000] [2002] Operation not permitted');
$out['busy2002'] = App\Database\RetryingMySqlConnector::isBusy($busy);
$out['busy1203'] = App\Database\RetryingMySqlConnector::isBusy(new PDOException("SQLSTATE[42000] [1203] User x already has more than 'max_user_connections' active connections"));
$out['notBusyPassword'] = App\Database\RetryingMySqlConnector::isBusy(new PDOException('SQLSTATE[HY000] [1045] Access denied for user'));
$out['limiter'] = config('cache.limiter');
$out['persistent'] = (bool) (config('database.connections.mysql.options')[PDO::ATTR_PERSISTENT] ?? false);
DB::table('error_logs')->delete();
foreach (['AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd', 'ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210zyxw'] as $sid) {
    ErrorLog::record('error', 'server', "QueryException: SQLSTATE[HY000] [2002] Operation not permitted (SQL: select * from `sessions` where `id` = $sid limit 1)", 'Connection.php:857', '/x');
}
$rows = DB::table('error_logs')->get();
$out['rowsForTwoSessions'] = $rows->count();
$out['occurrences'] = (int) ($rows[0]->occurrences ?? 0);
$out['sessionIdHidden'] = ! str_contains((string) ($rows[0]->message ?? ''), 'AbCdEfGh');
// database unreachable: the entry is parked in a file, then moved into the log by the cron
@unlink(ErrorLog::pendingFile());
$good = config('database.connections.sqlite.database');
config(['database.connections.sqlite.database' => '/nonexistent-dir/none.sqlite']); DB::purge('sqlite');
ErrorLog::record('error', 'server', 'Recorded while the database was down', 'Test.php:1');
$out['parked'] = is_file(ErrorLog::pendingFile());
$out['flushWhileDown'] = ErrorLog::flushPending();
$out['stillParked'] = is_file(ErrorLog::pendingFile());
config(['database.connections.sqlite.database' => $good]); DB::purge('sqlite');
$out['flushed'] = ErrorLog::flushPending();
$out['fileGone'] = ! is_file(ErrorLog::pendingFile());
$r = DB::table('error_logs')->where('message', 'Recorded while the database was down')->first();
$out['inLog'] = (bool) $r;
$out['markedDown'] = $r && str_contains((string) $r->context, 'recorded_while_database_unreachable');
DB::table('error_logs')->delete();
echo json_encode($out);
'''


def run(ctx, t):
    inst = ctx.inst
    f = os.path.join(inst.app, "s39_probe.php"); open(f, "w").write(PHP)
    try:
        r = subprocess.run(["php", f], cwd=inst.app, env=inst.env, capture_output=True, text=True, timeout=120)
    finally:
        os.unlink(f)
    try: o = json.loads(r.stdout.strip().splitlines()[-1])
    except Exception: t.check("error-log probe ran", False, (r.stdout + r.stderr)[-600:]); return
    t.check("MySQL connections retry when the host is busy", o["connector"].endswith("RetryingMySqlConnector"), o)
    t.check("'Operation not permitted' and max_user_connections count as busy", o["busy2002"] and o["busy1203"], o)
    t.check("a wrong database password is not retried", not o["notBusyPassword"], o)
    # the test instance sets CACHE_LIMITER=database so suites can reset limits; the shipped default is files
    t.check("rate limits are counted in files by default, not the cache table", "env('CACHE_LIMITER', 'file')" in open(os.path.join(ctx.app, "config", "cache.php")).read() and o["limiter"] == "database", o)
    t.check("MySQL connections are persistent by default", o["persistent"], o)
    t.check("the same fault with different session ids is one Error Log row", o["rowsForTwoSessions"] == 1 and o["occurrences"] == 2, o)
    t.check("session ids are not stored in the Error Log", o["sessionIdHidden"], o)
    t.check("an error while the database is down is kept in a file", o["parked"] and o["flushWhileDown"] == 0 and o["stillParked"], o)
    t.check("the cron moves it into the Error Log once the database is back", o["flushed"] == 1 and o["fileGone"] and o["inLog"] and o["markedDown"], o)
    src = open(os.path.join(ctx.public, "error-report.js")).read()
    t.check("dropped connections ('Failed to fetch') are not reported as site errors", "Failed to fetch" in src and "dynamically imported" not in src.split("Failed to fetch")[0][-200:], "")
