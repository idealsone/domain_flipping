<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\DB;
use App\Support\Mailer;

final class OutreachService
{
    public const INTERVALS = [1 => 0, 2 => 7, 3 => 21, 4 => 45, 5 => 90];

    public function process(int $limit = 40): int
    {
        $sent = 0;
        $leads = DB::fetchAll(
            'SELECT * FROM leads WHERE stop_marketing = 0 AND consent_at IS NOT NULL AND campaign_step BETWEEN 1 AND 5 ORDER BY last_sent_at IS NULL DESC, id ASC LIMIT ' . $limit
        );
        foreach ($leads as $lead) {
            $step = (int) $lead['campaign_step'];
            $wait = self::INTERVALS[$step] ?? 90;
            if ($lead['last_sent_at'] && strtotime((string) $lead['last_sent_at']) > strtotime("-{$wait} days") && $step > 1) {
                continue;
            }
            $copy = $this->copy($lead, $step);
            $unsub = url('/unsubscribe/' . $lead['unsubscribe_token']);
            $pixel = url('/o/' . $lead['unsubscribe_token']);
            $html = Mailer::wrap(
                $copy['subject'],
                '<p>Hi ' . e($lead['name'] ?: 'there') . ',</p>' . $copy['body']
                    . '<p><a href="' . e(url('/register')) . '">Create your CVTara workspace</a></p>'
                    . '<img src="' . e($pixel) . '" width="1" height="1" alt="">',
                'You are receiving this because you opted in or were imported with recorded consent. <a href="' . e($unsub) . '">Unsubscribe</a>.'
            );
            $ok = Mailer::send($lead['email'], $copy['subject'], $html, Mailer::nextMarketing());
            DB::update('leads', [
                'last_sent_at' => now(),
                'campaign_step' => $ok ? min(6, $step + 1) : $step,
                'status' => $ok ? 'nurture' : 'error',
            ], 'id = ?', [$lead['id']]);
            DB::insert('email_jobs', [
                'recipient' => $lead['email'],
                'trigger_name' => 'campaign_step_' . $step,
                'subject' => $copy['subject'],
                'body' => $html,
                'status' => $ok ? 'sent' : 'failed',
                'due_at' => now(),
                'sent_at' => $ok ? now() : null,
                'error' => $ok ? null : 'Provider rejected the message',
                'mailbox_id' => null,
                'created_at' => now(),
            ]);
            if ($ok) {
                $sent++;
            }
        }
        return $sent;
    }

    private function copy(array $lead, int $step): array
    {
        $name = $lead['name'] ?: 'there';
        return match ($step) {
            1 => ['subject' => 'Your CVTara workspace is ready', 'body' => '<p>Save a structured resume, compare it with a real job description, and keep every claim tied to evidence you already have.</p>'],
            2 => ['subject' => 'A clearer application in one sitting', 'body' => '<p>Most people stall on wording. The workspace keeps your facts, then shows the missing job language you can honestly add.</p>'],
            3 => ['subject' => 'Still deciding? Start with a free compliance scan', 'body' => '<p>Paste a job description and your current resume. You will see a 0–100 overlap score and the keyword groups that are missing.</p>'],
            4 => ['subject' => 'Keep your search organised', 'body' => '<p>The application tracker stores company, role, document version and the next follow-up in one place.</p>'],
            default => ['subject' => 'Last note from CVTara', 'body' => '<p>Hi ' . e($name) . ', this is the last scheduled note. You can unsubscribe any time or create a workspace if you still want the tools.</p>'],
        };
    }
}
