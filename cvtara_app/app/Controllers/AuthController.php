<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Auth;
use App\Support\Crypto;
use App\Support\DB;
use App\Support\Mailer;
use App\Support\Response;
use App\Support\View;

final class AuthController
{
    public function showLogin(): never
    {
        if (Auth::check()) {
            Response::redirect(Auth::admin() ? '/admin' : '/workspace');
        }
        Response::html(View::render('auth/login', ['title' => 'Sign in'], 'layouts/auth'));
    }

    public function login(): never
    {
        // Brute force protection
        $attempts = $_SESSION['login_attempts'] ?? 0;
        $lastAttempt = $_SESSION['login_last_attempt'] ?? 0;

        // Reset counter after 15 minutes
        if (time() - $lastAttempt > 900) {
            $attempts = 0;
        }

        if ($attempts >= 5) {
            $remainingSeconds = 900 - (time() - $lastAttempt);
            flash('error', 'Too many failed attempts. Please try again in ' . ceil($remainingSeconds / 60) . ' minute(s).');
            Response::redirect('/login');
        }

        if (!Auth::attempt((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
            $_SESSION['login_attempts'] = ($attempts + 1);
            $_SESSION['login_last_attempt'] = time();
            flash('error', 'Those details did not match an account.');
            Response::redirect('/login');
        }

        unset($_SESSION['login_attempts'], $_SESSION['login_last_attempt']);
        Response::redirect(Auth::admin() ? '/admin' : '/workspace');
    }

    public function showRegister(): never
    {
        if (Auth::check()) {
            Response::redirect(Auth::admin() ? '/admin' : '/workspace');
        }
        Response::html(View::render('auth/register', ['title' => 'Create account'], 'layouts/auth'));
    }

    public function register(): never
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $tier = in_array($_POST['experience_tier'] ?? '', ['fresher', 'professional', 'power'], true) ? $_POST['experience_tier'] : 'fresher';
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            flash('error', 'Use a name, valid email and a password of at least 12 characters.');
            Response::redirect('/register');
        }
        if (DB::fetch('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('error', 'An account already exists for that email. Sign in instead.');
            Response::redirect('/login');
        }
        $ref = strtoupper(trim((string) ($_POST['referral'] ?? $_GET['ref'] ?? '')));
        $parent = $ref !== '' ? DB::fetch('SELECT id FROM users WHERE referral_code = ?', [$ref]) : null;
        $id = DB::insert('users', [
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'is_admin' => 0,
            'experience_tier' => $tier,
            'is_premium' => 0,
            'marketing_consent' => !empty($_POST['marketing']) ? 1 : 0,
            'referred_by' => $parent['id'] ?? null,
            'referral_code' => strtoupper(substr(bin2hex(random_bytes(5)), 0, 8)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::insert('tailor_credits', ['user_id' => $id, 'total_allowed' => 3, 'used_count' => 0, 'remaining_balance' => 3]);
        $user = DB::fetch('SELECT * FROM users WHERE id = ?', [$id]);
        Auth::login($user);
        DB::insert('events', ['user_id' => $id, 'event' => 'signup', 'path' => '/register', 'created_at' => now()]);
        if ($parent) {
            DB::insert('events', ['user_id' => (int) $parent['id'], 'event' => 'referral', 'path' => '/register', 'created_at' => now()]);
        }
        Mailer::send($email, 'Your CVTara account', Mailer::wrap('Account created', '<p>Hi ' . e($name) . ',</p><p>Your workspace is ready. Complete a career profile, then run a compliance scan on a real job description.</p>'), Mailer::transactional());
        Response::redirect('/workspace');
    }

    public function logout(): never
    {
        Auth::logout();
        Response::redirect('/');
    }

    public function showForgot(): never
    {
        Response::html(View::render('auth/forgot', ['title' => 'Reset password'], 'layouts/auth'));
    }

    public function forgot(): never
    {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $user = filter_var($email, FILTER_VALIDATE_EMAIL) ? DB::fetch('SELECT * FROM users WHERE email = ?', [$email]) : null;
        if ($user) {
            $token = Crypto::token();
            DB::query('REPLACE INTO password_resets (email, token, created_at) VALUES (?,?,?)', [$email, hash('sha256', $token), now()]);
            Mailer::send($email, 'Reset your CVTara password', Mailer::wrap('Password reset', '<p>This link is valid for 60 minutes.</p><p><a href="' . e(url('/reset/' . $token)) . '">Choose a new password</a></p>'), Mailer::transactional());
        }
        flash('success', 'If that email exists, a reset link is on its way.');
        Response::redirect('/forgot');
    }

    public function showReset(string $token): never
    {
        Response::html(View::render('auth/reset', ['title' => 'New password', 'token' => $token], 'layouts/auth'));
    }

    public function reset(string $token): never
    {
        $row = DB::fetch('SELECT * FROM password_resets WHERE token = ?', [hash('sha256', $token)]);
        if (!$row || strtotime((string) $row['created_at']) < strtotime('-60 minutes')) {
            flash('error', 'That reset link has expired.');
            Response::redirect('/forgot');
        }
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 12) {
            flash('error', 'Use at least 12 characters.');
            Response::redirect('/reset/' . $token);
        }
        DB::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => now()], 'email = ?', [$row['email']]);
        DB::query('DELETE FROM password_resets WHERE email = ?', [$row['email']]);
        flash('success', 'Password updated. Sign in.');
        Response::redirect('/login');
    }
}
