<?php

namespace App\Http\Controllers;

use App\Support\StaffAccess;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommerceController
{
    private function encounters(int $branch)
    {
        return DB::table('encounters as e')
            ->join('appointments as a', 'a.id', '=', 'e.appointment_id')
            ->join('slots as slot', 'slot.id', '=', 'a.slot_id')
            ->where('e.branch_id', $branch)
            ->whereNull('a.cancelled_at')
            ->select('a.reference', 'e.id', 'e.patient_id', 'slot.starts_at')->orderByDesc('e.id');
    }

    private function ledgerFilters(Request $request, array $rules): array
    {
        $filters = $request->validate($rules + [
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1',
        ]);
        if (!empty($filters['from']) && !empty($filters['to']) && $filters['to'] < $filters['from']) {
            throw ValidationException::withMessages(['to' => '結束日期不可早於開始日期。']);
        }
        return $filters;
    }

    private function ledgerPeriod($query, string $column, array $filters): void
    {
        if (!empty($filters['from'])) {
            $query->where($column, '>=', CarbonImmutable::parse($filters['from'], 'Asia/Hong_Kong')->utc());
        }
        if (!empty($filters['to'])) {
            $query->where($column, '<', CarbonImmutable::parse($filters['to'], 'Asia/Hong_Kong')->addDay()->utc());
        }
    }

    public function inventory(Request $request)
    {
        StaffAccess::allow('admin', 'reception', 'doctor');
        $branch = StaffAccess::branch();
        $filters = $this->ledgerFilters($request, [
            'herb_filter' => 'nullable|string|max:100', 'movement_kind' => 'nullable|in:receive,dispense',
        ]);
        $stocks = DB::table('herbal_stocks')->where('branch_id', $branch)->orderBy('herb')->get();
        $query = DB::table('herbal_movements as m')->join('herbal_stocks as s', 's.id', '=', 'm.stock_id')
            ->where('s.branch_id', $branch);
        if (isset($filters['herb_filter']) && $filters['herb_filter'] !== '') $query->where('s.herb', $filters['herb_filter']);
        if (!empty($filters['movement_kind'])) $query->where('m.kind', $filters['movement_kind']);
        $this->ledgerPeriod($query, 'm.created_at', $filters);
        $movements = $query->select('m.*', 's.herb')->orderByDesc('m.id')->paginate(25)->appends($filters);
        $encounters = $this->encounters($branch)->get();
        $patients = Patient::whereIn('id', $encounters->pluck('patient_id'))->get()->keyBy('id');
        return view('commerce.inventory', compact('stocks', 'movements', 'filters', 'encounters', 'patients'));
    }

    public function move(Request $request)
    {
        StaffAccess::allow('admin', 'reception', 'doctor');
        $branch = StaffAccess::branch();
        $data = $request->validate([
            'reference' => 'required|uuid',
            'kind' => 'required|in:receive,dispense',
            'herb' => 'required|string|max:100',
            'grams' => ['required', 'regex:/^\d{1,7}(\.\d{1,3})?$/D', 'numeric', 'gt:0'],
            'note' => 'nullable|string|max:160',
            'encounter_id' => 'nullable|integer|prohibited_if:kind,receive',
        ]);
        $mg = (int) round((float) $data['grams'] * 1000);
        DB::transaction(function () use ($branch, $data, $mg) {
            DB::table('branches')->where('id', $branch)->lockForUpdate()->firstOrFail();
            $prior = DB::table('herbal_movements as m')->join('herbal_stocks as s', 's.id', '=', 'm.stock_id')
                ->where('m.reference', $data['reference'])->select('m.*', 's.branch_id', 's.herb')->first();
            if ($prior) {
                abort_unless((int) $prior->branch_id === $branch && $prior->kind === $data['kind']
                    && $prior->herb === $data['herb'] && (int) $prior->milligrams === $mg
                    && (int) $prior->encounter_id === (int) ($data['encounter_id'] ?? 0)
                    && (string) $prior->note === (string) ($data['note'] ?? ''), 409);
                return;
            }
            if (!empty($data['encounter_id'])) {
                $this->encounters($branch)->where('e.id', $data['encounter_id'])->firstOrFail();
            }
            $stocks = DB::table('herbal_stocks')->where(['branch_id' => $branch, 'herb' => $data['herb']]);
            $stock = $stocks->first();
            if ($data['kind'] === 'dispense' && (!$stock || $stock->milligrams < $mg)) {
                throw ValidationException::withMessages(['grams' => '此藥材的分店存量不足。']);
            }
            if (!$stock) {
                $id = DB::table('herbal_stocks')->insertGetId(['branch_id' => $branch, 'herb' => $data['herb'], 'milligrams' => $mg]);
            } else {
                $id = $stock->id;
                $stocks->increment('milligrams', $data['kind'] === 'receive' ? $mg : -$mg);
            }
            DB::table('herbal_movements')->insert([
                'reference' => $data['reference'], 'stock_id' => $id,
                'encounter_id' => $data['encounter_id'] ?? null, 'kind' => $data['kind'],
                'milligrams' => $mg, 'note' => $data['note'] ?? null, 'created_at' => now('UTC'),
            ]);
        }, 3);
        return redirect('/staff/inventory')->with('status', '已記錄庫存變動。');
    }

    public function pos(Request $request)
    {
        StaffAccess::allow('admin', 'reception');
        $branch = StaffAccess::branch();
        $filters = $this->ledgerFilters($request, [
            'reference' => 'nullable|string|max:36', 'method' => 'nullable|in:cash,card,fps,other',
        ]);
        $encounters = $this->encounters($branch)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('receipts as r')->whereColumn('r.encounter_id', 'e.id'))->get();
        $query = DB::table('receipts as r')->join('appointments as a', 'a.id', '=', 'r.appointment_id')
            ->where('r.branch_id', $branch);
        if (isset($filters['reference']) && $filters['reference'] !== '') {
            $reference = trim($filters['reference']);
            $query->where(fn ($q) => $q->where('r.reference', $reference)->orWhere('a.reference', $reference)
                ->when(ctype_digit($reference), fn ($q) => $q->orWhere('r.id', $reference)));
        }
        if (!empty($filters['method'])) $query->where('r.method', $filters['method']);
        $this->ledgerPeriod($query, 'r.created_at', $filters);
        $totalCents = (clone $query)->sum('r.amount_cents');
        $receipts = $query->select('r.*', 'a.reference as booking')->orderByDesc('r.id')->paginate(25)->appends($filters);
        $patients = Patient::whereIn('id', $encounters->pluck('patient_id')->merge($receipts->pluck('patient_id')))->get()->keyBy('id');
        return view('commerce.pos', compact('encounters', 'receipts', 'filters', 'totalCents', 'patients'));
    }

    public function collect(Request $request)
    {
        StaffAccess::allow('admin', 'reception');
        $branch = StaffAccess::branch();
        $data = $request->validate([
            'encounter_id' => 'required|integer',
            'description' => 'required|string|max:160',
            'amount' => ['required', 'regex:/^\d{1,7}(\.\d{1,2})?$/D', 'numeric', 'gt:0'],
            'method' => 'required|in:cash,card,fps,other',
        ]);
        $cents = (int) round((float) $data['amount'] * 100);
        $receipt = DB::transaction(function () use ($branch, $data, $cents) {
            $encounter = DB::table('encounters')->where('id', $data['encounter_id'])
                ->where('branch_id', $branch)->lockForUpdate()->firstOrFail();
            DB::table('appointments')->join('slots', 'slots.id', '=', 'appointments.slot_id')
                ->where('appointments.id', $encounter->appointment_id)->where('slots.branch_id', $branch)
                ->where('appointments.patient_id', $encounter->patient_id)->whereNull('cancelled_at')
                ->lockForUpdate()->firstOrFail();
            $prior = DB::table('receipts')->where('encounter_id', $encounter->id)->lockForUpdate()->first();
            if ($prior) {
                abort_unless((int) $prior->amount_cents === $cents && $prior->method === $data['method']
                    && $prior->description === $data['description'], 409, '此診症已有收據。');
                return $prior->id;
            }
            $id = DB::table('receipts')->insertGetId([
                'reference' => (string) Str::uuid(), 'branch_id' => $branch, 'patient_id' => $encounter->patient_id,
                'appointment_id' => $encounter->appointment_id, 'encounter_id' => $encounter->id,
                'description' => $data['description'], 'amount_cents' => $cents, 'method' => $data['method'],
                'created_at' => now('UTC'),
            ]);
            event('clinic.receipt.paid', [$id]);
            return $id;
        }, 3);
        return redirect('/staff/pos/'.$receipt);
    }

    public function receipt(int $receipt)
    {
        StaffAccess::allow('admin', 'reception');
        $item = DB::table('receipts as r')->join('appointments as a', 'a.id', '=', 'r.appointment_id')
            ->join('branches as b', 'b.id', '=', 'r.branch_id')->where('r.branch_id', StaffAccess::branch())
            ->where('r.id', $receipt)->select('r.*', 'a.reference as booking', 'b.name_zh as branch')->firstOrFail();
        return view('commerce.receipt', compact('item'));
    }
}
