<?php

namespace App\Http\Controllers;

use App\Support\StaffAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffAccountController
{
    public function save(Request $request, ?int $id = null)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::branch();
        abort_unless($request->integer('expected_branch') === $branch,409,'分店已切換，請重新載入。');
        $roles = StaffAccess::hq() ? StaffAccess::ROLES : ['doctor','reception','hr'];
        try {
            DB::transaction(function () use ($request,$id,$roles,$branch) {
                $accounts = DB::table('staff_accounts')->where('branch_id',$branch);
                if ($id !== null) {
                    $account = (clone $accounts)->where('id',$id)->lockForUpdate()->firstOrFail();
                    abort_unless(in_array($account->role,$roles,true) && $account->id !== StaffAccess::id(),403);
                }
                $fields = $request->validate([
                    'name'=>'required|string|max:255','email'=>'required|email|max:255','role'=>['required',Rule::in($roles)],
                    'active'=>'required|boolean','password'=>[$id===null?'required':'nullable','string','min:12','max:72'],
                ]);
                $fields['email'] = strtolower($fields['email']);
                $password = $fields['password'] ?? null;
                unset($fields['password']);
                if ($password !== null) {
                    if (strlen($password)>72) throw ValidationException::withMessages(['password'=>'密碼最多 72 位元組。']);
                    $fields['password_hash'] = Hash::make($password);
                }
                $fields['updated_at'] = now();
                if ($id === null) {
                    DB::table('staff_accounts')->insert($fields+['created_at'=>now(),'branch_id'=>$branch]);
                } else {
                    if ($password !== null || $fields['role'] !== $account->role || (bool)$fields['active'] !== (bool)$account->active || $fields['email'] !== $account->email)
                        $fields['session_version'] = $account->session_version + 1;
                    $accounts->where('id',$id)->update($fields);
                }
            },3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email'=>'此工作電郵未能使用。']);
        }
        return redirect('/staff/accounts')->with('status','已儲存職員帳號。');
    }
}
