<?php

namespace App\Messaging;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CloudWhatsApp
{
    public const KINDS = [
        'confirmed'=>'預約確認', 'rescheduled'=>'預約改期', 'cancelled'=>'預約取消',
        'reminder'=>'預約提醒', 'payment'=>'收款通知', 'feedback'=>'診後隨訪', 'marketing'=>'推廣',
    ];
    public const PARAMETERS = [
        'confirmed'=>['reference','starts_at','branch_name'],
        'rescheduled'=>['reference','starts_at','branch_name'],
        'cancelled'=>['reference','starts_at','branch_name'],
        'reminder'=>['reference','starts_at','branch_name'],
        'payment'=>['reference','amount','branch_name'],
        'feedback'=>['branch_name'], 'marketing'=>['body','branch_name'],
    ];

    public function send(object $entry, object $thread, string $body): array
    {
        $config = config('clinic_messaging.whatsapp');
        if (empty($config['access_token']) || !preg_match('/^[0-9]+$/D', $config['phone_number_id'] ?? '')
            || !preg_match('/^v[0-9]+\.[0-9]+$/D', $config['graph_version'] ?? '')) {
            return ['status'=>'unconfigured','error'=>'whatsapp_credentials_required'];
        }
        $payload = ['messaging_product'=>'whatsapp','recipient_type'=>'individual',
            'to'=>ltrim(Crypt::decryptString($thread->phone), '+')];
        if ($entry->kind === 'reply') {
            $payload += ['type'=>'text','text'=>['preview_url'=>false,'body'=>$body]];
        } else {
            $template = DB::table('whatsapp_templates')->where('kind',$entry->kind)->where('approved',true)->first();
            if (!$template) return ['status'=>'unconfigured','error'=>'whatsapp_template_required'];
            $data = $entry->template_data ? json_decode(Crypt::decryptString($entry->template_data), true) : [];
            $data += ['body'=>$body,'branch_name'=>DB::table('branches')->where('id',$thread->branch_id)->value('name_zh')];
            $parameters = [];
            foreach (json_decode($template->parameters, true) as $key) {
                if (!in_array($key, self::PARAMETERS[$entry->kind] ?? [], true) || !isset($data[$key])) {
                    return ['status'=>'unconfigured','error'=>'whatsapp_template_parameters_required'];
                }
                $parameters[] = ['type'=>'text','text'=>(string)$data[$key]];
            }
            $payload += ['type'=>'template','template'=>['name'=>$template->name,'language'=>['code'=>$template->language]]];
            if ($parameters) $payload['template']['components'] = [['type'=>'body','parameters'=>$parameters]];
        }
        // Correlate callbacks that can arrive before the HTTP response is stored.
        $reference = (string) Str::uuid();
        DB::table('message_entries')->where('id',$entry->id)->update(['provider_reference'=>$reference,'provider_message_id'=>null]);
        $payload['biz_opaque_callback_data'] = $reference;
        $response = Http::withToken($config['access_token'])->withoutRedirecting()->asJson()->timeout(10)
            ->post('https://graph.facebook.com/'.$config['graph_version'].'/'.$config['phone_number_id'].'/messages', $payload);
        if ($response->status() === 429 && $entry->attempts < 4) {
            return ['status'=>'queued','error'=>'rate_limited','due'=>now()->addSeconds(min(3600,max(60,(int)$response->header('Retry-After'))))];
        }
        if (!$response->successful()) {
            return ['status'=>$response->serverError() ? 'review' : 'failed','error'=>'HTTP_'.$response->status()];
        }
        $messageId = $response->json('messages.0.id');
        if (!is_string($messageId) || $messageId === '' || strlen($messageId)>255) {
            return ['status'=>'review','error'=>'delivery_unknown'];
        }
        DB::table('message_entries')->where('id',$entry->id)->where('provider_reference',$reference)
            ->whereNull('provider_message_id')->update(['provider_message_id'=>$messageId]);
        return ['status'=>'accepted'];
    }

    public function receipt(array $receipt): void
    {
        $id = $receipt['id'] ?? null;
        $status = $receipt['status'] ?? null;
        $phone = $receipt['recipient_id'] ?? null;
        if (!is_string($id) || !is_string($phone) || !preg_match('/^[1-9][0-9]{7,14}$/D',$phone)
            || !in_array($status,['sent','delivered','read','failed'],true)) return;
        DB::transaction(function () use ($receipt,$id,$status,$phone) {
            $entry = DB::table('message_entries')->where('channel','whatsapp')->where('direction','outbound')
                ->where(function ($q) use ($receipt,$id) {
                    $q->where('provider_message_id',$id);
                    if (isset($receipt['biz_opaque_callback_data']) && is_string($receipt['biz_opaque_callback_data'])) {
                        $q->orWhere('provider_reference',$receipt['biz_opaque_callback_data']);
                    }
                })->lockForUpdate()->first();
            if (!$entry || ($entry->provider_message_id && $entry->provider_message_id !== $id)) return;
            $thread = DB::table('message_threads')->find($entry->thread_id);
            if (!hash_equals($thread->contact_key,hash_hmac('sha256','+'.$phone,config('app.key')))) return;
            $rank = ['sending'=>0,'review'=>0,'accepted'=>1,'sent'=>2,'failed'=>3,'delivered'=>4,'read'=>5];
            if (!isset($rank[$entry->status]) || $rank[$status] <= $rank[$entry->status]) return;
            $error = $status === 'failed' ? 'META_'.(int)($receipt['errors'][0]['code'] ?? 0) : null;
            DB::table('message_entries')->where('id',$entry->id)->update([
                'provider_message_id'=>$id,'status'=>$status,'error'=>$error,'updated_at'=>now(),
            ]);
        });
    }
}
