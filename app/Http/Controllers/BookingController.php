<?php

namespace App\Http\Controllers;

use App\Actions\CancelAppointment;
use App\Actions\CreateAppointment;
use App\Actions\RescheduleAppointment;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookingController
{
    private function browserKey(Request $request): string
    {
        $key = $request->session()->get('booking_owner') ?? hash_hmac('sha256', $request->session()->getId(), config('app.key'));
        $request->session()->put('booking_owner', $key);
        return $key;
    }

    private function owned(Request $request, string $reference): Appointment
    {
        return Appointment::whereBrowserKey($this->browserKey($request))->whereReference($reference)->firstOrFail();
    }

    public function availability()
    {
        $clock = now('Asia/Hong_Kong');
        $slots = DB::table('slots')->where('starts_at', '>', $clock)->whereNull('closed_at')
            ->where('starts_at', '<', $clock->copy()->startOfDay()->addDays(14))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments')
                ->whereColumn('appointments.slot_id', 'slots.id')->whereNull('appointments.cancelled_at'))
            ->orderBy('starts_at')->orderBy('id')->get();
        return response()->json([
            'slots' => $slots,
            'branches' => DB::table('branches')->orderBy('id')->get(),
            'services' => DB::table('services')->orderBy('id')->get(),
            'practitioners' => DB::table('practitioners')->orderBy('id')->get(),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, CreateAppointment $create)
    {
        $validator = validator($request->all(), CreateAppointment::RULES);
        if ($validator->fails()) {
            return response()->json(['error' => 'invalid_details', 'fields' => $validator->errors()->keys()], 422);
        }
        $appointment = $create->book($validator->validated(), $this->browserKey($request));
        return $appointment ? response()->json(['reference' => $appointment->reference], 201)
            : response()->json(['error' => 'slot_unavailable'], 409);
    }

    public function manage(Request $request)
    {
        $bookings = Appointment::whereBrowserKey($this->browserKey($request))
            ->join('slots', 'slots.id', '=', 'appointments.slot_id')
            ->leftJoin('encounters', 'encounters.appointment_id', '=', 'appointments.id')
            ->join('branches', 'branches.id', '=', 'slots.branch_id')
            ->join('services', 'services.id', '=', 'slots.service_id')
            ->join('practitioners', 'practitioners.id', '=', 'slots.practitioner_id')
            ->orderByDesc('slots.starts_at')->get(['appointments.reference','appointments.cancelled_at','encounters.id as encounter_id','slots.id as slot_id','slots.starts_at',
                'branches.name_zh as branch_zh','branches.name_en as branch_en','services.name_zh as service_zh','services.name_en as service_en',
                'practitioners.name_zh as practitioner_zh','practitioners.name_en as practitioner_en']);
        return response()->view('booking-manage', ['bookings' => $bookings, 'en' => $request->query('lang') === 'en'])
            ->header('Cache-Control', 'no-store');
    }

    public function edit(Request $request, string $reference)
    {
        $appointment = $this->owned($request, $reference);
        $originalTime = DB::table('slots')->where('id', $appointment->slot_id)->value('starts_at');
        abort_if($appointment->cancelled_at || $appointment->hasEncounter()
            || $originalTime <= now('Asia/Hong_Kong')->toDateTimeString(), 409);
        return response()->view('booking', compact('appointment', 'originalTime'))->header('Cache-Control', 'no-store');
    }

    public function move(Request $request, string $reference, RescheduleAppointment $move)
    {
        $appointment = $this->owned($request, $reference);
        $data = $request->validate(['expected_slot' => 'required|integer', 'slot_id' => 'required|integer']);
        return $move->move($appointment, $data['expected_slot'], $data['slot_id'])
            ? response()->json(['reference' => $reference])
            : response()->json(['error' => 'booking_changed_or_unavailable'], 409);
    }

    public function cancel(Request $request, string $reference, CancelAppointment $cancel)
    {
        $appointment = $this->owned($request, $reference);
        $data = $request->validate(['expected_slot' => 'required|integer']);
        $ok = $cancel->cancel($appointment, $data['expected_slot']);
        if ($request->expectsJson()) {
            return response()->json(['cancelled' => $ok], $ok ? 200 : 409);
        }
        return redirect('/appointments/manage?lang='.($request->input('lang') === 'en' ? 'en' : 'zh'))
            ->with('booking_status', $ok ? 'cancelled' : 'conflict');
    }
}
