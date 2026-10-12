<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Crypto;
use App\Support\DB;

final class LeadImportService
{
    public const ALLOWED_SOURCES = [
        'opt-in' => 'Website opt-in form',
        'csv-import' => 'Owner CSV of consented contacts',
        'event' => 'Event or webinar sign-up',
        'partner' => 'Licensed partner list',
        'linkedin-export' => 'Your own LinkedIn export of people who already consented',
    ];

    public function add(string $email, ?string $name, string $source, string $note = 'Owner-confirmed consent'): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Skip invalid email: ' . $email);
        }
        if (!isset(self::ALLOWED_SOURCES[$source])) {
            $source = 'csv-import';
        }
        $existing = DB::fetch('SELECT id FROM leads WHERE email = ? AND source = ?', [$email, $source]);
        if ($existing) {
            return ['id' => (int) $existing['id'], 'created' => false];
        }
        $id = DB::insert('leads', [
            'email' => $email,
            'name' => $name,
            'source' => $source,
            'status' => 'new',
            'campaign_step' => 1,
            'last_sent_at' => null,
            'stop_marketing' => 0,
            'consent_at' => now(),
            'consent_note' => $note,
            'unsubscribe_token' => Crypto::token(),
            'created_at' => now(),
        ]);
        return ['id' => $id, 'created' => true];
    }

    public function importCsv(string $csv, string $source, string $note): array
    {
        $lines = preg_split('/\r\n|\n/', trim($csv)) ?: [];
        $created = 0;
        $skipped = 0;
        $header = null;
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            if ($i === 0 && isset($cols[0]) && !str_contains((string) $cols[0], '@')) {
                $header = array_map(fn ($c) => strtolower(trim((string) $c)), $cols);
                continue;
            }
            $email = $header ? ($cols[array_search('email', $header, true)] ?? '') : ($cols[0] ?? '');
            $name = $header && ($idx = array_search('name', $header, true)) !== false ? ($cols[$idx] ?? '') : ($cols[1] ?? '');
            try {
                $result = $this->add((string) $email, (string) $name ?: null, $source, $note);
                $result['created'] ? $created++ : $skipped++;
            } catch (\Throwable) {
                $skipped++;
            }
        }
        return compact('created', 'skipped');
    }
}
