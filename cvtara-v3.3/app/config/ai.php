<?php

/*
 * AI service settings (3.38.0). Every call to Claude goes through App\Services\AiGateway, which reads this file.
 * The owner's model choice in Owner console > Settings > Integrations > AI (and Tara's own model setting) wins over
 * 'default_model'; nothing else in the code names a model.
 */
return [
    'default_model' => env('AI_DEFAULT_MODEL', 'claude-opus-5-5'),

    // Per assistant: how long one request may take (seconds) and how many times a busy/failed connection is retried.
    // Assistants not listed use 'default'. The key is the start of the usage "purpose" (exact purpose first, then the part before ':').
    'assistants' => [
        'default' => ['timeout' => (int) env('AI_TIMEOUT', 50), 'retries' => 0],
        'customer:tara_chat' => ['timeout' => 60, 'retries' => 1],  // customer chat
        'owner:tara' => ['timeout' => 60, 'retries' => 1],          // Business assistant
        'customer:ats_tailor' => ['timeout' => 150, 'retries' => 0],
        'seo' => ['timeout' => 40, 'retries' => 0],
    ],

    // AI health: a warning goes to the Error Log when, over the last 24 hours and at least 'min_calls' requests,
    // fewer than 'min_success_pct' percent succeeded.
    'health' => ['min_calls' => 20, 'min_success_pct' => 80],

    // 3.48.0: short routine tasks that may run on the owner's cheaper "quick-task model" (Integrations > AI). Customer
    // writing (reviews, tailoring, letters, chat answers) is not listed and always uses the main model.
    'quick_purposes' => ['seo', 'customer:chat_summary', 'customer:jd_parse', 'customer:interview_eval'],

    // List prices in US dollars per million tokens (input, output), used to cost each request by the model that answered.
    // A model not listed is costed with the owner's prices on the Growth page.
    'prices' => [
        'claude-opus-5-5' => [4.00, 20.00],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-haiku-5-5' => [0.10, 0.50],
    ],
];
