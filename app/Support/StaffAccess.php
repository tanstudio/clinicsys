<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class StaffAccess
{
    public const ROLES = ['admin', 'reception', 'doctor', 'hr'];

    public static function account(): ?object
    {
        $request = request();
        if (!$request->hasSession() || !$id = $request->session()->get('staff_id')) {
            return null;
        }
        $cached = $request->attributes->get('clinic_staff');
        if ($cached && (int)$cached->id === (int)$id) {
            return $cached;
        }
        $account = DB::table('staff_accounts')->where(['id' => $id, 'active' => true])->first();
        $request->attributes->set('clinic_staff', $account);
        return $account;
    }

    public static function id(): int
    {
        return (int)(self::account()->id ?? abort(403));
    }

    public static function role(): string
    {
        return self::account()->role ?? abort(403);
    }

    public static function hq(): bool
    {
        $account = self::account();
        return $account && $account->branch_id === null && $account->role === 'admin';
    }

    public static function currentBranch(): ?int
    {
        $account = self::account();
        abort_unless($account, 403);
        if (!self::hq()) {
            abort_unless($account->branch_id !== null, 403);
            return (int)$account->branch_id;
        }
        $id = session('staff_branch') ?: DB::table('branches')->orderBy('id')->value('id');
        return $id ? (int)$id : null;
    }

    public static function branch(): int
    {
        return self::currentBranch() ?? abort(409, 'No branch configured.');
    }

    public static function can(string ...$roles): bool
    {
        return self::role() === 'admin' || in_array(self::role(), $roles, true);
    }

    public static function allow(string ...$roles): void
    {
        abort_unless(self::can(...$roles), 403);
    }
}
