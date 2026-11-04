<?php

use App\Messaging\Inbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/sleekflow', function (Request $request, Inbox $inbox) {
    $secret = config('clinic_messaging.inbound_token');
    abort_unless(is_string($secret) && strlen($secret) >= 32 && hash_equals($secret, (string) $request->bearerToken()), 401);
    $data = $request->validate([
        'message_id' => 'required|string|max:255', 'branch_id' => 'required|integer|exists:branches,id',
        'direction' => 'nullable|in:inbound,outbound', 'phone' => ['required','regex:/^\+[1-9][0-9]{7,14}$/D'], 'text' => 'required|string|max:4000',
    ]);
    return response()->json(['accepted' => true, 'route' => $inbox->receive($data)]);
})->middleware('throttle:240,1,messaging-webhook');
