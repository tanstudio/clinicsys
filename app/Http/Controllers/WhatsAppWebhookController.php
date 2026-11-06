<?php

namespace App\Http\Controllers;

use App\Messaging\CloudWhatsApp;
use App\Messaging\Inbox;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WhatsAppWebhookController
{
    public function verify(Request $request)
    {
        $secret = config('clinic_messaging.whatsapp.verify_token');
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));
        abort_unless(is_string($secret) && strlen($secret)>=32 && is_string($token)
            && hash_equals($secret,$token) && $mode === 'subscribe' && is_string($challenge),403);
        return response($challenge,200)->header('Content-Type','text/plain');
    }

    public function receive(Request $request, Inbox $inbox, CloudWhatsApp $cloud)
    {
        $secret = config('clinic_messaging.whatsapp.app_secret');
        $signature = $request->header('X-Hub-Signature-256');
        abort_unless(is_string($secret) && strlen($secret)>=16 && is_string($signature)
            && hash_equals('sha256='.hash_hmac('sha256',$request->getContent(),$secret),$signature),401);
        $data = $request->validate([
            'object'=>'required|in:whatsapp_business_account','entry'=>'required|array|max:100',
            'entry.*.id'=>'required|string','entry.*.changes'=>'required|array|max:100',
            'entry.*.changes.*.field'=>'required|string',
            'entry.*.changes.*.value'=>'sometimes|array',
            'entry.*.changes.*.value.messages'=>'sometimes|array|max:100',
            'entry.*.changes.*.value.statuses'=>'sometimes|array|max:100',
            'entry.*.changes.*.value.metadata'=>'sometimes|array',
        ]);
        $processed = 0;
        foreach ($data['entry'] as $entry) {
            $account = config('clinic_messaging.whatsapp.business_account_id');
            if (!$account || $entry['id'] !== (string)$account) continue;
            foreach ($entry['changes'] as $change) {
                if (($change['field'] ?? null) !== 'messages' || !is_array($change['value'] ?? null)) continue;
                $value = $change['value'];
                $phoneId = config('clinic_messaging.whatsapp.phone_number_id');
                if (!$phoneId || ($value['metadata']['phone_number_id'] ?? null) !== (string)$phoneId) continue;
                foreach (($value['statuses'] ?? []) as $receipt) {
                    if (is_array($receipt)) $cloud->receipt($receipt);
                }
                foreach (($value['messages'] ?? []) as $message) {
                    if (!is_array($message) || !is_string($message['id'] ?? null) || $message['id']==='' || strlen($message['id'])>255
                        || !is_string($message['from'] ?? null) || !preg_match('/^[1-9][0-9]{7,14}$/D',$message['from'])
                        || !is_scalar($message['timestamp'] ?? null) || !ctype_digit((string)($message['timestamp'] ?? ''))
                        || (int)$message['timestamp']<1 || (int)$message['timestamp']>time()+300) continue;
                    $branch = DB::table('whatsapp_settings')->where('id',1)->value('default_branch_id');
                    abort_unless($branch && DB::table('branches')->where('id',$branch)->exists(),503,'WhatsApp reception branch is not configured.');
                    $text = match ($message['type'] ?? '') {
                        'text'=>$message['text']['body'] ?? null,
                        'button'=>$message['button']['payload'] ?? $message['button']['text'] ?? null,
                        'interactive'=>$message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null,
                        default=>null,
                    };
                    $supported = is_string($text) && $text !== '' && mb_strlen($text)<=4096;
                    $received = Carbon::createFromTimestamp((int)$message['timestamp'])->setTimezone(config('app.timezone'));
                    DB::transaction(function () use ($branch,$message,$text,$supported,$received,$inbox) {
                        $contact = hash_hmac('sha256','+'.$message['from'],config('app.key'));
                        DB::table('whatsapp_routes')->insertOrIgnore(['contact_key'=>$contact,'branch_id'=>$branch]);
                        $route = DB::table('whatsapp_routes')->where('contact_key',$contact)->lockForUpdate()->first();
                        $target = $route->branch_id;
                        $latest = !$route->last_received_at || $received->greaterThanOrEqualTo($route->last_received_at);
                        if ($latest && $supported && preg_match('/^(BOOK|預約)[+]?@(\d+)(?:\s|$)/iu',trim($text),$selection)
                            && DB::table('branches')->where('id',$selection[2])->exists()) $target = (int)$selection[2];
                        $result = $inbox->receive(['branch_id'=>(int)$target,'phone'=>'+'.$message['from'],
                            'message_id'=>$message['id'], 'text'=>$supported ? $text : '收到非文字訊息，待職員跟進。Non-text message received; staff follow-up required.',
                            'force_handoff'=>!$supported,'received_at'=>$received,'stale'=>!$latest || $received->lessThanOrEqualTo(now()->subDay()),
                        ]);
                        if ($latest && $result !== 'duplicate') DB::table('whatsapp_routes')->where('contact_key',$contact)
                            ->update(['branch_id'=>$target,'last_received_at'=>$received]);
                    },3);
                    $processed++;
                }
            }
        }
        return response()->json(['accepted'=>true,'processed'=>$processed]);
    }
}
