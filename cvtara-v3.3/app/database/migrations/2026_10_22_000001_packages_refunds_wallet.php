<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** New packages (Quick Download, Job Hunt, Pro), the 7-day refund for unused purchases, and the "Give ₹50, get ₹50" wallet. */
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $t) {
            $t->unsignedBigInteger('wallet_paise')->default(0);
            $t->unsignedBigInteger('referral_discount_paise')->default(0);
            $t->unsignedBigInteger('upgrade_credit_paise')->default(0);
            $t->unsignedBigInteger('addon_discount_paise')->default(0);
            $t->uuid('upgrade_from')->nullable()->index();
        });
        // A random first-party browser id, hashed: lets the referral check spot a friend account opened from the referrer's own browser.
        Schema::table('users', function (Blueprint $t) {
            $t->string('device_hash', 64)->nullable()->index();
            $t->string('last_device_hash', 64)->nullable()->index();
        });
        Schema::create('refund_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users');
            $t->uuid('purchase_id')->unique();
            $t->string('status', 20)->default('requested'); // requested | approved | processed | rejected
            $t->unsignedBigInteger('amount_paise');
            $t->string('reason', 1000)->nullable();
            $t->string('note', 1000)->nullable();
            $t->string('provider_refund_id', 120)->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('owner_id')->constrained('users');
            $t->string('kind', 20); // referral | spend | return | adjust
            $t->bigInteger('amount_paise');
            $t->string('reference', 190)->unique();
            $t->timestamp('earned_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->index(['owner_id', 'created_at']);
        });
        Schema::create('service_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users');
            $t->uuid('purchase_id');
            $t->string('service', 30);
            $t->string('status', 20)->default('not_started'); // not_started | in_review | delivered | cancelled
            $t->string('note', 2000)->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamps();
            $t->unique(['purchase_id', 'service']);
            $t->index(['status', 'created_at']);
        });
        // Saved copies of the old "final and non-refundable" policy text are replaced by the new 7-day refund wording.
        $defaults = json_decode(file_get_contents(resource_path('data/defaults.json')), true);
        $row = DB::table('site_settings')->where('key', 'legal')->first();
        if ($row && ($legal = json_decode((string) $row->value_json, true))) {
            foreach (['refund', 'terms', 'about'] as $k) {
                if (isset($legal[$k]) && is_string($legal[$k]) && stripos($legal[$k], 'non-refundable') !== false) {
                    $legal[$k] = $defaults['legal'][$k];
                }
            }
            DB::table('site_settings')->where('key', 'legal')->update(['value_json' => json_encode($legal), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
        $row = DB::table('site_settings')->where('key', 'emails')->first();
        if ($row && is_array($emails = json_decode((string) $row->value_json, true))) {
            $fresh = collect($defaults['emails'])->keyBy('id');
            foreach ($emails as &$e) {
                if (($e['trigger'] ?? '') === 'purchase_verified' && stripos((string) ($e['body'] ?? ''), 'non-refundable') !== false && $fresh->has($e['id'] ?? '')) {
                    $e['body'] = $fresh[$e['id']]['body'];
                }
            }
            unset($e);
            DB::table('site_settings')->where('key', 'emails')->update(['value_json' => json_encode($emails), 'revision' => DB::raw('revision + 1'), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('refund_requests');
        Schema::table('users', function (Blueprint $t) {
            $t->dropIndex(['device_hash']);
            $t->dropIndex(['last_device_hash']);
            $t->dropColumn(['device_hash', 'last_device_hash']);
        });
        Schema::table('purchases', function (Blueprint $t) {
            $t->dropIndex(['upgrade_from']);
            $t->dropColumn(['wallet_paise', 'referral_discount_paise', 'upgrade_credit_paise', 'addon_discount_paise', 'upgrade_from']);
        });
    }
};
