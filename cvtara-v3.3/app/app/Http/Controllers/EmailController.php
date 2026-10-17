<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\EmailVerification;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailController extends Controller
{
    /** Signed link in every marketing email. GET asks to confirm, POST (also the one-click mail-client request) switches marketing mail off. */
    public function unsubscribe(Request $r, int $user)
    {
        abort_unless($r->hasValidSignature(), 403);
        $u = User::findOrFail($user);
        if ($r->isMethod('POST')) {
            DB::table('profiles')->where('owner_id', $u->id)->update(['marketing_consent' => false, 'updated_at' => now()]);
            Settings::audit($u->id, 'unsubscribed');

            return response($this->shell('You are unsubscribed', 'We will not send you tips or plan reminders any more. You will still get account emails such as receipts, sign-in codes and reminders you asked for. You can turn tips back on any time in Account settings.', null), 200, ['X-Robots-Tag' => 'noindex']);
        }

        return response($this->shell('Unsubscribe from CVTara tips?', 'This stops career tips and plan reminders to '.e($this->mask($u->email)).'. Account emails such as receipts and sign-in codes will continue.', $r->fullUrl()), 200, ['X-Robots-Tag' => 'noindex']);
    }

    private function mask(string $e): string
    {
        [$a, $b] = explode('@', $e, 2) + ['', ''];

        return substr($a, 0, 2).str_repeat('•', max(1, strlen($a) - 2)).'@'.$b;
    }

    private function shell(string $h, string $p, ?string $postTo): string
    {
        $btn = $postTo ? '<form method="post" action="'.e($postTo).'"><button style="padding:13px 22px;border:0;border-radius:11px;background:#1557d2;color:#fff;font:700 15px Inter,Arial,sans-serif;cursor:pointer">Yes, unsubscribe me</button></form><p style="margin-top:18px"><a href="/" style="color:#1557d2">No, take me to CVTara</a></p>' : '<p><a href="/" style="color:#1557d2;font-weight:700">Go to CVTara</a></p>';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>'.e($h).' | CVTara</title></head><body style="margin:0;background:#f1f5f9;font-family:Inter,Segoe UI,Arial,sans-serif;color:#0f172a"><main style="max-width:520px;margin:12vh auto;padding:34px;background:#fff;border-radius:18px;border:1px solid #e2e8f0;box-shadow:0 20px 50px rgba(15,23,42,.08)"><h1 style="margin:0 0 12px;font-size:26px;letter-spacing:-.02em">'.e($h).'</h1><p style="line-height:1.65;color:#475569">'.$p.'</p>'.$btn.'</main></body></html>';
    }

    public function verifyPage(Request $r)
    {
        $u = $r->user();
        if (! EmailVerification::required($u)) {
            return redirect($this->after($r));
        }

        return response()->view('auth', ['mode' => 'verify', 'token' => null, 'email' => $u->email, 'action' => '/verify-email'])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function verifySubmit(Request $r)
    {
        $u = $r->user();
        $r->validate(['code' => 'required|string|max:12']);
        if (! EmailVerification::required($u)) {
            return redirect($this->after($r));
        }
        $err = EmailVerification::check($u, preg_replace('/\D/', '', (string) $r->input('code')));

        return $err ? back()->withErrors(['code' => $err]) : redirect($this->after($r));
    }

    public function verifyResend(Request $r)
    {
        $u = $r->user();
        if (! EmailVerification::required($u)) {
            return redirect($this->after($r));
        }
        $err = EmailVerification::send($u);

        return $err ? back()->withErrors(['code' => $err]) : back()->with('status', 'A new code is on its way. It can take a minute to arrive. Check spam if you do not see it.');
    }

    private function after(Request $r): string
    {
        $n = (string) $r->input('next', $r->query('next', ''));

        return ($n !== '' && $n[0] === '/' && ! str_starts_with($n, '//') && ! str_contains($n, '\\') && ! str_starts_with($n, '/admin')) ? $n : '/workspace.html';
    }
}
