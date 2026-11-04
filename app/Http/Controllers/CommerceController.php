<?php

namespace App\Http\Controllers;

use App\Support\StaffAccess;
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
            ->where('e.branch_id', $branch)
            ->whereNull('a.cancelled_at')
            ->select('a.reference', 'e.id')->orderByDesc('e.id');
    }

    public function inventory()
    {
        StaffAccess::allow('admin', 'reception', 'doctor');
        $branch = StaffAccess::branch();
        $stocks = DB::table('herbal_stocks')->where('branch_id', $branch)->orderBy('herb')->get();
        $movements = DB::table('herbal_movements as m')->join('herbal_stocks as s', 's.id', '=', 'm.stock_id')
            ->where('s.branch_id', $branch)->select('m.*', 's.herb')->orderByDesc('m.id')->paginate(25);
        return view('commerce.inventory', compact('stocks', 'movements') + ['encounters' => $this->encounters($branch)->get()]);
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

    public function pos()
    {
        StaffAccess::allow('admin', 'reception');
        $branch = StaffAccess::branch();
        $encounters = $this->encounters($branch)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('receipts as r')->whereColumn('r.encounter_id', 'e.id'))->get();
        $receipts = DB::table('receipts')->where('branch_id', $branch)->orderByDesc('id')->paginate(25);
        return view('commerce.pos', compact('encounters', 'receipts'));
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
