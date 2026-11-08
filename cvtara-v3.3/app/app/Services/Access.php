<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may do what in the owner console (3.37.0). The owner (users.is_admin) is the Super Admin and may do everything.
 * Staff have one role from config/permissions.php. Every /api/admin/... request is checked here before a controller
 * runs; a path that matches no rule is owner only. Switching ROLES_ENFORCE=off in .env falls back to the old rule
 * (owner only for everything), which can never open anything wider.
 */
class Access
{
    private static array $roleCache = [];

    public static function role(?User $u): ?string
    {
        if (! $u || $u->blocked) {
            return null;
        }
        if ($u->is_admin) {
            return 'owner';
        }
        if (! array_key_exists($u->id, self::$roleCache)) {
            $role = null;
            try {
                if (Schema::hasTable('user_roles')) {
                    $role = DB::table('user_roles')->where('user_id', $u->id)->value('role');
                }
            } catch (\Throwable) {
                $role = null;
            }
            self::$roleCache[$u->id] = $role && isset(config('permissions.roles')[$role]) && self::enforced() ? $role : null;
        }

        return self::$roleCache[$u->id];
    }

    public static function forget(int $userId): void
    {
        unset(self::$roleCache[$userId]);
    }

    public static function enforced(): bool
    {
        return strtolower((string) env('ROLES_ENFORCE', 'on')) !== 'off';
    }

    /** Owner or a staff member with a role: may open the console. */
    public static function isStaff(?User $u): bool
    {
        return self::role($u) !== null;
    }

    public static function isOwner(?User $u): bool
    {
        return self::role($u) === 'owner';
    }

    /** Permission keys this person holds ('*' for the owner). */
    public static function permissions(?User $u): array
    {
        $role = self::role($u);
        if ($role === null) {
            return [];
        }
        if ($role === 'owner') {
            return ['*'];
        }

        return config('permissions.roles')[$role]['can'] ?? [];
    }

    /** $perm may be "a|b" (either one). "owner" is the owner only, "staff" any console user. */
    public static function can(?User $u, string $perm): bool
    {
        $role = self::role($u);
        if ($role === null) {
            return false;
        }
        if ($role === 'owner') {
            return true;
        }
        if ($perm === 'staff') {
            return true;
        }
        if ($perm === 'owner') {
            return false;
        }
        $have = self::permissions($u);
        foreach (explode('|', $perm) as $p) {
            if (in_array($p, $have, true)) {
                return true;
            }
        }

        return false;
    }

    /** The permission an admin API path needs. Unknown paths are owner only. */
    public static function forPath(string $method, string $path): string
    {
        $method = strtoupper($method) === 'GET' ? 'GET' : 'POST';
        foreach (config('permissions.routes') as [$m, $re, $perm]) {
            if (($m === '*' || $m === $method) && preg_match($re, $path)) {
                return $perm;
            }
        }

        return 'owner';
    }

    /** Called by the API router for every /api/admin/... request. */
    public static function need(Request $r, string $adminPath): void
    {
        $u = $r->user();
        abort_unless(self::isStaff($u), 403);
        $perm = self::forPath($r->method(), $adminPath);
        abort_unless(self::can($u, $perm), 403, 'Your role cannot use this part of the console.');
        // Staff never change the owner's or another staff member's account, whatever their role allows.
        if (! self::isOwner($u)) {
            foreach (['userId', 'user_id', 'id'] as $k) {
                $target = $r->input($k);
                if ($k !== 'id' || str_starts_with($adminPath, 'users/')) {
                    if (is_numeric($target) && (int) $target !== (int) $u->id && self::isStaff(User::find((int) $target))) {
                        abort(403, 'Only the owner can change a console account.');
                    }
                }
            }
        }
    }

    public static function moduleAllowed(?User $u, string $module): bool
    {
        $need = config('permissions.modules')[$module] ?? 'owner';

        return self::can($u, $need);
    }

    /** Staff list for the owner's Team page. */
    public static function team(): array
    {
        $rows = DB::table('user_roles as r')->join('users as u', 'u.id', '=', 'r.user_id')->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email', 'u.blocked', 'u.totp_enabled_at', 'r.role', 'r.created_at', 'r.updated_at']);
        $owners = DB::table('users')->where('is_admin', true)->orderBy('id')->get(['id', 'name', 'email', 'blocked', 'totp_enabled_at']);
        $roles = config('permissions.roles');

        return [
            'owners' => $owners->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'email' => $o->email, 'role' => 'owner', 'roleName' => 'Owner (Super Admin)', 'authenticator' => (bool) $o->totp_enabled_at])->all(),
            'staff' => $rows->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'email' => $s->email, 'blocked' => (bool) $s->blocked, 'role' => $s->role, 'roleName' => $roles[$s->role]['name'] ?? $s->role, 'authenticator' => (bool) $s->totp_enabled_at, 'since' => (string) $s->created_at])->all(),
            'roles' => collect($roles)->map(fn ($r, $k) => ['key' => $k, 'name' => $r['name'], 'about' => $r['about'], 'can' => array_map(fn ($p) => config('permissions.permissions')[$p] ?? $p, $r['can'])])->values()->all(),
        ];
    }
}
