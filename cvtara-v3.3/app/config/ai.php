<?php

/*
 * AI service settings (3.38.0). Every call to Claude goes through App\Services\AiGateway, which reads this file.
 * The owner's model choice in Owner console > Settings > Integrations > AI (and Tara's own model setting) wins over
 * 'default_model'; nothing else in the code names a model.
 */
return [
    // 3.64.0 cost tiers. Before this release every request used claude-opus-5-5 ($4 in / $20 out per million tokens),
    // so a handful of chats with tool steps cost about Rs 100. Now each kind of work runs on the cheapest model that
    // does it well. The owner can still type another model per tier in Integrations > AI.
    'default_model' => env('AI_DEFAULT_MODEL', 'claude-sonnet-5-5'),   // customer writing: reviews, tailoring, letters
    'chat_model' => env('AI_CHAT_MODEL', 'claude-haiku-5-5'),           // customer Tara chat (tool steps, short answers)
    'quick_model' => env('AI_QUICK_MODEL', 'claude-haiku-5-5'),         // SEO titles, summaries, job parsing

    // Thinking effort per kind of work. Thinking is billed as output tokens, so routine work runs at 'low'.
    'effort' => ['chat' => 'low', 'quick' => 'low', 'writing' => 'medium'],

    // Prompt caching: the fixed part of Tara's instructions and her tool list are cached for 5 minutes, so the 2nd to
    // 5th steps of one answer (and the next message within 5 minutes) read them at a tenth of the price or less.
    'cache' => (bool) env('AI_PROMPT_CACHE', true),

    // A tool result Tara reads is cut to this many characters (the customer guide has its own, larger limit).
    'tool_result_chars' => 6000,

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

    // 3.64.0: chat purposes run on the chat model (Tara for customers). The Business assistant stays on the writing model
    // because its answers are business numbers the owner acts on.
    'chat_purposes' => ['customer:tara_chat'],

    // List prices in US dollars per million tokens (input, output), used to cost each request by the model that answered.
    // A model not listed is costed with the owner's prices on the Growth page.
    // Cached input is billed separately: a cache write costs 1.25x the input price, a cache read the 'cache_read' price.
    'cache_read' => ['claude-opus-5-5' => 0.20, 'claude-sonnet-5-5' => 0.10, 'claude-haiku-5-5' => 0.01],
    'prices' => [
        'claude-opus-5-5' => [4.00, 20.00],
        'claude-sonnet-5-5' => [2.00, 10.00],
        'claude-haiku-5-5' => [0.10, 0.50],
    ],
];
