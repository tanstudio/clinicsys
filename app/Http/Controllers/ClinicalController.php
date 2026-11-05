<?php

namespace App\Http\Controllers;

use App\Models\ClinicalTemplate;
use App\Models\Encounter;
use App\Models\Patient;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicalController
{
    public const SYMPTOMS = ['疼痛', '疲倦', '失眠', '咳嗽', '胃腸不適', '頭暈', '其他'];
    public const FIELDS = ['symptoms' => '症狀分類', 'notes' => '症狀描述', 'diagnosis' => '辨證及診斷', 'prescription' => '處方', 'plan' => '治療計劃'];

    private function branch(): int
    {
        StaffAccess::allow('doctor');
        return StaffAccess::branch();
    }

    private function booking(int $branch, int $appointment)
    {
        return DB::table('appointments as a')->join('slots as s', 's.id', '=', 'a.slot_id')
            ->where('a.id', $appointment)->where('s.branch_id', $branch)->whereNull('a.cancelled_at')
            ->select('a.id', 'a.reference', 'a.patient_id', 's.starts_at');
    }

    public function index(Request $request)
    {
        $branch = $this->branch();
        $query = $request->input('q', '');
        abort_unless(is_string($query) || $query === null, 422);
        $term = trim($query ?? '');
        abort_if(mb_strlen($term) > 150, 422);
        $patientId = $request->integer('patient');
        $matches = Encounter::where('branch_id', $branch)->when($patientId, fn ($q) => $q->where('patient_id', $patientId))
            ->orderByDesc('id')->cursor()->filter(function ($encounter) use ($term) {
                $record = $encounter->clinical_record;
                $record['symptoms'] = implode(' ', $record['symptoms']);
                return $term === '' || mb_stripos(implode(' ', $record), $term) !== false;
            })->collect();
        $names = Patient::whereIn('id', $matches->pluck('patient_id')->unique())->get()->keyBy('id');
        return view('clinical.encounters', compact('matches','names','term','patientId'));
    }

    public function create(Request $request, int $appointment)
    {
        $branch = $this->branch();
        $booking = $this->booking($branch, $appointment)->firstOrFail();
        $existing = Encounter::where('appointment_id', $appointment)->first();
        if ($existing) {
            return redirect('/staff/encounters/'.$existing->id);
        }
        $patient = Patient::findOrFail($booking->patient_id);
        $templates = ClinicalTemplate::where('branch_id', $branch)->orderBy('id')->get();
        $chosen = $request->integer('template');
        $template = $chosen ? $templates->find($chosen) : null;
        abort_if($chosen && !$template, 404);
        $record = $template?->clinical_record ?? [];
        return view('clinical.form', compact('booking','patient','templates','record','chosen'));
    }

    private function validateRecord(Request $request, bool $template = false): array
    {
        $rules = [
            'symptoms' => ['nullable', 'array', 'list', 'max:'.count(self::SYMPTOMS)],
            'symptoms.*' => ['string', 'distinct', Rule::in(self::SYMPTOMS)],
            'notes' => ['nullable','string','max:8000'],
            'diagnosis' => [$template ? 'nullable' : 'required','string','max:8000'],
            'prescription' => ['nullable','string','max:8000'],
            'plan' => ['nullable','string','max:8000'],
        ];
        // Avoid flashing unencrypted clinical text to the session on validation errors.
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            if ($template) {
                abort(422, '請檢查病歷欄位及字數。');
            }
            throw new ValidationException($validator);
        }
        $record = $validator->validated() + array_fill_keys(array_keys(self::FIELDS), '');
        $record['symptoms'] = $record['symptoms'] ?: [];
        return $record;
    }

    public function store(Request $request, int $appointment)
    {
        $branch = $this->branch();
        $booking = $this->booking($branch, $appointment)->firstOrFail();
        try {
            $record = $this->validateRecord($request);
        } catch (ValidationException $error) {
            $recordErrors = [];
            foreach ($error->errors() as $field => $messages) {
                $field = explode('.', $field)[0];
                $recordErrors[$field] = $field === 'symptoms'
                    ? '請從症狀分類中選取，勿重複選擇。'
                    : ($field === 'diagnosis' ? '請填寫辨證及診斷，最多 8,000 字。' : '請輸入文字，最多 8,000 字。');
            }
            if ($request->expectsJson()) {
                return response()->json(['message' => '請檢查病歷欄位及字數。', 'errors' => $recordErrors], 422);
            }
            // Re-render this response only. Never flash clinical text to the session.
            $record = [];
            foreach (self::FIELDS as $field => $label) {
                $value = $request->input($field);
                $record[$field] = $field === 'symptoms'
                    ? (is_array($value) ? array_values(array_intersect(self::SYMPTOMS, array_filter($value, 'is_string'))) : [])
                    : (is_string($value) ? $value : '');
            }
            $patient = Patient::findOrFail($booking->patient_id);
            $templates = ClinicalTemplate::where('branch_id', $branch)->orderBy('id')->get();
            $chosen = 0;
            return response()->view('clinical.form', compact('booking', 'patient', 'templates', 'record', 'chosen', 'recordErrors'), 422);
        }
        return DB::transaction(function () use ($appointment, $branch, $record) {
            $booking = $this->booking($branch, $appointment)->lockForUpdate()->firstOrFail();
            abort_if(Encounter::where('appointment_id', $appointment)->exists(), 409, '此預約已有病歷。');
            $encounter = Encounter::create(['appointment_id' => $appointment, 'patient_id' => $booking->patient_id,
                'branch_id' => $branch, 'recorded_by' => StaffAccess::id(), 'clinical_record' => $record]);
            return redirect('/staff/encounters/'.$encounter->id);
        }, 3);
    }

    public function template(Request $request)
    {
        $branch = $this->branch();
        $name = $request->input('name');
        abort_unless(is_string($name) && trim($name) !== '' && mb_strlen($name) <= 80, 422, '請填寫範本名稱。');
        $record = $this->validateRecord($request, true);
        ClinicalTemplate::create(['branch_id' => $branch, 'created_by' => StaffAccess::id(), 'name' => trim($name),
            'clinical_record' => $record]);
        return redirect('/staff/encounters')->with('status', '已儲存病歷範本。');
    }

    public function show(Request $request, int $encounter)
    {
        $branch = $this->branch();
        $current = Encounter::where('branch_id', $branch)->findOrFail($encounter);
        $patient = Patient::findOrFail($current->patient_id);
        $history = Encounter::where('branch_id', $branch)->where('patient_id', $current->patient_id)
            ->where('id', '<>', $encounter)->orderByDesc('id');
        $other = $request->filled('compare') ? (clone $history)->findOrFail($request->integer('compare')) : null;
        $previous = $history->get(['id','created_at']);
        return view('clinical.compare', compact('current','other','previous','patient'));
    }
}
