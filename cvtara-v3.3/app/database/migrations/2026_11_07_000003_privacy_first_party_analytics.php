<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3.35.1: the analytics consent box and Google Analytics are gone; visits are counted on CVTara's own server.
 * A saved privacy policy still holding the old consent sentences gets them replaced. Any other owner wording is left alone.
 */
return new class extends Migration
{
    private const SWAP = [
        ' Optional analytics run only if you accept analytics cookies.' => ' We count page visits on our own servers, without tracking cookies or third-party analytics tools.',
        ' Optional analytics cookies load only after you accept them.' => ' We do not use analytics or advertising cookies.',
        ' If you accept analytics, Google Analytics may set its own identifiers.' => '',
    ];

    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'legal')->first();
        $v = $row ? json_decode((string) $row->value_json, true) : null;
        if (! is_array($v) || ! is_string($v['privacy'] ?? null)) {
            return;
        }
        $new = strtr($v['privacy'], self::SWAP);
        if ($new === $v['privacy']) {
            return;
        }
        $v['privacy'] = $new;
        \App\Services\Settings::put('legal', $v);
    }

    public function down(): void {}
};
