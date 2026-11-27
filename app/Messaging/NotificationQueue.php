<?php

namespace App\Messaging;

use App\Models\Appointment;
use App\Models\Patient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationQueue
{
    public function collect(): void
    {
        foreach (['appointment_events','receipts','encounters','package_ledger'] as $source) {
            if (!Schema::hasTable($source)) {
                continue;
            }
            $rows = DB::table("$source as s")->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('message_projections as m')->where('m.source', $source)->whereColumn('m.source_id','s.id'))
                ->orderBy('s.id')->limit(250)->get();
            foreach ($rows as $row) {
                DB::transaction(function () use ($source, $row) {
                    if (DB::table('message_projections')->insertOrIgnore(['source' => $source, 'source_id' => $row->id])) {
                        $this->collectOne($source, $row);
                    }
                }, 3);
            }
        }
    }

    private function collectOne(string $source, object $row): void
    {
        if ($source === 'package_ledger') {
            $this->collectPackage($row);
            return;
        }
        $appointment = Appointment::find($row->appointment_id);
        if (!$appointment) {
            return;
        }
        $eventId = null;
        $parameters = [];
        $due = now();
        if ($source === 'appointment_events') {
            $eventId = $row->id;
            if (DB::table('appointment_events')->where('appointment_id', $appointment->id)->where('id','>', $eventId)->exists()) {
                return;
            }
            $kind = $row->event;
            $slot = DB::table('slots')->find($row->to_slot_id ?? $row->from_slot_id);
            $starts = CarbonImmutable::parse($slot->starts_at, 'Asia/Hong_Kong');
            if (!$starts->isFuture()) {
                return;
            }
            $body = match ($kind) {
                'confirmed' => '預約已確認 / Appointment confirmed',
                'rescheduled' => '預約已更改 / Appointment rescheduled',
                'cancelled' => '預約已取消 / Appointment cancelled',
            };
            $body .= " · $appointment->reference · $slot->starts_at (Hong Kong)";
            $branch = $slot->branch_id;
            $parameters = ['reference'=>$appointment->reference,'starts_at'=>$slot->starts_at.' (Hong Kong)'];
        } elseif ($source === 'receipts') {
            // A package redemption is an HKD 0 receipt; its balance notice comes from the package ledger.
            if ($row->method === 'package') {
                return;
            }
            $kind = 'payment';
            $branch = $row->branch_id;
            $body = "已收款 / Payment received · HKD ".number_format($row->amount_cents / 100, 2)." · $row->reference";
            $parameters = ['reference'=>$row->reference,'amount'=>'HKD '.number_format($row->amount_cents / 100, 2)];
        } else {
            $kind = 'feedback';
            $branch = $row->branch_id;
            $due = CarbonImmutable::parse($row->created_at, config('app.timezone'))->addDay();
            $body = '感謝到診。歡迎回覆分享您的意見。Thank you for visiting. Please reply with your feedback.';
        }
        $patient = Patient::findOrFail($appointment->patient_id);
        $thread = Inbox::thread($branch, $patient->phone, $patient->id);
        $key = "$source:$row->id";
        $parameters['branch_name'] = DB::table('branches')->where('id',$branch)->value('name_zh');
        Inbox::enqueue($thread, "$key:wa", $kind, $body, 'whatsapp', null, $due, $eventId, $appointment->id, $parameters);
        if ($appointment->email && $kind !== 'feedback') {
            Inbox::enqueue($thread, "$key:email", $kind, $body, 'email', $appointment->email, $due, $eventId, $appointment->id);
        }
        if (in_array($kind, ['confirmed','rescheduled'], true)) {
            $remind = $starts->subDay()->setTimezone(config('app.timezone'));
            // A booking made within 24 hours receives its confirmation immediately.
            if ($remind->greaterThanOrEqualTo(CarbonImmutable::parse($row->created_at, config('app.timezone')))) {
                $reminder = "預約提示 / Appointment reminder · $slot->starts_at (Hong Kong) · $appointment->reference";
                Inbox::enqueue($thread, "$key:reminder", 'reminder', $reminder, 'whatsapp', null, $remind, $eventId, $appointment->id, $parameters);
                if ($appointment->email) {
                    Inbox::enqueue($thread, "$key:email-reminder", 'reminder', $reminder, 'email', $appointment->email, $remind, $eventId, $appointment->id);
                }
            }
        }
    }

    /**
     * One balance notice per package ledger row, to the package owner, when head office has notices on.
     * WhatsApp only, linked to the owner's latest appointment so delivery re-checks that booking's notification consent.
     */
    private function collectPackage(object $row): void
    {
        if (!\App\Support\Loyalty::settings()->balance_notices) {
            return;
        }
        $package = DB::table('patient_packages')->find($row->package_id);
        $appointment = Appointment::where('patient_id', $package->patient_id)->orderByDesc('id')->first();
        if (!$appointment || !$appointment->whatsapp_opt_in) {
            return;
        }
        $balance = (int) DB::table('package_ledger')->where('package_id', $package->id)->where('id', '<=', $row->id)
            ->selectRaw('COALESCE(SUM(sessions) + SUM(value_cents), 0) AS balance')->value('balance');
        $change = $row->sessions + $row->value_cents;
        $format = fn (int $amount) => $package->kind === 'stored_value' ? 'HKD '.number_format($amount / 100, 2) : $amount.' 次 sessions';
        $action = ['purchase'=>'購買 Purchase','redeem'=>'使用 Used','void'=>'撤銷使用 Use reversed','refund'=>'退款 Refunded',
            'transfer_out'=>'轉出 Transferred out','transfer_in'=>'轉入 Transferred in'][$row->kind] ?? $row->kind;
        $parameters = ['package_name'=>$package->name, 'change'=>$action.' '.($change > 0 ? '+' : '').$format($change),
            'balance'=>$format($balance), 'expires_on'=>$package->expires_on,
            'branch_name'=>DB::table('branches')->where('id', $row->branch_id)->value('name_zh')];
        $body = "套票餘額 / Package balance · $package->name · {$parameters['change']} · 餘額 Balance {$parameters['balance']} · 有效至 Valid until $package->expires_on";
        $patient = Patient::findOrFail($package->patient_id);
        $thread = Inbox::thread($row->branch_id, $patient->phone, $patient->id);
        Inbox::enqueue($thread, "package_ledger:$row->id:wa", 'package', $body, 'whatsapp', null, now(), null, $appointment->id, $parameters);
    }
}
