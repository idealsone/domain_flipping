<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner-console lists are sorted and filtered on these columns one page at a time; indexes keep that quick as data grows. */
return new class extends Migration
{
    private const INDEXES = [
        'users' => [['created_at'], 'users_created_list'],
        'purchases' => [['created_at'], 'purchases_created_list'],
        'feedback' => [['status', 'created_at'], 'feedback_status_created_list'],
        'mail_outbox' => [['created_at'], 'mail_outbox_created_list'],
        'withdrawals' => [['created_at'], 'withdrawals_created_list'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => [$cols, $name]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($cols, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => [, $name]) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
            }
        }
    }
};
