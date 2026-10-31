<?php

namespace App\Database;

use Illuminate\Database\Connectors\MySqlConnector;
use PDOException;
use Throwable;

/**
 * Hostinger limits how many new MySQL connections an account may open per second. During a burst of visitors the
 * extra connections are refused with "[2002] Operation not permitted" (or 1040/1203 "too many connections") and the
 * visitor got an error page. This connector waits a moment and tries again, up to three times, before giving up.
 */
class RetryingMySqlConnector extends MySqlConnector
{
    public const ATTEMPTS = 4;

    public function createConnection($dsn, array $config, array $options)
    {
        for ($try = 1; ; $try++) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (Throwable $e) {
                if ($try >= self::ATTEMPTS || ! self::isBusy($e)) {
                    throw $e;
                }
                usleep(random_int(120, 260) * 1000 * $try); // about 0.2 s, 0.4 s, 0.6 s
            }
        }
    }

    /** True for "the server is busy, try again" refusals; false for real faults such as a wrong password. */
    public static function isBusy(Throwable $e): bool
    {
        $m = $e->getMessage();

        return ($e instanceof PDOException || $e->getPrevious() instanceof PDOException)
            && (str_contains($m, 'Operation not permitted') || str_contains($m, 'Too many connections') || str_contains($m, 'max_user_connections') || str_contains($m, 'Resource temporarily unavailable'));
    }
}
