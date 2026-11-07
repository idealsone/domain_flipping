<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.37.0: staff roles (the owner stays Super Admin through users.is_admin), an append-only audit log whose rows are
 * chained by hash so a silent edit or delete shows up, and authenticator-app codes for console sign-in.
 * Additive only: nothing existing is changed or removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->primary();
                $t->string('role', 40);
                $t->unsignedBigInteger('granted_by')->nullable();
                $t->timestamps();
                $t->index('role');
            });
        }
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->timestamp('at')->index();
                $t->unsignedBigInteger('actor_id')->nullable()->index();
                $t->string('actor_role', 40)->default('');
                $t->string('action', 120);
                $t->string('target', 190)->default('');
                $t->longText('before_json')->nullable();
                $t->longText('after_json')->nullable();
                $t->string('reason', 500)->default('');
                $t->string('ip', 64)->default('');
                $t->string('request_id', 20)->default('');
                $t->unsignedSmallInteger('status')->default(0);
                $t->string('prev_hash', 64)->default('');
                $t->string('hash', 64)->default('');
                $t->index(['action', 'at']);
            });
        }
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'totp_secret')) {
                $t->text('totp_secret')->nullable();          // encrypted with the app key
                $t->timestamp('totp_enabled_at')->nullable();
                $t->unsignedBigInteger('totp_last_step')->nullable(); // a code is accepted only once
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('audit_logs');
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['totp_secret', 'totp_enabled_at', 'totp_last_step']);
        });
    }
};
