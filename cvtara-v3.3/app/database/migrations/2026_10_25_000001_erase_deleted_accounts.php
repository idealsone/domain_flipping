<?php

use App\Services\AccountEraser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Accounts deleted before 3.21 were only anonymised ("Deleted user"). Remove them for good, as deleting now does. */
    public function up(): void
    {
        foreach (DB::table('users')->where('email', 'like', 'deleted-%@deleted.invalid')->where('email', '!=', AccountEraser::PLACEHOLDER)->pluck('id') as $id) {
            AccountEraser::erase((int) $id);
        }
    }

    public function down(): void {}
};
