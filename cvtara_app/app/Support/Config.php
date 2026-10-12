<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Reads secrets from .env first, then from owner Settings so keys can be
 * pasted in /admin/settings without editing files on Hostinger.
 */
final class Config
{
    private const MAP = [
        'CASHFREE_APP_ID'          => ['payments', 'appId'],
        'CASHFREE_SECRET_KEY'      => ['payments', 'secretKey'],
        'CASHFREE_WEBHOOK_SECRET'  => ['payments', 'webhookSecret'],
        'CASHFREE_ENV'             => ['payments', 'env'],
        'CASHFREE_PAYOUT_APP_ID'   => ['payments', 'payoutAppId'],
        'CASHFREE_PAYOUT_SECRET'   => ['payments', 'payoutSecret'],
        'OPENAI_API_KEY'           => ['writing', 'openaiKey'],
        'ANTHROPIC_API_KEY'        => ['writing', 'anthropicKey'],
        'XAI_API_KEY'              => ['writing', 'xaiKey'],
        'OPENAI_MODEL'             => ['writing', 'openaiModel'],
        'ANTHROPIC_MODEL'          => ['writing', 'anthropicModel'],
        'XAI_MODEL'                => ['writing', 'xaiModel'],
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = Env::get($key);
        if ($fromEnv !== null && $fromEnv !== '') {
            return $fromEnv;
        }
        if (!isset(self::MAP[$key])) {
            return $fromEnv ?? $default;
        }
        [$group, $field] = self::MAP[$key];
        try {
            $cfg = Settings::cached($group, []);
            $value = $cfg[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        } catch (\Throwable) {
        }
        return $default;
    }
}
