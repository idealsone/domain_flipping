<?php
// Read-only: Error Log rows and laravel.log entries since the QA run started (argument: UTC ISO time).
require __DIR__.'/qa_boot.php';
use Illuminate\Support\Facades\DB;
$since=$argv[1]??date('Y-m-d H:i:s',time()-6*3600); $since=str_replace('T',' ',substr($since,0,19));
$cols=Illuminate\Support\Facades\Schema::getColumnListing('error_logs');
$rows=DB::table('error_logs')->where(in_array('last_seen',$cols)?'last_seen':'created_at','>=',$since)->orderBy('id')->limit(400)->get();
$unres=DB::table('error_logs')->when(in_array('resolved_at',$cols),fn($q)=>$q->whereNull('resolved_at'))->when(in_array('resolved',$cols),fn($q)=>$q->where('resolved',false))->count();
echo "QAJSON ".json_encode(['since'=>$since,'columns'=>$cols,'unresolvedTotal'=>$unres,'rows'=>$rows],JSON_PARTIAL_OUTPUT_ON_ERROR)." QAEND\n";
$f=$APP.'/storage/logs/laravel.log'; echo "== laravel.log ".(is_file($f)?filesize($f):'missing')." bytes\n";
if(is_file($f)){ $fh=fopen($f,'r'); $sz=filesize($f); if($sz>4000000) fseek($fh,$sz-4000000); $keep=false;$n=0;
  while(($l=fgets($fh))!==false){ if(preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)/',$l,$m)) {$keep=$m[1]>=$since; $lines=0;} if($keep&&$n<1500){ if(preg_match('/^\[/',$l)||(++$lines<=12&&!str_contains($l,'/vendor/'))){echo mb_substr(rtrim($l),0,400)."\n";$n++;} } } }
foreach([$APP.'/error_log',$APP.'/../public_html/error_log'] as $e) if(is_file($e)&&filemtime($e)>=strtotime($since.' UTC')){ echo "== $e\n"; foreach(array_slice(file($e),-30) as $x) echo mb_substr(rtrim($x),0,300)."\n"; }
echo "== mail to QA addresses since: ".json_encode(DB::table('mail_outbox')->where('created_at','>=',$since)->where('recipient','like',QA_PREFIX.'%')->selectRaw('`trigger`, status, count(*) n')->groupBy('trigger','status')->get())."\n";
echo "== mail to NON-QA addresses since (should be only owner notices): ".json_encode(DB::table('mail_outbox')->where('created_at','>=',$since)->where('recipient','not like',QA_PREFIX.'%')->selectRaw('`trigger`, count(*) n')->groupBy('trigger')->get())."\n";
$pp=App\Services\Settings::plans(); $pro=$pp['pro']??null;
echo "== live pro plan: ".json_encode($pro?array_intersect_key((array)$pro,array_flip(['price','savedResumes','savedLetters','atsScans','mockInterviews','perks'])):null)."\n";
$qp=DB::table('partners')->where('name','QA TEST Partner (delete me)')->value('id');
echo "== QA partner $qp members: ".json_encode($qp?DB::table('partner_members as m')->join('users as u','u.id','=','m.user_id')->where('m.partner_id',$qp)->get(['u.email','m.role']):[])."\n";
echo "== QA grants: ".json_encode(DB::table('purchases as p')->join('users as u','u.id','=','p.owner_id')->where('u.email','like',QA_PREFIX.'%')->get(['u.email','p.plan','p.status','p.provider','p.amount_paise']))."\n";
echo "== done ".date('c')."\n";
