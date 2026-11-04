<?php

namespace App\Http\Controllers;

use App\Actions\HrAttendance;
use App\Support\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class HrController
{
    private function accounts()
    {
        $accounts = DB::table('staff_accounts')->select('id', 'name', 'email', 'branch_id', 'role');
        if (!StaffAccess::can('hr', 'admin')) {
            return $accounts->where('id', StaffAccess::id());
        }
        return $accounts->where(function ($query) {
            $query->where('branch_id', StaffAccess::branch());
            if (StaffAccess::hq()) {
                $query->orWhereNull('branch_id');
            }
        });
    }

    private function person(int $staff)
    {
        return $this->accounts()->where('id', $staff)->firstOrFail();
    }

    private function done(int $staff)
    {
        return redirect("/staff/hr/people/{$staff}")->with('hr_status', '已儲存。');
    }

    public function index()
    {
        return view('hr.index', ['people' => $this->accounts()->orderBy('name')->get()]);
    }

    public function show(int $staff)
    {
        $person = $this->person($staff);
        $table = fn ($name) => DB::table($name)->where('staff_id', $staff);
        $details = $table('hr_profiles')->value('details');
        $shifts = $table('hr_shifts')->orderByDesc('starts_at')->paginate(20, ['*'], 'shift_page')->withQueryString();
        foreach ($shifts as $shift) {
            $shift->attendance = HrAttendance::summary($shift);
        }
        return response()->view('hr.show', [
            'person' => $person, 'profile' => $details ? decrypt($details) : [],
            'management' => StaffAccess::can('hr', 'admin'), 'own' => StaffAccess::id() === $staff,
            'history' => $table('hr_history')->orderByDesc('effective_on')->orderByDesc('id')->paginate(15, ['*'], 'history_page')->withQueryString(),
            'documents' => $table('hr_documents')->orderByDesc('id')->get(['id','kind','filename','uploaded_at']),
            'shifts' => $shifts, 'leave' => $table('hr_leave')->orderByDesc('id')->paginate(15, ['*'], 'leave_page')->withQueryString(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function profile(Request $request, int $staff)
    {
        $this->person($staff);
        $fields = $request->validate([
            'identity' => 'nullable|string|max:64', 'phone' => 'nullable|string|max:64',
            'address' => 'nullable|string|max:300', 'emergency_name' => 'nullable|string|max:100',
            'emergency_phone' => 'nullable|string|max:64',
        ]);
        DB::table('hr_profiles')->upsert([['staff_id' => $staff, 'details' => encrypt($fields)]], ['staff_id'], ['details']);
        return $this->done($staff);
    }

    public function history(Request $request, int $staff)
    {
        StaffAccess::allow('hr', 'admin');
        $this->person($staff);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['job','pay','training','performance'])],
            'effective_on' => 'required|date_format:Y-m-d', 'details' => 'required|string|max:3000',
        ]);
        DB::table('hr_history')->insert([...$data, 'staff_id' => $staff, 'author_id' => StaffAccess::id(), 'details' => Crypt::encryptString($data['details'])]);
        return $this->done($staff);
    }

    public function upload(Request $request, int $staff)
    {
        StaffAccess::allow('hr', 'admin');
        $this->person($staff);
        $data = $request->validate(['kind' => ['required', Rule::in(['contract','tax'])], 'attachment' => 'required|file|mimes:pdf,png,jpg,jpeg|max:2048']);
        $file = $data['attachment'];
        DB::table('hr_documents')->insert([
            'staff_id' => $staff, 'kind' => $data['kind'], 'filename' => Crypt::encryptString($file->getClientOriginalName()),
            'contents' => Crypt::encryptString($file->get()), 'uploaded_at' => now(),
        ]);
        return $this->done($staff);
    }

    public function download(int $staff, int $document)
    {
        $this->person($staff);
        $row = DB::table('hr_documents')->where('staff_id', $staff)->where('id', $document)->firstOrFail();
        $name = preg_replace('/[\x00-\x1f\x7f\\\\\/]/u', '_', Crypt::decryptString($row->filename));
        return response(Crypt::decryptString($row->contents))->withHeaders([
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => "attachment; filename=\"document\"; filename*=UTF-8''".rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function shift(Request $request, int $staff)
    {
        StaffAccess::allow('hr', 'admin');
        $this->person($staff);
        $data = $request->validate([
            'kind' => 'required|in:fixed,flex', 'starts_at' => 'required|date_format:Y-m-d\TH:i',
            'latest_start' => 'required_if:kind,flex|nullable|date_format:Y-m-d\TH:i|after_or_equal:starts_at',
            'required_minutes' => 'required|integer|min:1|max:1440',
        ]);
        $start = CarbonImmutable::parse($data['starts_at'], 'Asia/Hong_Kong');
        $latest = $data['kind'] === 'flex' ? CarbonImmutable::parse($data['latest_start'], 'Asia/Hong_Kong') : $start;
        $end = $latest->addMinutes($data['required_minutes']);
        DB::transaction(function () use ($staff, $data, $start, $latest, $end) {
            DB::table('staff_accounts')->where('id', $staff)->lockForUpdate()->firstOrFail();
            $previous = DB::table('hr_shifts')->where('staff_id', $staff)->where('starts_at', '<', $end)
                ->where('latest_start', '>', $start->subDay())->get();
            foreach ($previous as $shift) {
                if (CarbonImmutable::parse($shift->latest_start, 'Asia/Hong_Kong')->addMinutes($shift->required_minutes)->gt($start)) {
                    throw ValidationException::withMessages(['starts_at' => '此更次與已有更次重疊。']);
                }
            }
            DB::table('hr_shifts')->insert([
                'staff_id' => $staff, 'kind' => $data['kind'], 'starts_at' => $start,
                'latest_start' => $latest, 'required_minutes' => $data['required_minutes'],
            ]);
        });
        return $this->done($staff);
    }

    public function clock(Request $request, int $staff, int $shift)
    {
        $this->person($staff);
        abort_unless(StaffAccess::id() === $staff, 403);
        $data = $request->validate(['action' => 'required|in:in,out']);
        DB::transaction(function () use ($staff, $shift, $data) {
            DB::table('staff_accounts')->where('id', $staff)->lockForUpdate()->firstOrFail();
            $row = DB::table('hr_shifts')->where('id', $shift)->where('staff_id', $staff)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now('Asia/Hong_Kong');
            if ($data['action'] === 'in') {
                $start = CarbonImmutable::parse($row->starts_at, 'Asia/Hong_Kong');
                $last = CarbonImmutable::parse($row->latest_start, 'Asia/Hong_Kong')->addMinutes($row->required_minutes);
                abort_if($row->clock_in || $now->lt($start->subHours(2)) || $now->gte($last), 409, '未在此更次的上班打卡時段。');
                abort_if(DB::table('hr_shifts')->where('staff_id', $staff)->whereNotNull('clock_in')->whereNull('clock_out')->exists(), 409, '請先完成上一更次的下班打卡。');
            } else {
                abort_if(!$row->clock_in || $row->clock_out, 409, '無法重複或提前下班打卡。');
            }
            DB::table('hr_shifts')->where('id', $shift)->update(['clock_'.$data['action'] => $now]);
        });
        return $this->done($staff);
    }

    public function leave(Request $request, int $staff)
    {
        $this->person($staff);
        abort_unless(StaffAccess::id() === $staff, 403);
        $data = $request->validate([
            'kind' => 'required|in:annual,sick,maternity', 'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on', 'reason' => 'required|string|max:1500',
        ]);
        DB::transaction(function () use ($data, $staff) {
            DB::table('staff_accounts')->where('id', $staff)->lockForUpdate()->firstOrFail();
            if (DB::table('hr_leave')->where('staff_id', $staff)->whereIn('status', ['pending','approved'])
                ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => '已有申請涵蓋此日期。']);
            }
            DB::table('hr_leave')->insert([...$data, 'staff_id' => $staff, 'reason' => Crypt::encryptString($data['reason'])]);
        });
        return $this->done($staff);
    }

    public function review(Request $request, int $staff, int $leave)
    {
        StaffAccess::allow('hr', 'admin');
        $this->person($staff);
        abort_if(StaffAccess::id() === $staff, 403);
        $data = $request->validate(['decision' => 'required|in:approved,rejected']);
        $query = DB::table('hr_leave')->where('staff_id', $staff)->where('id', $leave);
        abort_unless($query->exists(), 404);
        abort_unless($query->where('status', 'pending')->update([
            'status' => $data['decision'], 'reviewer_id' => StaffAccess::id(), 'reviewed_at' => now(),
        ]), 409);
        return $this->done($staff);
    }
}
