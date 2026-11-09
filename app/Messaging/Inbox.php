<?php

namespace App\Messaging;

use App\Actions\CreateAppointment;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Inbox
{
    public static function pauseAutomatedReplies(int $thread): void
    {
        DB::table('message_entries')->where('thread_id',$thread)->where('status','queued')
            ->where('kind','reply')->where('event_key','like','reply:incoming:%')
            ->update(['status'=>'suppressed','error'=>'automation_paused','updated_at'=>now()]);
    }

    public static function thread(int $branch, string $phone, ?int $patient = null): object
    {
        $contact = hash_hmac('sha256', $phone, config('app.key'));
        $key = hash('sha256', "$branch|$contact|".($patient ?? 'inbound'));
        DB::table('message_threads')->insertOrIgnore([
            'thread_key' => $key, 'contact_key' => $contact, 'phone' => Crypt::encryptString($phone),
            'branch_id' => $branch, 'patient_id' => $patient, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return DB::table('message_threads')->where('thread_key', $key)->lockForUpdate()->firstOrFail();
    }

    public static function enqueue(object $thread, string $key, string $kind, string $body, string $channel = 'whatsapp',
        ?string $recipient = null, $due = null, ?int $event = null, ?int $appointment = null, ?array $templateData = null): void
    {
        DB::table('message_entries')->insertOrIgnore([
            'thread_id' => $thread->id, 'event_key' => $key, 'direction' => 'outbound', 'channel' => $channel,
            'kind' => $kind, 'body' => Crypt::encryptString($body), 'recipient' => $recipient ? Crypt::encryptString($recipient) : null,
            'template_data' => $templateData ? Crypt::encryptString(json_encode($templateData, JSON_THROW_ON_ERROR)) : null,
            'due_at' => $due ?? now(), 'status' => 'queued', 'appointment_event_id' => $event, 'appointment_id' => $appointment,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('message_threads')->where('id', $thread->id)->update(['updated_at' => now()]);
    }

    public function receive(array $data): string
    {
        // A patient may select another public booking branch in the chat itself.
        if (empty($data['stale']) && ($data['direction'] ?? 'inbound') === 'inbound'
            && preg_match('/^(BOOK|預約)([+]?)@(\d+)(?:\s|$)/iu', trim($data['text']), $selection)
            && DB::table('branches')->where('id',$selection[3])->exists()) {
            $data['branch_id'] = (int) $selection[3];
        }
        return DB::transaction(function () use ($data) {
            $inbound = self::thread($data['branch_id'], $data['phone']);
            $thread = $this->verifiedContact($inbound);
            $key = 'incoming:'.hash('sha256', $data['message_id']);
            $text = trim($data['text']);
            $outbound = ($data['direction'] ?? 'inbound') === 'outbound';
            $inserted = DB::table('message_entries')->insertOrIgnore([
                'thread_id' => $thread->id, 'event_key' => $key, 'direction' => $outbound ? 'outbound' : 'inbound', 'channel' => 'whatsapp',
                'body' => Crypt::encryptString($text), 'status' => $outbound ? 'provider_recorded' : 'received', 'kind' => $outbound ? 'provider_reply' : 'message',
                'due_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                'provider_received_at' => $data['received_at'] ?? null,
            ]);
            if (!$inserted) {
                return 'duplicate';
            }
            if ($outbound) {
                DB::table('message_threads')->where('id', $thread->id)->update(['updated_at'=>now()]);
                return 'recorded';
            }
            $updates = ['status' => 'open', 'updated_at' => now()];
            $reply = null;
            $paused = $thread->automation_paused || $thread->assignee_id;
            $route = $paused ? 'human' : 'triage';
            if (in_array(mb_strtoupper($text), ['START','STOP','訂閱','退訂'], true)
                && (empty($data['stale']) || in_array(mb_strtoupper($text),['STOP','退訂'],true))) {
                $allow = in_array(mb_strtoupper($text), ['START','訂閱'], true);
                DB::table('message_consents')->upsert([['contact_key' => $thread->contact_key,
                    'marketing' => $allow, 'updated_at' => now()]], ['contact_key'], ['marketing','updated_at']);
                $reply = $allow ? '已訂閱推廣訊息。Promotion subscription active. Reply STOP to unsubscribe.'
                    : '已取消訂閱推廣訊息。Promotion subscription cancelled.';
            } elseif (!empty($data['stale'])) {
                $route = 'human';
                $updates['category'] = 'delayed_message';
                $updates['automation_paused'] = true;
            } elseif (!empty($data['force_handoff']) || in_array(mb_strtolower($text), ['human','agent','真人','職員'], true)) {
                $route = 'human';
                $updates['category'] = 'handoff';
                $updates['automation_paused'] = true;
                if (!$paused) $reply = '已通知職員跟進。Reception will follow up with you.';
            } elseif ($paused) {
                // Human ownership persists until reception explicitly resumes automation.
                $route = 'human';
            } elseif (in_array(mb_strtoupper($text), ['BRANCHES','分店'], true)) {
                $reply = DB::table('branches')->orderBy('id')->get()->map(fn ($b) => "BOOK@$b->id · $b->name_zh / $b->name_en")->implode("\n");
            } elseif (preg_match('/^(?:BOOK|預約)[+]?(?:@\d+)?(?:\s|$)/iu', $text)) {
                $updates['category'] = 'booking';
                $reply = $this->booking($text, $data, $thread);
                DB::table('message_entries')->where('event_key',$key)->update(['thread_id'=>$thread->id]);
            } else {
                $rule = DB::table('message_rules')->where('branch_id', $thread->branch_id)
                    ->orderBy('id')->get()->first(fn ($r) => Str::contains(mb_strtolower($text),
                        array_values(array_filter(array_map('trim',explode('|',mb_strtolower($r->keyword)))))));
                if ($rule) {
                    $route = $rule->assignee_id ? 'human' : 'local';
                    $updates['category'] = $rule->category;
                    $updates['assignee_id'] = $rule->assignee_id && DB::table('staff_accounts')
                        ->where('id',$rule->assignee_id)->where('branch_id',$thread->branch_id)
                        ->where('active',true)->whereIn('role',['admin','reception'])->exists() ? $rule->assignee_id : null;
                    $updates['automation_paused'] = (bool)$rule->assignee_id;
                    $reply = $rule->reply;
                } elseif (in_array(mb_strtolower($text), ['help','faq','幫助','常見問題'], true)) {
                    $reply = '回覆「預約」查詢本分店時段，或留言予職員。Reply BOOK for appointments, or send your enquiry to reception.';
                } else {
                    $route = 'human';
                    $updates['category'] = 'handoff';
                    $updates['automation_paused'] = true;
                    $reply = '已收到查詢，將由職員回覆。Your enquiry has been received. Reception will reply.';
                }
            }
            DB::table('message_threads')->where('id', $thread->id)->update($updates);
            if (!empty($updates['automation_paused'])) self::pauseAutomatedReplies($thread->id);
            if ($reply) {
                self::enqueue($thread, ($route === 'human' ? 'handoff:' : 'reply:').$key, 'reply', $reply);
            }
            return $route === 'human' ? 'human' : ($reply ? 'local' : $route);
        }, 3);
    }

    private function verifiedContact(object $inbound): object
    {
        $linked = DB::table('message_threads')->where('branch_id',$inbound->branch_id)
            ->where('contact_key',$inbound->contact_key)->whereNotNull('patient_id')->get();
        if ($linked->pluck('patient_id')->unique()->count() === 1) {
            foreach ($linked as $known) {
                if (DB::table('message_entries')->where('thread_id',$known->id)->where('direction','inbound')->exists()) {
                    return DB::table('message_threads')->where('id',$known->id)->lockForUpdate()->firstOrFail();
                }
            }
        }
        if ($inbound->patient_id && $linked->pluck('patient_id')->unique()->count() > 1) {
            DB::table('message_threads')->where('id',$inbound->id)->update(['thread_key'=>hash('sha256','verified:'.$inbound->id)]);
            return self::thread($inbound->branch_id, Crypt::decryptString($inbound->phone));
        }
        return $inbound;
    }

    private function booking(string $text, array $data, object &$thread): string
    {
        if (preg_match('/^(?:BOOK|預約)[+]?@(\d+)/iu', $text, $selection) && (int)$selection[1] !== (int)$data['branch_id']) {
            return '找不到分店。Unknown branch. Reply BRANCHES for available branches.';
        }
        $text = preg_replace('/^((?:BOOK|預約)[+]?)@\d+/iu', '$1', $text);
        if (preg_match('/^(?:BOOK|預約)([+]?)\s+(\d+)\s+(.+)$/iu', $text, $match)) {
            $input = ['whatsapp_opt_in' => $match[1] === '+', 'slot_id' => $match[2], 'name' => $match[3], 'phone' => $data['phone']];
            if (Validator::make($input, CreateAppointment::RULES)->fails()) {
                return '請使用香港或中國內地手機號碼及有效姓名，或聯絡職員。Use a Hong Kong or mainland China mobile number and a valid name, or contact reception.';
            }
            $booking = app(CreateAppointment::class)->book($input, null, (int) $data['branch_id']);
            if ($booking) {
                $thread = self::thread($data['branch_id'], $data['phone'], $booking->patient_id);
                return '預約成功。Booking reference '.$booking->reference;
            }
        }
        $clock = now('Asia/Hong_Kong');
        $page = preg_match('/^(?:BOOK|預約)\s+PAGE\s+(\d{1,5})$/iu', $text, $pageMatch) ? max(1, (int)$pageMatch[1]) : 1;
        $command = 'BOOK@'.$data['branch_id'];
        $slots = DB::table('slots as s')->join('practitioners as p', 'p.id', '=', 's.practitioner_id')
            ->join('services as v', 'v.id', '=', 's.service_id')->where('s.branch_id', $data['branch_id'])
            ->whereNull('s.closed_at')->where('s.starts_at', '>', $clock)->where('s.starts_at', '<', $clock->copy()->startOfDay()->addDays(14))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('appointments')->whereColumn('slot_id','s.id')->whereNull('cancelled_at'))
            ->orderBy('s.starts_at')->orderBy('s.id')->offset(($page-1)*8)->limit(9)->get(['s.id','s.starts_at','p.name_zh as doctor','v.name_zh as service','p.name_en as doctor_en','v.name_en as service_en']);
        return $slots->isEmpty() ? '暫無可預約時段。No available slots. Reply BRANCHES for other branches or contact reception.'
            : "可預約時段 / Available appointments (Hong Kong)\n".$slots->take(8)->map(fn ($s) => "$s->id · $s->starts_at · $s->doctor / $s->doctor_en · $s->service / $s->service_en")->implode("\n")
                .($slots->count() > 8 ? "\n下一頁 / Next: $command PAGE ".($page+1) : '')
                ."\n轉分店 / Change branch: BRANCHES\n回覆 $command 時段編號 姓名 / Reply $command <slot number> <full name>.\n如同意接收預約、付款及到診意見 WhatsApp 通知，請改用 BOOK+@{$data['branch_id']}。Use BOOK+@{$data['branch_id']} to opt into appointment, payment and feedback messages.\n".url('/');
    }
}
