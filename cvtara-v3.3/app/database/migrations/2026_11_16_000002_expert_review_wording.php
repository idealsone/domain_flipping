<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3.48.0 (V7 audit, owner decision 2): Tara Expert Review is written automatically, so customer wording must not say a
 * person reviews the resume. Replaces the old tagline only where the owner's saved Pricing still holds it unchanged.
 */
return new class extends Migration
{
    private const OLD = 'The CVTara team reviews your whole resume and sends you a written report';

    private const NEW = 'An instant written review of your whole resume, ready in minutes and emailed to you';

    public function up(): void
    {
        $this->swap(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->swap(self::NEW, self::OLD);
    }

    private function swap(string $from, string $to): void
    {
        $row = DB::table('site_settings')->where('key', 'plans')->first();
        if (! $row) {
            return;
        }
        $plans = json_decode((string) $row->value_json, true);
        if (! is_array($plans) || ($plans['expert-review']['tagline'] ?? null) !== $from) {
            return;
        }
        $plans['expert-review']['tagline'] = $to;
        DB::table('site_settings')->where('key', 'plans')->update(['value_json' => json_encode($plans), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
    }
};
