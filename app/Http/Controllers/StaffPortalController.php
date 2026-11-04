<?php

namespace App\Http\Controllers;

use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffPortalController
{
    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required','email'], 'password' => ['required','string','max:1024']]);
        $account = DB::table('staff_accounts')->where('email', strtolower($credentials['email']))->where('active', true)->first();
        if (!$account || !Hash::check($credentials['password'], $account->password_hash)) {
            return back()->withErrors(['email' => '電郵地址或密碼不正確。'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $request->session()->put('staff_id', $account->id);
        $request->session()->forget('staff_branch');
        return redirect('/staff');
    }

    public function selectBranch(Request $request)
    {
        abort_unless(StaffAccess::hq(), 403);
        $data = $request->validate(['branch_id' => ['required','integer','exists:branches,id']]);
        $request->session()->put('staff_branch', (int)$data['branch_id']);
        return redirect('/staff');
    }

    public function logout(Request $request)
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/staff/login');
    }
}
