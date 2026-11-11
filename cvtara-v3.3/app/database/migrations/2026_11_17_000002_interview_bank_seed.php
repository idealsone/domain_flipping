<?php

use App\Services\Interviews;
use Illuminate\Database\Migrations\Migration;

/**
 * 3.49.0: loads the starter interview question bank (resources/data/interview-bank.json) as DRAFTS. Nothing reaches
 * customers until the owner approves questions in the console and switches interview practice on. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Interviews::seed();
    }

    public function down(): void
    {
        // Seeded rows stay; the tables themselves are removed by 2026_11_17_000001 if that is rolled back.
    }
};
