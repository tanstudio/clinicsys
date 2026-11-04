<?php

namespace App\Http\Controllers;

use App\Support\BranchDirectory;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectoryController
{
    public const TITLES = ['practitioners'=>'醫師管理','services'=>'服務項目管理','accounts'=>'職員帳號管理'];

    public function index(string $type)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::currentBranch();
        $entries = collect();
        $available = collect();
        if ($branch !== null && $type === 'accounts') {
            $entries = DB::table('staff_accounts')->where('branch_id',$branch)->orderBy('name')
                ->get(['id','name','email','role','active']);
        } elseif ($branch !== null) {
            $key = BranchDirectory::KEYS[$type];
            $entries = BranchDirectory::entries($type,$branch)->addSelect([
                'branch_count'=>DB::table('branch_'.$type)->selectRaw('count(*)')->whereColumn($key,$type.'.id'),
            ])->orderBy('name_zh')->get();
            if (StaffAccess::hq()) {
                $available = DB::table($type)->whereNotIn('id',$entries->pluck('id'))->orderBy('name_zh')->get();
            }
        }
        return view('staff.directory', compact('type','branch','entries','available') + ['title'=>self::TITLES[$type]]);
    }

    public function save(Request $request, string $type, ?int $id = null)
    {
        StaffAccess::allow('admin');
        $branch = StaffAccess::branch();
        abort_unless((int)$request->input('expected_branch') === $branch,409,'分店已切換，請重新載入。');
        $key = BranchDirectory::KEYS[$type];
        DB::transaction(function () use ($request,$branch,$type,$key,$id) {
            DB::table('branches')->where('id',$branch)->lockForUpdate()->firstOrFail();
            $members = DB::table('branch_'.$type)->where('branch_id',$branch);
            if ($id === null && $request->filled('existing_id')) {
                abort_unless(StaffAccess::hq(),403);
                $record = DB::table($type)->where('id',$request->integer('existing_id'))->lockForUpdate()->firstOrFail();
                DB::table('branch_'.$type)->insertOrIgnore(['branch_id'=>$branch,$key=>$record->id]);
                return;
            }
            if ($id !== null) {
                (clone $members)->where($key,$id)->lockForUpdate()->firstOrFail();
                DB::table($type)->where('id',$id)->lockForUpdate()->firstOrFail();
                if ($request->has('active')) {
                    $request->validate(['active'=>'required|boolean']);
                    if (!$request->boolean('active') && DB::table('slots')->where('branch_id',$branch)->where($key,$id)
                        ->where('starts_at','>',now('Asia/Hong_Kong'))->whereNull('closed_at')->exists()) {
                        throw ValidationException::withMessages(['active'=>'請先處理未來預約並關閉相關時段，再停用。']);
                    }
                    $members->where($key,$id)->update(['active'=>$request->boolean('active')]);
                    return;
                }
                abort_if(!StaffAccess::hq() && DB::table('branch_'.$type)->where($key,$id)->count()>1,403);
            }
            $names = $request->validate(['name_zh'=>'required|string|max:255','name_en'=>'required|string|max:255']);
            $duplicate = BranchDirectory::entries($type,$branch)->where('name_zh',$names['name_zh']);
            if ($id !== null) $duplicate->where($type.'.id','!=',$id);
            if ($duplicate->exists()) throw ValidationException::withMessages(['name_zh'=>'本分店已有相同中文名稱。']);
            if ($id !== null) {
                DB::table($type)->where('id',$id)->update($names);
            } else {
                $newId = DB::table($type)->insertGetId($names);
                DB::table('branch_'.$type)->insert(['branch_id'=>$branch,$key=>$newId]);
            }
        },3);
        return redirect('/staff/'.$type)->with('status','已儲存分店資料。');
    }
}
