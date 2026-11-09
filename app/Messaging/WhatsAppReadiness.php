<?php

namespace App\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WhatsAppReadiness
{
    /** Local checks only. Never call Meta or return configuration values. */
    public function checks(): array
    {
        $config = config('clinic_messaging.whatsapp', []);
        $checks = [];
        $add = function (string $key, bool $passed, string $action) use (&$checks) {
            $checks[] = ['check'=>$key,'passed'=>$passed,'action'=>$passed ? 'Configured locally; live verification pending.' : $action];
        };
        $schema = Schema::hasColumns('message_entries',['provider_message_id','provider_reference','provider_received_at','template_data'])
            && Schema::hasColumn('message_threads','automation_paused')
            && Schema::hasTable('whatsapp_settings') && Schema::hasTable('whatsapp_routes') && Schema::hasTable('whatsapp_templates');
        $add('schema',$schema,'Run the pending database migrations.');
        foreach (['access_token'=>1,'app_secret'=>16,'verify_token'=>32] as $key=>$minimum) {
            $value=$config[$key] ?? null;
            $add($key,is_string($value) && strlen(trim($value)) >= $minimum,'Set WHATSAPP_'.strtoupper($key).' in the private environment.');
        }
        foreach (['phone_number_id','business_account_id','graph_version'] as $key) {
            $value=$config[$key] ?? null;
            $pattern=$key==='graph_version' ? '/^v[0-9]+\.[0-9]+$/D' : '/^[1-9][0-9]*$/D';
            $add($key,is_string($value) && (bool)preg_match($pattern,$value),'Set a valid WHATSAPP_'.strtoupper($key).' in the private environment.');
        }
        $url=parse_url(config('app.url',''));
        $add('public_https_url',is_array($url) && ($url['scheme'] ?? '')==='https' && !empty($url['host'])
            && !isset($url['user']) && !isset($url['pass']),'Set APP_URL to the public HTTPS origin.');
        $branch=$schema ? DB::table('whatsapp_settings')->where('id',1)->value('default_branch_id') : null;
        $add('reception_branch',$branch && DB::table('branches')->where('id',$branch)->exists(),'Select the reception branch in /staff/whatsapp.');
        foreach (CloudWhatsApp::KINDS as $kind=>$label) {
            $template=$schema ? DB::table('whatsapp_templates')->where('kind',$kind)->first() : null;
            $parameters=$template ? json_decode($template->parameters,true) : null;
            $valid=$template && $template->approved && strlen($template->name)<=255
                && preg_match('/^[a-z0-9_]+$/D',$template->name)
                && preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$template->language)
                && is_array($parameters) && array_is_list($parameters) && count($parameters)<=10;
            if ($valid) foreach ($parameters as $parameter) {
                if (!is_string($parameter) || !in_array($parameter,CloudWhatsApp::PARAMETERS[$kind],true)) $valid=false;
            }
            $add('template_'.$kind,(bool)$valid,'Configure and enable the Meta-approved '.$kind.' text-body template in /staff/whatsapp.');
        }
        return $checks;
    }
}
