<?php

namespace Database\Seeders;

use App\Models\ClinicalTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Removes every patient whose CRM profile carries the DemoDataSeeder tag, with their bookings,
 * encounters, receipts, herbal movements, follow-ups and outbox rows, plus the demo clinical templates
 * and the demo staff accounts with their HR records, the demo inbox rules and the scripted WhatsApp conversations.
 * Branches, services, practitioners and slots stay, because real records may share them.
 */
class DemoDataPurgeSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demonstration data currently requires a local or testing environment.');
        }
        $patients = DB::table('crm_profiles')->get()
            ->filter(fn ($row) => in_array(DemoDataSeeder::TAG, array_map('trim', explode(',', Crypt::decryptString($row->tags))), true))
            ->pluck('patient_id')->unique()->values();
        // A template is removed only when both its name and its full record match the demo content.
        $templates = ClinicalTemplate::all()->filter(function ($template) {
            $concern = DemoDataSeeder::templateNames()[$template->name] ?? null;

            return $concern && $template->clinical_record === DemoDataSeeder::templateRecord($concern);
        })->pluck('id');
        $phones = \App\Models\Patient::whereIn('id', $patients)->get()->pluck('phone')->merge(DemoDataSeeder::demoEnquiryPhones());
        $contacts = $phones->map(fn ($phone) => hash_hmac('sha256', $phone, config('app.key')))->unique()->values();
        $removed = DB::transaction(function () use ($patients, $templates, $contacts) {
            // An unlinked thread is removed only when every inbound message in it is a scripted demo message.
            $demoKeys = DemoDataSeeder::demoMessageKeys();
            $conversations = DB::table('message_threads')->whereNull('patient_id')->whereIn('contact_key', $contacts)->pluck('id')
                ->filter(function ($thread) use ($demoKeys) {
                    $inbound = DB::table('message_entries')->where('thread_id', $thread)->where('direction', 'inbound')->pluck('event_key');

                    return $inbound->isNotEmpty() && $inbound->diff($demoKeys)->isEmpty();
                });
            DB::table('message_threads')->whereIn('id', $conversations)->delete();
            foreach (DemoDataSeeder::demoRules() as [$keyword, $category, , $reply]) {
                DB::table('message_rules')->where(['keyword' => $keyword, 'category' => $category, 'reply' => $reply])->delete();
            }
            $appointments = DB::table('appointments')->whereIn('patient_id', $patients)->pluck('id');
            $events = DB::table('appointment_events')->whereIn('appointment_id', $appointments)->pluck('id');
            $encounters = DB::table('encounters')->whereIn('patient_id', $patients)->pluck('id');
            $receipts = DB::table('receipts')->whereIn('patient_id', $patients)->pluck('id');
            DB::table('message_projections')->where('source', 'appointment_events')->whereIn('source_id', $events)->delete();
            DB::table('message_projections')->where('source', 'encounters')->whereIn('source_id', $encounters)->delete();
            DB::table('message_projections')->where('source', 'receipts')->whereIn('source_id', $receipts)->delete();
            DB::table('receipts')->whereIn('id', $receipts)->delete();
            // Demo movements: dispenses linked to demo encounters, plus marked openings, deliveries and losses.
            $movements = DB::table('herbal_movements')->where(fn ($q) => $q->whereIn('encounter_id', $encounters)
                ->orWhere('note', 'like', '%'.DemoDataSeeder::MOVEMENT_MARK))->get(['id', 'stock_id', 'kind', 'milligrams']);
            foreach ($movements->groupBy('stock_id') as $stock => $rows) {
                $net = $rows->sum(fn ($m) => $m->kind === 'receive' ? (int) $m->milligrams : -(int) $m->milligrams);
                DB::table('herbal_stocks')->where('id', $stock)->decrement('milligrams', $net);
            }
            foreach ($movements->pluck('id')->chunk(500) as $chunk) {
                DB::table('herbal_movements')->whereIn('id', $chunk)->delete();
            }
            // A stock row is removed only when nothing else ever moved it and its balance is back to zero.
            $stocks = DB::table('herbal_stocks')->whereIn('id', $movements->pluck('stock_id')->unique())->where('milligrams', 0)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('herbal_movements as m')->whereColumn('m.stock_id', 'herbal_stocks.id'))
                ->delete();
            DB::table('message_threads')->whereIn('patient_id', $patients)->delete();
            DB::table('patient_followups')->whereIn('patient_id', $patients)->delete();
            DB::table('crm_profiles')->whereIn('patient_id', $patients)->delete();
            DB::table('encounters')->whereIn('id', $encounters)->delete();
            DB::table('appointments')->whereIn('id', $appointments)->delete();
            $templateCount = DB::table('clinical_templates')->whereIn('id', $templates)->delete();
            // Demo staff use the reserved .invalid domain, so no real account can match.
            $staff = DB::table('staff_accounts')->where('email', 'like', '%@'.DemoDataSeeder::STAFF_DOMAIN)->pluck('id');
            DB::table('hr_leave')->whereIn('reviewer_id', $staff)->whereNotIn('staff_id', $staff)->update(['reviewer_id' => null]);
            foreach (['hr_leave', 'hr_shifts', 'hr_documents', 'hr_history', 'hr_profiles', 'staff_access_log'] as $table) {
                DB::table($table)->whereIn('staff_id', $staff)->delete();
            }
            foreach (['message_threads', 'message_rules'] as $table) {
                DB::table($table)->whereIn('assignee_id', $staff)->update(['assignee_id' => null]);
            }
            // Routes and consents go only for demo contacts that no longer have any thread.
            $orphans = $contacts->filter(fn ($contact) => !DB::table('message_threads')->where('contact_key', $contact)->exists());
            DB::table('whatsapp_routes')->whereIn('contact_key', $orphans)->delete();
            DB::table('message_consents')->whereIn('contact_key', $orphans)->delete();

            return ['patients' => DB::table('patients')->whereIn('id', $patients)->delete(), 'appointments' => $appointments->count(),
                'encounters' => $encounters->count(), 'receipts' => $receipts->count(),
                'herbal_movements' => $movements->count(), 'herbal_stocks' => $stocks, 'templates' => $templateCount,
                'conversation_threads' => $conversations->count(), 'contacts' => $orphans->count(),
                'staff_accounts' => DB::table('staff_accounts')->whereIn('id', $staff)->delete()];
        });
        $this->command?->info(json_encode($removed));
    }
}
