<?php

/*
|--------------------------------------------------------------------------
| AI assistant (Venice AI, OpenAI-compatible API)
|--------------------------------------------------------------------------
| The API key is entered by the administrator in Settings > AI and stored
| encrypted. VENICE_AI_API_KEY in .env is only used as a fallback.
*/

return [

    'base_url' => env('VENICE_AI_BASE_URL', 'https://api.venice.ai/api/v1'),

    'api_key' => env('VENICE_AI_API_KEY'),

    // Chat models with function calling support.
    'models' => [
        'deepseek-v4-1-flash' => 'DeepSeek V4.1 Flash',
        'deepseek-v4-flash' => 'DeepSeek V4 Flash',
        'qwen-3-8-flash' => 'Qwen 3.8 Flash',
        'openai-gpt-56-luna' => 'GPT-5.6 Luna',
        'openai-gpt-56-luna-pro' => 'GPT-5.6 Luna Pro',
    ],

    'default_model' => env('VENICE_AI_MODEL', 'deepseek-v4-1-flash'),

    // Used to read screenshots / images attached to a message.
    'vision_model' => env('VENICE_AI_VISION_MODEL', 'qwen3-vl-235b-a22b'),

    'reasoning_efforts' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'],

    // Maximum model <-> tool round trips for a single user message.
    'max_steps' => 25,

    'timeout' => 300,

    'max_images' => 4,

    'max_image_kb' => 8192,
];
