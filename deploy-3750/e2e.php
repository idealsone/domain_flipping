<?php
// 3.75.0 end-to-end check: read the support mailbox, find the owner's test email, reply to it as the owner (once).
$base = '/home/u280039231/domains/cvtara.com/cvtara_app';
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->usePublicPath('/home/u280039231/domains/cvtara.com/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$s = App\Services\SupportInbox::status();
echo 'inbox before: lastRun='.$s['lastRun'].' lastError='.$s['lastError'].' day='.json_encode($s['day'])."\n";
echo 'run: '.json_encode(App\Services\SupportInbox::run(true))."\n";
$t = DB::table('support_reports')->where('channel', 'email')->where('email_subject', 'like', 'Test: support reply check%')->orderByDesc('created_at')->first();
if (! $t) { echo "test ticket NOT FOUND\n"; exit; }
echo "ticket $t->reference from $t->email triage=$t->triage reply=".json_encode($t->reply_needed)." status=$t->status owner_id=".json_encode($t->owner_id)."\n";
echo "reason: $t->triage_reason\n";
echo "draft:\n".($t->draft ?: '(none)')."\n---\n";
$sent = DB::table('support_ticket_messages')->where('ticket_id', $t->id)->where('author_type', 'staff')->count();
if ($sent) { echo "already replied ($sent)\n"; } else {
    $owner = App\Models\User::where('is_admin', true)->orderBy('id')->first();
    $body = $t->draft ?: "Thank you for your message. This is a test reply from the CVTara support console.";
    $r = App\Services\SupportCentre::staffReply($t, $owner, $body);
    echo 'reply: '.json_encode($r)."\n";
}
foreach (DB::table('mail_outbox')->where('trigger', 'support_reply')->orderByDesc('created_at')->limit(3)->get(['recipient', 'subject', 'status', 'error', 'thread_headers', 'sent_at']) as $o) echo '  outbox '.json_encode($o)."\n";
foreach (App\Services\SupportCentre::messages($t->id, true) as $m) echo '  msg '.$m['from'].': '.mb_strimwidth(str_replace("\n", ' ', $m['body']), 0, 160, '…').(isset($m['mail']) ? ' ['.$m['mail']['status'].']' : '')."\n";
