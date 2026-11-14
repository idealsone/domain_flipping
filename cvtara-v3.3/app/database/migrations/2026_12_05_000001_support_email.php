<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3.75.0 Support by email: mail sent to support@cvtara.com becomes a ticket (or joins its ticket when it is a reply), each
 * new mail is sorted (customer who needs a reply, sales pitch, automatic report, spam), a suggested reply can be kept on
 * the ticket, and every reply email carries the headers that thread it in the customer's mail app. Nothing is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_reports', function (Blueprint $t) {
            foreach ([
                'triage' => fn () => $t->string('triage', 12)->nullable(),            // customer, sales, automated, spam
                'reply_needed' => fn () => $t->boolean('reply_needed')->nullable(),
                'triage_reason' => fn () => $t->string('triage_reason', 300)->nullable(),
                'email_subject' => fn () => $t->string('email_subject', 255)->nullable(),
                'email_message_id' => fn () => $t->string('email_message_id', 255)->nullable(),
                'draft' => fn () => $t->text('draft')->nullable(),
                'draft_at' => fn () => $t->timestamp('draft_at')->nullable(),
            ] as $col => $add) {
                if (! Schema::hasColumn('support_reports', $col)) {
                    $add();
                }
            }
        });
        Schema::table('support_ticket_messages', function (Blueprint $t) {
            if (! Schema::hasColumn('support_ticket_messages', 'mail_id')) {
                $t->uuid('mail_id')->nullable();
            }
            if (! Schema::hasColumn('support_ticket_messages', 'email_message_id')) {
                $t->string('email_message_id', 255)->nullable()->index();
            }
        });
        Schema::table('mail_outbox', function (Blueprint $t) {
            if (! Schema::hasColumn('mail_outbox', 'thread_headers')) {
                $t->text('thread_headers')->nullable();
            }
        });
        if (! Schema::hasTable('support_inbox_seen')) {
            // One row per mail read from the support mailbox, so a mail is never turned into a ticket twice.
            Schema::create('support_inbox_seen', function (Blueprint $t) {
                $t->string('id', 64)->primary(); // sha256 of the Message-ID (or of the raw mail when it has none)
                $t->uuid('ticket_id')->nullable();
                $t->string('outcome', 20);       // ticket, reply, skipped
                $t->string('sender', 190)->default('');
                $t->string('subject', 255)->default('');
                $t->timestamp('created_at')->nullable();
                $t->index('created_at');
            });
        }
    }

    public function down(): void
    {
        // Kept on purpose: tickets and their mail history are records.
    }
};
