<?php

namespace App\Http\Controllers;

use App\Actions\CancelAppointment;
use App\Actions\CreateAppointment;
use App\Actions\RescheduleAppointment;
use App\Models\Appointment;
use App\Models\Slot;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffBookingController
{
    private function bookings()
    {
        return DB::table('appointments')->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->where('slots.branch_id', StaffAccess::branch())
            ->select('appointments.id','appointments.reference','appointments.patient_id','appointments.slot_id',
                'appointments.cancelled_at','appointments.updated_at','slots.branch_id','slots.service_id',
                'slots.practitioner_id','slots.starts_at');
    }

    public function api(Request $request)
    {
        StaffAccess::allow('reception', 'doctor');
        $filter = $request->validate(['patient_id' => 'nullable|integer', 'reference' => 'nullable|uuid',
            'after_id' => 'nullable|integer|min:0']);
        $bookings = $this->bookings();
        foreach (['reference','patient_id'] as $field) {
            if (isset($filter[$field])) {
                $bookings->where('appointments.'.$field, $filter[$field]);
            }
        }
        $rows = $bookings->where('appointments.id', '>', $filter['after_id'] ?? 0)
            ->orderBy('appointments.id')->limit(101)->get();
        $page = $rows->take(100);
        return response()->json(['appointments' => $page->values(), 'next_after_id' => $rows->count() > 100 ? $page->last()->id : null])
            ->header('Cache-Control', 'no-store');
    }

    public function index(Request $request)
    {
        StaffAccess::allow('reception');
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? today('Asia/Hong_Kong')->toDateString();
        $branch = StaffAccess::branch();
        $slots = DB::table('slots')->where('slots.branch_id', $branch)->whereDate('slots.starts_at', $date)
            ->leftJoin('appointments', fn ($join) => $join->on('appointments.slot_id', '=', 'slots.id')->whereNull('appointments.cancelled_at'))
            ->leftJoin('encounters', 'encounters.appointment_id', '=', 'appointments.id')
            ->join('practitioners','practitioners.id','=','slots.practitioner_id')->join('services','services.id','=','slots.service_id')
            ->orderBy('slots.starts_at')->orderBy('slots.id')
            ->get(['slots.*','practitioners.name_zh as practitioner','services.name_zh as service','appointments.reference','appointments.patient_id','encounters.id as encounter_id']);
        $practitioners = DB::table('practitioners')->orderBy('name_zh')->get();
        $services = DB::table('services')->orderBy('name_zh')->get();
        $now = now('Asia/Hong_Kong');
        $available = DB::table('slots')->where('slots.branch_id', $branch)->whereNull('closed_at')
            ->where('starts_at','>', $now)->where('starts_at','<', $now->copy()->startOfDay()->addDays(14))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments')->whereColumn('appointments.slot_id','slots.id')->whereNull('cancelled_at'))
            ->join('practitioners','slots.practitioner_id','=','practitioners.id')->join('services','slots.service_id','=','services.id')
            ->orderBy('starts_at')->get(['slots.id','slots.starts_at','practitioners.name_zh as practitioner','services.name_zh as service']);
        return response()->view('staff.schedule', compact('date','slots','practitioners','services','available','now'))
            ->header('Cache-Control', 'no-store');
    }

    public function addSlot(Request $request)
    {
        StaffAccess::allow('reception');
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'time' => ['required','regex:/^(?:[01]\d|2[0-3]):(?:00|30)$/D'],
            'practitioner_id' => 'required|integer|exists:practitioners,id','service_id' => 'required|integer|exists:services,id']);
        $starts = $data['date'].' '.$data['time'].':00';
        abort_if($starts <= now('Asia/Hong_Kong')->toDateTimeString(), 422, '請選擇未來的時段。');
        try {
            DB::table('slots')->insert(['branch_id' => StaffAccess::branch(), 'practitioner_id' => $data['practitioner_id'],
                'service_id' => $data['service_id'], 'starts_at' => $starts]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return back()->withErrors(['time' => '該醫師在此時間已有排班。']);
        }
        return redirect('/staff/schedule?date='.$data['date'])->with('status', '已開放時段。');
    }

    public function availability(Request $request, int $id)
    {
        StaffAccess::allow('reception');
        $data = $request->validate(['state' => 'required|in:open,closed']);
        DB::transaction(function () use ($id, $data) {
            $slot = Slot::whereBranchId(StaffAccess::branch())->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($slot->starts_at <= now('Asia/Hong_Kong')->toDateTimeString(), 409, '此時段已開始。');
            abort_if(Appointment::whereSlotId($id)->whereNull('cancelled_at')->lockForUpdate()->first(), 409, '此時段已有預約。');
            DB::table('slots')->where('id',$id)->update(['closed_at' => $data['state'] === 'closed' ? now() : null]);
        }, 3);
        return back()->with('status', '已更新時段狀態。');
    }

    public function book(Request $request, CreateAppointment $create)
    {
        StaffAccess::allow('reception');
        $validator = validator($request->all(), CreateAppointment::RULES);
        if ($validator->fails()) {
            return back()->withErrors($validator);
        }
        $appointment = $create->book($validator->validated(), null, StaffAccess::branch());
        return $appointment ? back()->with('status', '預約已確認。'.$appointment->reference)
            : back()->withErrors(['slot_id' => '此時段未能預約。']);
    }

    private function appointment(string $reference): Appointment
    {
        $row = $this->bookings()->where('appointments.reference', $reference)->firstOrFail();
        return Appointment::findOrFail($row->id);
    }

    public function move(Request $request, string $reference, RescheduleAppointment $action)
    {
        StaffAccess::allow('reception');
        $data = $request->validate(['expected_slot' => 'required|integer','slot_id' => 'required|integer']);
        $ok = $action->move($this->appointment($reference), $data['expected_slot'], $data['slot_id'], StaffAccess::branch());
        return $ok ? back()->with('status', '已更改預約時段。') : back()->withErrors(['slot_id' => '預約已更改或新時段未能預約。']);
    }

    public function cancel(Request $request, string $reference, CancelAppointment $action)
    {
        StaffAccess::allow('reception');
        $data = $request->validate(['expected_slot' => 'required|integer']);
        $ok = $action->cancel($this->appointment($reference), $data['expected_slot'], StaffAccess::branch());
        return $ok ? back()->with('status','預約已取消。') : back()->withErrors(['slot_id' => '預約已更改或診症已開始。']);
    }
}
