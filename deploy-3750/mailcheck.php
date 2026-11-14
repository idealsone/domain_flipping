<?php
// 3.75.0 deploy check: mail settings without secrets, then one read of the support mailbox.
$base = '/home/u280039231/domains/cvtara.com/cvtara_app';
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->usePublicPath('/home/u280039231/domains/cvtara.com/public_html');
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p = App\Services\Settings::providers()['email'] ?? [];
$i = App\Services\Settings::get('integrations') ?? [];
echo 'mail: provider='.($p['provider'] ?? '?').' host='.($p['smtpHost'] ?? '').' port='.($p['smtpPort'] ?? '').' user='.($p['smtpUser'] ?? '').' from='.($p['fromEmail'] ?? '').' replyTo='.($p['replyTo'] ?? '').' hasPassword='.(empty($p['smtpPassword']) ? 'no' : 'yes')."\n";
echo 'integrations: email='.json_encode($i['email'] ?? null).' ai='.json_encode($i['ai'] ?? null).' aiReady='.json_encode(App\Services\TaraAI::enabled())."\n";
echo 'support_reply sent last 30d: '.Illuminate\Support\Facades\DB::table('mail_outbox')->where('trigger', 'support_reply')->where('created_at', '>', now()->subDays(30))->groupBy('status')->selectRaw('status, count(*) c')->pluck('c', 'status')->toJson()."\n";
echo 'open tickets before: '.Illuminate\Support\Facades\DB::table('support_reports')->whereIn('status', ['new', 'seen', 'working'])->count()."\n";
echo 'inbox run: '.json_encode(App\Services\SupportInbox::run(true))."\n";
$s = App\Services\SupportInbox::status(); unset($s['host']);
echo 'inbox status: '.json_encode($s)."\n";
foreach (Illuminate\Support\Facades\DB::table('support_reports')->where('channel', 'email')->orderByDesc('created_at')->limit(15)->get(['reference', 'email', 'triage', 'reply_needed', 'status', 'draft_at']) as $t) {
    echo '  '.$t->reference.' '.$t->email.' '.$t->triage.' reply='.json_encode($t->reply_needed).' '.$t->status.' draft='.($t->draft_at ? 'yes' : 'no')."\n";
}
