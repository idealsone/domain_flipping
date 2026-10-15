<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Daily database backup kept in the private storage folder (gzip-compressed SQL), newest 14 kept. */
class Backup
{
    private const SKIP = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    public static function dir(): string
    {
        $d = storage_path('app/backups');
        if (! is_dir($d)) {
            mkdir($d, 0700, true);
        }

        return $d;
    }

    public static function run(): string
    {
        $pdo = DB::connection()->getPdo();
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $file = self::dir().'/cvtara-'.now()->format('Ymd-His').'.sql.gz';
        $gz = gzopen($file.'.part', 'wb6');
        gzwrite($gz, "-- CVTara backup ".now()->toIso8601String()."\n".($driver === 'mysql' ? "SET FOREIGN_KEY_CHECKS=0;\n" : "PRAGMA foreign_keys=OFF;\n"));
        $tables = $driver === 'mysql'
            ? array_map(fn ($r) => array_values((array) $r)[0], DB::select('SHOW TABLES'))
            : array_map(fn ($r) => $r->name, DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"));
        foreach ($tables as $t) {
            if (in_array($t, self::SKIP, true)) {
                continue;
            }
            $q = $driver === 'mysql' ? '`'.str_replace('`', '', $t).'`' : '"'.str_replace('"', '', $t).'"';
            $create = $driver === 'mysql' ? ((array) DB::selectOne("SHOW CREATE TABLE $q"))['Create Table'] : DB::selectOne("SELECT sql FROM sqlite_master WHERE name = ?", [$t])->sql;
            gzwrite($gz, "\nDROP TABLE IF EXISTS $q;\n$create;\n");
            $offset = 0;
            while (true) {
                $rows = DB::select("SELECT * FROM $q LIMIT 500 OFFSET $offset");
                if (! $rows) {
                    break;
                }
                foreach ($rows as $r) {
                    $vals = array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $r);
                    gzwrite($gz, "INSERT INTO $q VALUES (".implode(',', $vals).");\n");
                }
                $offset += 500;
            }
        }
        gzclose($gz);
        rename($file.'.part', $file);
        @chmod($file, 0600);
        $all = glob(self::dir().'/cvtara-*.sql.gz') ?: [];
        rsort($all);
        foreach (array_slice($all, 14) as $old) {
            @unlink($old);
        }
        Settings::put('lastBackup', ['at' => now()->toIso8601String(), 'file' => basename($file), 'bytes' => filesize($file)]);

        return $file;
    }
}
