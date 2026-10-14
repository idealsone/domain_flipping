<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
class AppServiceProvider extends ServiceProvider {
  public function register(): void {}
  public function boot(): void {
    if (!env('CVTARA_FAKE_HTTP')) return;
    $state = env('CVTARA_FAKE_STATE', sys_get_temp_dir().'/fake_state.json');
    Http::fake(function ($request) use ($state) {
      $url = $request->url(); $s = is_file($state) ? json_decode(file_get_contents($state), true) : [];
      $log = fopen(dirname($state).'/fake_calls.log','a'); fwrite($log, $request->method().' '.$url.' '.substr($request->body(),0,300)."\n"); fclose($log);
      if (str_contains($url,'api.razorpay.com/v1/orders')) { $b=json_decode($request->body(),true); return Http::response(['id'=>'order_'.bin2hex(random_bytes(6)),'amount'=>$b['amount']],200); }
      if (preg_match('#api.razorpay.com/v1/payments/(.+)$#',$url,$m)) { $e=$s['razorpay_payments'][urldecode($m[1])]??null; return $e?Http::response($e,200):Http::response(['error'=>'nf'],404); }
      if (str_contains($url,'verify.twilio.com') && str_ends_with($url,'/Verifications')) return Http::response(['status'=>'pending'],201);
      if (str_contains($url,'verify.twilio.com') && str_ends_with($url,'/VerificationCheck')) { parse_str($request->body(),$q); return Http::response(['status'=>($q['Code']??'')==='123456'?'approved':'pending'],200); }
      if (str_contains($url,'api.openai.com')) return Http::response(['output'=>[['content'=>[['type'=>'output_text','text'=>'Improved text']]]]],200);
      if (str_contains($url,'api.resend.com')) return Http::response(['id'=>'x'],200);
      if (str_contains($url,'cashfree.com/pg/orders') && $request->method()==='POST') { $b=json_decode($request->body(),true); return Http::response(['order_id'=>$b['order_id'],'payment_session_id'=>'sess_1'],200); }
      if (preg_match('#cashfree.com/pg/orders/([^/]+)/payments#',$url,$m)) return Http::response($s['cf_payments'][$m[1]]??[],200);
      if (preg_match('#cashfree.com/pg/orders/([^/]+)/refunds#',$url,$m)) return Http::response($s['cf_refunds'][$m[1]]??[],200);
      if (preg_match('#cashfree.com/pg/orders/([^/]+)$#',$url,$m)) return Http::response($s['cf_orders'][$m[1]]??['error'=>'nf'],$s['cf_orders'][$m[1]]??false?200:404);
      if (str_contains($url,'cashfree.com/payout/transfers') && $request->method()==='POST') return Http::response(['status'=>'RECEIVED'],200);
      if (str_contains($url,'cashfree.com/payout/transfers')) { return Http::response($s['cf_transfer']??[],200); }
      return Http::response(['error'=>'unmocked '.$url],500);
    });
  }
}
