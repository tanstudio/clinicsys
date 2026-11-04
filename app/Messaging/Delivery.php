<?php

namespace App\Messaging;

use App\Models\Appointment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            ->where('e.direction','inbound')->where('e.created_at','>',now()->subDay())->exists()) {
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
                $url = config('clinic_messaging.flow_url');
                $headers = config('clinic_messaging.flow_headers');
                if (!$url || parse_url($url, PHP_URL_SCHEME) !== 'https' || !$headers || !is_array($headers)) {
                    $this->finish($entry,'unconfigured','sleekflow_required');
                    return;
                }
                $response = Http::withHeaders($headers)->withoutRedirecting()->asJson()->timeout(8)->post($url, [
                    'event_id' => $entry->event_key, 'phone' => Crypt::decryptString($thread->phone), 'kind' => $entry->kind,
                    'body' => $body, 'branch_id' => $thread->branch_id,
                ]);
                if ($response->status() === 429 && $entry->attempts < 4) {
                    $delay = min(3600, max(60, (int) $response->header('Retry-After')));
                    $this->finish($entry, 'queued', 'rate_limited', now()->addSeconds($delay));
                    return;
                }
                if (!$response->successful()) {
                    $this->finish($entry, $response->serverError() ? 'review' : 'failed', 'HTTP_'.$response->status());
                    return;
                }
            }
            $this->finish($entry, 'accepted');
        } catch (\Throwable $e) {
            // Neither SMTP nor a Flow Builder trigger guarantees remote de-duplication.
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
