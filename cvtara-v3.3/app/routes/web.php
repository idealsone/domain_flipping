<?php
use Illuminate\Support\Facades\{Route,DB,Storage};
use Illuminate\Http\Request;
use App\Http\Controllers\{ApiController,AuthController,ContentController,ExportController,InstallController};
use App\Models\User;
Route::get('/login',fn(Request $r)=>app(AuthController::class)->page($r))->name('login');
foreach(['register','forgot-password'] as $mode){Route::get('/'.$mode.($mode==='reset-password'?'/ {token}':''),fn(Request $r)=>app(AuthController::class)->page($r,$mode));}
Route::get('/reset-password/{token}',fn(Request $r)=>app(AuthController::class)->page($r,'reset-password'));
Route::post('/login',[AuthController::class,'login'])->middleware('throttle:20,1,login');
Route::post('/register',[AuthController::class,'register'])->middleware('throttle:5,1,auth-change');
Route::post('/forgot-password',[AuthController::class,'forgot'])->middleware('throttle:3,1,password-email');
Route::post('/reset-password',[AuthController::class,'reset'])->middleware('throttle:5,1,auth-change');
Route::post('/logout',[AuthController::class,'logout']);
Route::get('/signin-with-chatgpt',fn()=>redirect('/login'));
Route::get('/r/{code}',function(Request $r,string $code){$u=User::where('referral_code',$code)->where('blocked',false)->firstOrFail();if(!$r->user()){$r->session()->put('referral',['code'=>$code,'at'=>time()]);DB::table('referral_clicks')->insertOrIgnore(['referrer_id'=>$u->id,'visitor_hash'=>hash_hmac('sha256',$r->session()->getId(),config('app.key')),'day'=>today(),'created_at'=>now(),'updated_at'=>now()]);}$resp=redirect('/register')->header('X-Robots-Tag','noindex');if(!$r->user())$resp->withCookie(cookie('cvtara_ref',$code,43200,'/',null,$r->isSecure(),true,false,'Lax'));return $resp;})->middleware('throttle:60,1,referral');
Route::post('/webhooks/razorpay',function(Request $r){abort_if(strlen($r->getContent())>1000000,413);\App\Services\Payments::webhook($r->getContent(),$r->header('X-Razorpay-Signature',''),$r->header('X-Razorpay-Event-Id',''));return response()->json(['ok'=>true]);})->middleware('throttle:120,1,api');
Route::match(['GET','POST','DELETE'],'/api/{path?}',[ApiController::class,'run'])->where('path','.*')->middleware('throttle:'.env('API_RATE_LIMIT',400).',1,api');
Route::post('/export/{type}/{id}/{format}',[ExportController::class,'download'])->middleware(['auth','throttle:10,1,exports']);
Route::get('/media/{file}',function(string $file){abort_unless(preg_match('/^[a-f0-9-]{36}\.webp$/',$file)&&Storage::disk('local')->exists('media/'.$file),404);return response(Storage::disk('local')->get('media/'.$file))->header('Content-Type','image/webp')->header('Cache-Control','public,max-age=86400');});
Route::get('/preview',function(){abort_unless(config('app.preview'),404);$demo=\Illuminate\Support\Facades\DB::table('users')->where('email','demo@cvtara.test')->exists()?['customer'=>'demo@cvtara.test','owner'=>'owner@cvtara.test','password'=>config('app.demo_password')]:null;return response()->view('preview',['demo'=>$demo])->header('X-Robots-Tag','noindex');});
Route::get('/{path?}',[ContentController::class,'show'])->where('path','.*');

Route::post('/webhooks/cashfree',function(Request $r){abort_if(strlen($r->getContent())>1000000,413);\App\Services\CashfreeCheckout::webhook($r->getContent(),$r->header('x-webhook-timestamp',''),$r->header('x-webhook-signature',''));return response()->json(['ok'=>true]);})->middleware('throttle:120,1,api');
