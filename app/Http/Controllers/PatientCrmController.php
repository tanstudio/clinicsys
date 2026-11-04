<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PatientCrmController
{
    private function branch(): int
    {
        StaffAccess::allow('doctor','reception');
        return StaffAccess::branch();
    }

    private function bookings(int $branch)
    {
        return DB::table('appointments as a')->join('slots as s','s.id','=','a.slot_id')->where('s.branch_id',$branch);
    }

    private function patient(int $branch, int $id): Patient
    {
        return Patient::whereIn('id', $this->bookings($branch)->select('a.patient_id'))->findOrFail($id);
    }

    private function profileData($profile): array
    {
        return ['tags' => $profile ? Crypt::decryptString($profile->tags) : '',
            'segment' => $profile?->segment ? Crypt::decryptString($profile->segment) : ''];
    }

    public function index(Request $request)
    {
        $branch = $this->branch();
        $validator = Validator::make($request->all(), ['q' => 'nullable|string|max:150', 'segment' => 'nullable|string|max:80']);
        abort_if($validator->fails(), 422);
        $term = trim($request->input('q') ?? '');
        $segment = trim($request->input('segment') ?? '');
        $allBranches = $request->input('scope') === 'all';
        abort_if($allBranches && !StaffAccess::hq(), 403);
        $visits = DB::table('appointments as a')->join('slots as s', 's.id', '=', 'a.slot_id')
            ->join('branches as b', 'b.id', '=', 's.branch_id')
            ->when(!$allBranches, fn ($query) => $query->where('s.branch_id', $branch))
            ->groupBy('a.patient_id', 's.branch_id', 'b.name_zh')
            ->select('a.patient_id', 's.branch_id', 'b.name_zh as branch_name')
            ->selectRaw('count(*) as booking_count')->orderByDesc('a.patient_id')->orderBy('s.branch_id')->get();
        $profiles = DB::table('crm_profiles')->when(!$allBranches, fn ($query) => $query->where('branch_id', $branch))
            ->get()->keyBy(fn ($profile) => $profile->branch_id.'-'.$profile->patient_id);
        $contacts = Patient::whereIn('id', $visits->pluck('patient_id')->unique())->get()->keyBy('id');
        $patients = [];
        foreach ($visits as $visit) {
            $patient = $contacts[$visit->patient_id];
            $data = $this->profileData($profiles->get($visit->branch_id.'-'.$visit->patient_id));
            $searchable = implode(' ', [$patient->name,$patient->phone,$data['tags']]);
            if (($term === '' || mb_stripos($searchable, $term) !== false)
                && ($segment === '' || mb_strtolower($data['segment']) === mb_strtolower($segment))) {
                $patients[] = ['patient' => $patient, 'visit' => $visit] + $data;
            }
        }
        return view('clinical.patients', compact('patients','term','segment','allBranches'));
    }

    public function openBranch(Request $request, int $patient)
    {
        abort_unless(StaffAccess::hq(), 403);
        $branch = $request->integer('branch_id');
        $this->patient($branch, $patient);
        $request->session()->put('staff_branch', $branch);
        return redirect('/staff/patients/'.$patient);
    }

    public function show(int $patient)
    {
        $branch = $this->branch();
        $person = $this->patient($branch, $patient);
        $bookings = $this->bookings($branch)->where('a.patient_id',$patient)
            ->join('services as sv','sv.id','=','s.service_id')->join('practitioners as p','p.id','=','s.practitioner_id')
            ->select('a.id','a.reference','a.email','a.cancelled_at','s.starts_at','sv.name_zh as service','p.name_zh as practitioner')
            ->orderByDesc('s.starts_at')->get();
        $events = DB::table('appointment_events as e')
            ->leftJoin('slots as old_slot', function ($join) use ($branch) {
                $join->on('old_slot.id', '=', 'e.from_slot_id')->where('old_slot.branch_id', '=', $branch);
            })
            ->leftJoin('slots as new_slot', function ($join) use ($branch) {
                $join->on('new_slot.id', '=', 'e.to_slot_id')->where('new_slot.branch_id', '=', $branch);
            })
            ->whereIn('e.appointment_id', $bookings->pluck('id'))
            ->where(fn ($query) => $query->whereNotNull('old_slot.id')->orWhereNotNull('new_slot.id'))
            ->orderByDesc('e.id')
            ->get(['e.appointment_id','e.event','e.created_at','old_slot.starts_at as old_start','new_slot.starts_at as new_start']);
        $latestEmail = $bookings->first(fn ($booking) => $booking->email !== null)?->email;
        $email = $latestEmail ? Crypt::decryptString($latestEmail) : null;
        $scope = ['branch_id' => $branch, 'patient_id' => $patient];
        $profile = $this->profileData(DB::table('crm_profiles')->where($scope)->first());
        $followups = DB::table('patient_followups')->where($scope)->orderBy('due_on')->get();
        $threads = DB::table('message_threads')->where($scope)->orderByDesc('updated_at')->get();
        $encounters = StaffAccess::can('doctor') ? DB::table('encounters')->where($scope)->pluck('id','appointment_id') : collect();
        return view('clinical.patient', compact('person','email','bookings','events','profile','followups','threads','encounters'));
    }

    public function profile(Request $request, int $patient)
    {
        $branch = $this->branch();
        $this->patient($branch, $patient);
        $validator = Validator::make($request->all(), ['tags' => 'nullable|string|max:300', 'segment' => 'nullable|string|max:80']);
        abort_if($validator->fails(), 422);
        DB::table('crm_profiles')->upsert([['branch_id' => $branch, 'patient_id' => $patient,
            'tags' => Crypt::encryptString(trim($request->input('tags') ?? '')),
            'segment' => Crypt::encryptString(trim($request->input('segment') ?? ''))]],
            ['branch_id','patient_id'], ['tags','segment']);
        return redirect('/staff/patients/'.$patient);
    }

    public function followup(Request $request, int $patient)
    {
        $branch = $this->branch();
        $this->patient($branch, $patient);
        $validator = Validator::make($request->all(), ['due_on' => 'required|date_format:Y-m-d', 'note' => 'required|string|max:1500']);
        abort_if($validator->fails(), 422);
        DB::table('patient_followups')->insert(['branch_id' => $branch, 'patient_id' => $patient,
            'due_on' => $request->input('due_on'), 'note' => Crypt::encryptString($request->input('note')),
            'created_at' => now(), 'updated_at' => now()]);
        return redirect('/staff/patients/'.$patient);
    }

    public function complete(int $patient, int $id)
    {
        $branch = $this->branch();
        $this->patient($branch, $patient);
        $item = DB::table('patient_followups')->where(['id' => $id, 'patient_id' => $patient, 'branch_id' => $branch]);
        abort_unless($item->exists(), 404);
        $item->whereNull('completed_at')->update(['completed_at' => now(), 'updated_at' => now()]);
        return redirect('/staff/patients/'.$patient);
    }
}
