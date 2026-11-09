<?php

namespace App\Http\Controllers;

use App\Messaging\CloudWhatsApp;
use App\Support\StaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WhatsAppSettingsController
{
    private function authorize(): void
    {
        abort_unless(StaffAccess::hq(),403);
    }

    public function index()
    {
        $this->authorize();
        return view('messaging.settings', [
            'branches'=>DB::table('branches')->orderBy('id')->get(),
            'defaultBranch'=>DB::table('whatsapp_settings')->where('id',1)->value('default_branch_id'),
            'templates'=>DB::table('whatsapp_templates')->get()->keyBy('kind'),
            'kinds'=>CloudWhatsApp::KINDS,'parameters'=>CloudWhatsApp::PARAMETERS,
            'configured'=>collect(config('clinic_messaging.whatsapp'))->map(fn ($value)=>(bool)$value),
        ]);
    }

    public function save(Request $request)
    {
        $this->authorize();
        $data = $request->validate(['default_branch_id'=>'nullable|integer|exists:branches,id']);
        DB::table('whatsapp_settings')->updateOrInsert(['id'=>1],['default_branch_id'=>$data['default_branch_id'] ?? null]);
        return back()->with('status','已更新新對話接待分店');
    }

    public function template(Request $request)
    {
        $this->authorize();
        $data = $request->validate([
            'kind'=>['required',Rule::in(array_keys(CloudWhatsApp::KINDS))],
            'name'=>['required','string','max:255','regex:/^[a-z0-9_]+$/D'],
            'language'=>['required','string','max:20','regex:/^[a-z]{2,3}(?:_[A-Z]{2})?$/D'],
            'parameters'=>'nullable|string|max:200','approved'=>'required|boolean',
        ]);
        $parameters = empty($data['parameters']) ? [] : array_map('trim',explode(',',$data['parameters']));
        abort_unless(count($parameters)<=10 && !array_diff($parameters,CloudWhatsApp::PARAMETERS[$data['kind']]),422,'Invalid template parameter binding.');
        DB::table('whatsapp_templates')->updateOrInsert(['kind'=>$data['kind']], [
            'name'=>$data['name'],'language'=>$data['language'],'parameters'=>json_encode($parameters),
            'approved'=>(bool)$data['approved'],'updated_at'=>now(),
        ]);
        return back()->with('status','已儲存範本對應；實際發送仍以 Meta 核准狀態為準');
    }
}
