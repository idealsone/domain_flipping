<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Contact details from the owner's testing sheet (6 Nov 2026): the IndiQube Park office address, Monday–Friday support
     * hours, the old phone number taken off, and the official social links. Only values still equal to the old defaults
     * change; anything the owner typed in the console is kept.
     */
    public function up(): void
    {
        $row = DB::table('site_settings')->where('key', 'legal')->first();
        if (! $row || ! is_array($legal = json_decode((string) $row->value_json, true))) {
            return;
        }
        $address = 'IndiQube Park, Domlur 1st Stage, Bengaluru, Karnataka 560071';
        $before = $legal;
        $swap = ['address' => ['CVTara, Bangalore, Karnataka, India', $address], 'hours' => ['Monday–Saturday, 10:00–18:00 IST', 'Monday–Friday, 10:00 AM–6:00 PM IST'], 'phone' => ['+91 82178 23192', '']];
        foreach ($swap as $k => [$old, $new]) {
            if (trim((string) ($legal[$k] ?? '')) === $old) {
                $legal[$k] = $new;
            }
        }
        foreach (['privacy', 'terms', 'about'] as $k) {
            if (is_string($legal[$k] ?? null)) {
                $legal[$k] = str_replace('Monday to Saturday, 10:00 to 18:00 IST.', 'Monday to Friday, 10:00 AM to 6:00 PM IST.', $legal[$k]);
                $legal[$k] = str_replace(['(CVTara, Bangalore, Karnataka, India)', 'CVTara, Bangalore, Karnataka, India. Email'], ['(CVTara, '.$address.')', 'CVTara, '.$address.'. Email'], $legal[$k]);
            }
        }
        if ($legal !== $before) {
            DB::table('site_settings')->where('key', 'legal')->update(['value_json' => json_encode($legal), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void {}
};
