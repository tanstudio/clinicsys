<?php

namespace App\Messaging;

use App\Models\Appointment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class Delivery
{
    public function consume(): void
    {
        // After a crash the provider may already have accepted the request.
        DB::table('message_entries')->where('status','sending')->where('claimed_at','<',now()->subMinutes(10))
            ->update(['status' => 'review', 'error' => 'delivery_unknown', 'updated_at' => now()]);
        $ids = DB::table('message_entries')->where('status','queued')->where('due_at','<=',now())
            ->orderBy('due_at')->orderBy('id')->limit(100)->pluck('id');
        foreach ($ids as $id) {
            $entry = DB::transaction(function () use ($id) {
                $row = DB::table('message_entries')->where('id', $id)->where('status','queued')->where('due_at','<=',now())->lockForUpdate()->first();
                if (!$row) {
                    return;
                }
                DB::table('message_entries')->where('id',$id)->update(['status' => 'sending',
                    'attempts' => $row->attempts + 1, 'claimed_at' => now(), 'updated_at' => now()]);
                return $row;
            });
            if ($entry) {
                $this->deliver($entry);
            }
        }
    }

    private function deliver(object $entry): void
    {
        $thread = DB::table('message_threads')->find($entry->thread_id);
        if ($entry->kind === 'reply' && str_starts_with($entry->event_key,'reply:incoming:')
            && ($thread->automation_paused || $thread->assignee_id)) {
            $this->finish($entry,'suppressed','automation_paused');
            return;
        }
        if ($entry->appointment_event_id && DB::table('appointment_events')->where('appointment_id',$entry->appointment_id)
            ->max('id') != $entry->appointment_event_id) {
            $this->finish($entry, 'superseded');
            return;
        }
        if ($entry->appointment_event_id && DB::table('appointments as a')->join('slots as s','s.id','=','a.slot_id')
            ->where('a.id',$entry->appointment_id)->where('s.starts_at','<=',now('Asia/Hong_Kong'))->exists()) {
            $this->finish($entry, 'superseded');
            return;
        }
        if ($entry->channel === 'whatsapp' && $entry->appointment_id && !Appointment::whereKey($entry->appointment_id)->value('whatsapp_opt_in')) {
            $this->finish($entry, 'suppressed', 'no_notification_consent');
            return;
        }
        if ($entry->kind === 'marketing' && !DB::table('message_consents')->where('contact_key', $thread->contact_key)->value('marketing')) {
            $this->finish($entry, 'suppressed', 'no_consent');
            return;
        }
        if ($entry->kind === 'reply' && !DB::table('message_entries as e')->join('message_threads as t','t.id','=','e.thread_id')
            ->where('t.contact_key',$thread->contact_key)->where('t.branch_id',$thread->branch_id)
            ->where('e.direction','inbound')->whereRaw('COALESCE(e.provider_received_at,e.created_at) > ?', [now()->subDay()])->exists()) {
            $this->finish($entry,'suppressed','outside_service_window');
            return;
        }
        $body = Crypt::decryptString($entry->body);
        try {
            if ($entry->channel === 'email') {
                if (!config('clinic_messaging.email_enabled') || config('mail.default') !== 'smtp') {
                    $this->finish($entry,'unconfigured','smtp_required');
                    return;
                }
                Mail::raw($body, fn ($m) => $m->to(Crypt::decryptString($entry->recipient))->subject('Clinic · 診所通知'));
            } else {
                $result = app(CloudWhatsApp::class)->send($entry, $thread, $body);
                $this->finish($entry,$result['status'],$result['error'] ?? null,$result['due'] ?? null);
                return;
            }
            $this->finish($entry, 'accepted');
        } catch (\Throwable $e) {
            // A timeout can follow provider acceptance; require review before retrying.
            $this->finish($entry, 'review', 'delivery_unknown');
        }
    }

    private function finish(object $entry, string $status, ?string $error = null, $due = null): void
    {
        DB::table('message_entries')->where('id', $entry->id)->where('status','sending')->update([
            'status' => $status, 'error' => $error, 'due_at' => $due ?? $entry->due_at, 'updated_at' => now(),
        ]);
    }
}
