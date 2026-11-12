<?php

use App\Services\Settings;
use App\Services\WhatsNew;
use Illuminate\Database\Migrations\Migration;

/**
 * 3.63.0. When the owner has saved their own What's new list, the seeded notes are no longer read, so the notes for the
 * 6-12 Nov releases would never show. This adds the new notes the saved list does not have yet (matched by id) and hides
 * the old "Call us" note, because the support phone number was taken off the site on 6 Nov. Nothing is deleted; notes
 * the owner wrote or edited are left as they are. Without a saved list there is nothing to do: the seed already has them.
 */
return new class extends Migration
{
    private const ADD = ['2026-11-12-signup', '2026-11-12-versions', '2026-11-12-original', '2026-11-12-job-check', '2026-11-11-menu', '2026-11-07-dashboard', '2026-11-07-job-hunt', '2026-11-06-plans'];

    private const HIDE = '2026-10-26-phone';

    public function up(): void
    {
        $saved = Settings::get(WhatsNew::KEY, null);
        if (! is_array($saved) || ! isset($saved['entries']) || ! is_array($saved['entries'])) {
            return;
        }
        $entries = $saved['entries'];
        $have = array_column($entries, 'id');
        foreach (WhatsNew::seed() as $note) {
            if (in_array($note['id'], self::ADD, true) && ! in_array($note['id'], $have, true)) {
                $note['publishedAt'] = now()->toIso8601String(); // shown once in the window after the update
                $entries[] = $note;
            }
        }
        foreach ($entries as &$e) {
            if (($e['id'] ?? '') === self::HIDE) {
                $e['published'] = false;
            }
        }
        unset($e);
        $saved['entries'] = array_values($entries);
        Settings::put(WhatsNew::KEY, $saved);
    }

    public function down(): void
    {
        // Notes stay; the owner can hide or delete them in the console.
    }
};
