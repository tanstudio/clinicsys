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
        $request->session()->put(['staff_id'=>$account->id,'staff_version'=>$account->session_version]);
        $request->session()->forget('staff_branch');
        return redirect('/staff');
    }

    public function branches()
    {
        abort_unless(StaffAccess::hq(), 403);
        return view('staff.branches', ['branches' => DB::table('branches')->orderBy('id')->get()]);
    }

    public function createBranch(Request $request)
    {
        abort_unless(StaffAccess::hq(), 403);
        $names = $request->validate([
            'name_zh' => ['required', 'string', 'max:255', 'unique:branches,name_zh'],
            'name_en' => ['required', 'string', 'max:255'],
        ], ['name_zh.unique' => '分店中文名稱已存在。']);
        try {
            $id = DB::table('branches')->insertGetId($names);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(['name_zh' => '分店中文名稱已存在。']);
        }
        $request->session()->put('staff_branch', $id);
        return redirect('/staff/branches')->with('status', '已新增分店並切換至'.$names['name_zh'].'。');
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
