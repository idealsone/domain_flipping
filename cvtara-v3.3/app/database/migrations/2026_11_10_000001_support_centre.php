<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 3.40.0 Support Centre: ticket reference, priority, assignment and response times on support_reports; a conversation
 * per ticket (customer, staff and internal notes); read-only customer view sessions and every request made in them.
 * Existing owner notes become the first internal note of their ticket. Nothing existing is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $t) {
            if (! Schema::hasColumn('support_reports', 'reference')) {
                $t->string('reference', 12)->nullable();
            }
            if (! Schema::hasColumn('support_reports', 'priority')) {
                $t->string('priority', 10)->default('normal');
            }
            if (! Schema::hasColumn('support_reports', 'channel')) {
                $t->string('channel', 10)->default('web');
            }
            if (! Schema::hasColumn('support_reports', 'assigned_to')) {
                $t->unsignedBigInteger('assigned_to')->nullable();
            }
            if (! Schema::hasColumn('support_reports', 'first_response_at')) {
                $t->timestamp('first_response_at')->nullable();
            }
            if (! Schema::hasColumn('support_reports', 'last_customer_at')) {
                $t->timestamp('last_customer_at')->nullable();
            }
        });
        if (! Schema::hasTable('support_ticket_messages')) {
            Schema::create('support_ticket_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('ticket_id');
                $t->unsignedBigInteger('author_id')->nullable();
                $t->string('author_type', 10); // customer, staff, system
                $t->text('body');
                $t->boolean('internal')->default(false); // staff-only note
                $t->timestamps();
                $t->index(['ticket_id', 'created_at']);
            });
        }
        if (! Schema::hasTable('support_sessions')) {
            Schema::create('support_sessions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('ticket_id')->nullable();
                $t->unsignedBigInteger('staff_id');
                $t->unsignedBigInteger('customer_id');
                $t->string('mode', 12)->default('read_only');
                $t->string('reason', 500);
                $t->timestamp('started_at');
                $t->timestamp('expires_at');
                $t->timestamp('ended_at')->nullable();
                $t->unsignedBigInteger('ended_by')->nullable();
                $t->timestamps();
                $t->index(['staff_id', 'ended_at']);
                $t->index('customer_id');
            });
        }
        if (! Schema::hasTable('support_session_events')) {
            Schema::create('support_session_events', function (Blueprint $t) {
                $t->id();
                $t->uuid('session_id');
                $t->string('method', 8);
                $t->string('path', 190);
                $t->unsignedSmallInteger('status');
                $t->string('ip', 64)->default('');
                $t->timestamp('created_at')->nullable();
                $t->index('session_id');
            });
        }
        // References for existing tickets and their notes as first internal notes.
        foreach (DB::table('support_reports')->whereNull('reference')->get(['id', 'admin_note', 'created_at', 'updated_at']) as $row) {
            DB::table('support_reports')->where('id', $row->id)->update(['reference' => 'S-'.strtoupper(substr(str_replace('-', '', $row->id), 0, 6))]);
            if (trim((string) $row->admin_note) !== '' && ! DB::table('support_ticket_messages')->where('ticket_id', $row->id)->exists()) {
                DB::table('support_ticket_messages')->insert(['id' => (string) Str::uuid(), 'ticket_id' => $row->id, 'author_id' => null, 'author_type' => 'staff', 'body' => (string) $row->admin_note, 'internal' => true, 'created_at' => $row->updated_at ?? $row->created_at, 'updated_at' => $row->updated_at ?? $row->created_at]);
            }
        }
    }

    public function down(): void
    {
        // Kept on purpose: conversations and session logs are records.
    }
};
