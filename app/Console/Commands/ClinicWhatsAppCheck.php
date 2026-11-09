<?php

namespace App\Console\Commands;

use App\Messaging\WhatsAppReadiness;
use Illuminate\Console\Command;

class ClinicWhatsAppCheck extends Command
{
    protected $signature = 'clinic:whatsapp-check {--json : Print machine-readable results without secrets}';
    protected $description = 'Check local WhatsApp configuration without calling Meta or sending messages';

    public function handle(WhatsAppReadiness $readiness): int
    {
        $checks=$readiness->checks();
        $passed=count(array_filter($checks,fn ($check) => $check['passed']));
        $result=['passed'=>$passed,'total'=>count($checks),'live_verified'=>false,'checks'=>$checks];
        if ($this->option('json')) {
            $this->line(json_encode($result,JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check','Local result','Next action'],array_map(fn ($check) => [
                $check['check'],$check['passed'] ? 'PASS' : 'MISSING',$check['action'],
            ],$checks));
            $this->line("$passed/".count($checks).' local configuration checks passed. No Meta requests were made. Live verification is still required.');
        }
        return $passed===count($checks) ? self::SUCCESS : self::FAILURE;
    }
}
