<?php

namespace App\Http\Controllers;

use App\Messaging\Inbox;
use App\Models\Patient;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class InboxController
{
    private function branch(): int
    {
        StaffAccess::allow('reception');
        return StaffAccess::branch();
    }

    private function agents(int $branch)
    {
        return DB::table('staff_accounts')->where('branch_id',$branch)->where('active',true)->whereIn('role',['admin','reception']);
    }

    private function thread(int $id): object
    {
        $branch = $this->branch();
        return DB::table('message_threads')->when(!StaffAccess::hq(), fn ($q) => $q->where('branch_id',$branch))->where('id',$id)->firstOrFail();
    }

    public function index(Request $request)
    {
        $branch = $this->branch();
        $allBranches = StaffAccess::hq() && $request->query('scope') === 'all';
        $threads = DB::table('message_threads as t')->leftJoin('staff_accounts as staff','staff.id','=','t.assignee_id')
            ->select('t.*','staff.name as agent_name')->when(!$allBranches, fn ($q) => $q->where('t.branch_id',$branch));
        if ($request->query('queue') === 'mine') {
            $threads->where('assignee_id',StaffAccess::id());
        } elseif ($request->query('queue') === 'open') {
            $threads->where('status','open');
        }
        return view('messaging.index', [
            'branches' => DB::table('branches')->pluck('name_zh','id'),
            'threads' => $threads->orderByDesc('t.updated_at')->orderByDesc('t.id')->paginate(30)->withQueryString(),
            'rules' => DB::table('message_rules')->where('branch_id',$branch)->orderBy('id')->get(),
            'agents' => $this->agents($branch)->orderBy('name')->pluck('name','id'),
        ]);
    }

    public function show(int $id)
    {
        $thread = $this->thread($id);
        return view('messaging.thread', [
            'thread' => $thread, 'entries' => DB::table('message_entries')->where('thread_id',$id)->orderByDesc('id')->paginate(50),
            'agents' => $this->agents($thread->branch_id)->orderBy('name')->pluck('name','id'),
        ]);
    }

    public function assign(Request $request, int $id)
    {
        $thread = $this->thread($id);
        $data = $request->validate(['assignee_id'=>'nullable|integer', 'status'=>'required|in:open,closed', 'category'=>'required|string|max:80']);
        $this->checkAgent($thread->branch_id, $data['assignee_id'] ?? null);
        DB::table('message_threads')->where('id', $id)->update($data + ['updated_at'=>now()]);
        return back()->with('status','已更新對話');
    }

    private function checkAgent(int $branch, ?int $id): void
    {
        abort_if($id && !$this->agents($branch)->where('id',$id)->exists(),422,'Invalid branch agent.');
    }

    public function link(Request $request, int $id)
    {
        $thread = $this->thread($id);
        $data = $request->validate(['reference'=>'required|uuid','verified'=>'accepted']);
        $record = DB::table('appointments as a')->join('slots as s','s.id','=','a.slot_id')
            ->where('s.branch_id',$thread->branch_id)->where('a.reference',$data['reference'])->first(['a.patient_id']);
        abort_unless($record && Patient::findOrFail($record->patient_id)->phone === Crypt::decryptString($thread->phone),422,'Contact mismatch.');
        $changed = DB::table('message_threads')->where('id',$id)->whereNull('patient_id')->update(['patient_id'=>$record->patient_id,'updated_at'=>now()]);
        abort_unless($changed,409);
        return back()->with('status','已連結患者');
    }

    public function reply(Request $request, int $id)
    {
        $thread = $this->thread($id);
        $data = $request->validate(['body'=>'required|string|max:2000','request_id'=>'required|uuid']);
        Inbox::enqueue($thread, "staff:$id:".$data['request_id'], 'reply', $data['body']);
        return back()->with('status','回覆已排程');
    }

    public function retry(Request $request, int $id, int $entry)
    {
        $this->thread($id);
        StaffAccess::allow('admin');
        $request->validate(['checked'=>'accepted']);
        $changed = DB::table('message_entries')->where('thread_id',$id)->where('id',$entry)
            ->whereIn('status',['review','unconfigured','failed'])->update(['status'=>'queued','error'=>null,'due_at'=>now(),'updated_at'=>now()]);
        abort_unless($changed,409);
        return back()->with('status','已重新排程');
    }

    public function saveRule(Request $request)
    {
        StaffAccess::allow('admin');
        $branch = $this->branch();
        $data = $request->validate(['keyword'=>'required|string|max:100','category'=>'required|string|max:80',
            'assignee_id'=>'nullable|integer','reply'=>'nullable|string|max:2000']);
        $this->checkAgent($branch, $data['assignee_id'] ?? null);
        DB::table('message_rules')->updateOrInsert(['keyword'=>$data['keyword'],'branch_id'=>$branch], $data);
        return back()->with('status','已儲存分流規則');
    }

    public function deleteRule(int $id)
    {
        StaffAccess::allow('admin');
        DB::table('message_rules')->where('branch_id',$this->branch())->where('id',$id)->delete();
        return back()->with('status','已刪除規則');
    }

    public function campaign(Request $request)
    {
        StaffAccess::allow('admin');
        $branch = $this->branch();
        $data = $request->validate(['body'=>'required|string|max:1000','request_id'=>'required|uuid']);
        $count = DB::transaction(function () use ($branch,$data) {
            $targets = DB::table('message_threads as t')->join('message_consents as c','c.contact_key','=','t.contact_key')
                ->where('t.branch_id',$branch)->where('c.marketing',true)->groupBy('t.contact_key')->selectRaw('MIN(t.id) AS id')->get();
            foreach ($targets as $target) {
                Inbox::enqueue($target, "campaign:$branch:".$data['request_id'].":$target->id",'marketing',$data['body']);
            }
            return $targets->count();
        });
        return back()->with('status',"已為 $count 位已訂閱聯絡人排程");
    }
}
