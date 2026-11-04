<?php

return [
    'inbound_token' => env('SLEEKFLOW_INBOUND_TOKEN'),
    'flow_url' => env('SLEEKFLOW_FLOW_URL'),
    'flow_headers' => json_decode(env('SLEEKFLOW_FLOW_HEADERS', '{}'), true),
    'email_enabled' => env('CLINIC_EMAIL_ENABLED', false),
];
