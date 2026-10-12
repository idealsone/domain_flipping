<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Config;
use App\Support\DB;
use App\Support\Logger;

final class TailorEngineService
{
    public function optimize(int $userId, string $text, string $job = ''): array
    {
        return DB::transaction(function ($pdo) use ($userId, $text, $job) {
            $stmt = $pdo->prepare('SELECT * FROM tailor_credits WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $credits = $stmt->fetch();
            if (!$credits) {
                DB::insert('tailor_credits', [
                    'user_id' => $userId,
                    'total_allowed' => 3,
                    'used_count' => 0,
                    'remaining_balance' => 3,
                ]);
                $stmt->execute([$userId]);
                $credits = $stmt->fetch();
            }
            if ((int) $credits['remaining_balance'] < 1) {
                throw new \RuntimeException('No optimization credits remain. Add a credit bundle at checkout.');
            }

            $result = $this->rewrite($text, $job);
            $used = (int) $credits['used_count'] + 1;
            $remaining = max(0, (int) $credits['remaining_balance'] - 1);
            $upd = $pdo->prepare('UPDATE tailor_credits SET used_count = ?, remaining_balance = ? WHERE user_id = ?');
            $upd->execute([$used, $remaining, $userId]);

            return [
                'text' => $result,
                'used' => $used,
                'remaining' => $remaining,
                'total' => (int) $credits['total_allowed'],
            ];
        });
    }

    public function grant(int $userId, int $extra): void
    {
        $row = DB::fetch('SELECT * FROM tailor_credits WHERE user_id = ?', [$userId]);
        if (!$row) {
            DB::insert('tailor_credits', [
                'user_id' => $userId,
                'total_allowed' => $extra,
                'used_count' => 0,
                'remaining_balance' => $extra,
            ]);
            return;
        }
        DB::update('tailor_credits', [
            'total_allowed' => (int) $row['total_allowed'] + $extra,
            'remaining_balance' => (int) $row['remaining_balance'] + $extra,
        ], 'user_id = ?', [$userId]);
    }

    /**
     * Which AI provider is currently active (for UI display).
     * Returns 'openai', 'anthropic', 'xai', or 'local'.
     */
    public static function activeProvider(): string
    {
        if (Config::get('OPENAI_API_KEY'))    return 'openai';
        if (Config::get('ANTHROPIC_API_KEY')) return 'anthropic';
        if (Config::get('XAI_API_KEY'))       return 'xai';
        return 'local';
    }

    /**
     * Try AI providers in priority order: OpenAI → Claude → xAI → local fallback.
     */
    private function rewrite(string $text, string $job): string
    {
        $providers = [
            'openai'    => Config::get('OPENAI_API_KEY'),
            'anthropic' => Config::get('ANTHROPIC_API_KEY'),
            'xai'       => Config::get('XAI_API_KEY'),
        ];

        foreach ($providers as $name => $key) {
            if (!$key) {
                continue;
            }
            try {
                $result = $this->remoteVia($name, $key, $text, $job);
                Logger::info('AI rewrite completed', ['provider' => $name]);
                return $result;
            } catch (\Throwable $e) {
                Logger::error("AI provider {$name} failed, trying next", ['error' => $e->getMessage()]);
            }
        }

        Logger::info('AI rewrite: all providers unavailable, using local fallback');
        return $this->local($text);
    }

    private function remoteVia(string $provider, string $key, string $text, string $job): string
    {
        $system = 'You rewrite career history bullet points for a resume. Rules: '
            . 'Never invent employers, numbers, tools, titles, dates or outcomes that are not in the source. '
            . 'Only rephrase facts already present. Use precise action verbs (led, built, reduced, increased, delivered). '
            . 'Fix grammar. Remove filler phrases (responsible for, helped with, worked on). '
            . 'Return plain text only — no markdown, no bullet symbols, no headings.';

        $user = "Rewrite this career text:\n\n{$text}";
        if (trim($job) !== '') {
            $user .= "\n\nTarget role language to mirror ONLY if the same fact is already evidenced in the text above:\n{$job}";
        }

        return match ($provider) {
            'openai'    => $this->callOpenAI($key, $system, $user),
            'anthropic' => $this->callClaude($key, $system, $user),
            'xai'       => $this->callXAI($key, $system, $user),
            default     => throw new \RuntimeException("Unknown AI provider: {$provider}"),
        };
    }

    /** OpenAI ChatGPT — gpt-4o-mini by default, override via OPENAI_MODEL */
    private function callOpenAI(string $key, string $system, string $user): string
    {
        $model = Config::get('OPENAI_MODEL', 'gpt-4o-mini');
        $payload = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'max_tokens'  => 1200,
            'temperature' => 0.3,
        ], JSON_UNESCAPED_SLASHES);

        $raw = $this->post('https://api.openai.com/v1/chat/completions', $payload, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);

        $json = json_decode($raw, true);
        $out  = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        if ($out === '') {
            throw new \RuntimeException('OpenAI returned empty content. Error: ' . ($json['error']['message'] ?? 'unknown'));
        }
        return $out;
    }

    /** Anthropic Claude — claude-3-haiku-20240307 by default, override via ANTHROPIC_MODEL */
    private function callClaude(string $key, string $system, string $user): string
    {
        $model = Config::get('ANTHROPIC_MODEL', 'claude-3-haiku-20240307');
        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => 1200,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $user]],
        ], JSON_UNESCAPED_SLASHES);

        $raw = $this->post('https://api.anthropic.com/v1/messages', $payload, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json',
        ]);

        $json = json_decode($raw, true);
        $out  = trim((string) ($json['content'][0]['text'] ?? ''));
        if ($out === '') {
            throw new \RuntimeException('Anthropic returned empty content. Error: ' . ($json['error']['message'] ?? 'unknown'));
        }
        return $out;
    }

    /** xAI Grok — model via XAI_MODEL env var */
    private function callXAI(string $key, string $system, string $user): string
    {
        $model = Config::get('XAI_MODEL', 'grok-3-mini-fast');
        $payload = json_encode([
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'max_tokens'  => 1200,
            'temperature' => 0.3,
        ], JSON_UNESCAPED_SLASHES);

        $raw = $this->post('https://api.x.ai/v1/chat/completions', $payload, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ]);

        $json = json_decode($raw, true);
        $out  = trim((string) ($json['choices'][0]['message']['content'] ?? ''));
        if ($out === '') {
            throw new \RuntimeException('xAI returned empty content. Error: ' . ($json['error']['message'] ?? 'unknown'));
        }
        return $out;
    }

    /** Generic cURL POST helper */
    private function post(string $url, string $payload, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('cURL error: ' . curl_error($ch));
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http >= 500) {
            throw new \RuntimeException("Provider returned HTTP {$http}");
        }
        return (string) $raw;
    }

    private function local(string $text): string
    {
        $map = [
            '/\bhelped\b/i'          => 'supported',
            '/\bworked on\b/i'       => 'delivered',
            '/\bresponsible for\b/i' => 'owned',
            '/\bmade\b/i'            => 'produced',
            '/\bdid\b/i'             => 'completed',
            '/\bassisted\b/i'        => 'supported',
            '/\bhandled\b/i'         => 'managed',
        ];
        $out = trim($text);
        foreach ($map as $from => $to) {
            $out = preg_replace($from, $to, $out) ?? $out;
        }
        $lines = preg_split('/\r\n|\n/', $out) ?: [$out];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line    = preg_replace('/\s+/', ' ', $line) ?? $line;
            $clean[] = rtrim($line, '.') . '.';
        }
        return implode("\n", $clean);
    }
}
