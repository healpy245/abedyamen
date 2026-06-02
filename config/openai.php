<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key and Organization
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API Key and organization. This will be
    | used to authenticate with the OpenAI API - you can find your API key
    | and organization on your OpenAI dashboard, at https://openai.com.
    */

    'api_key' => trim((string) (env('OPENAI_API_KEY') ?? '')),
    'organization' => env('OPENAI_ORGANIZATION'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Project
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API project. This is used optionally in
    | situations where you are using a legacy user API key and need association
    | with a project. This is not required for the newer API keys.
    */
    'project' => env('OPENAI_PROJECT'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI Base URL
    |--------------------------------------------------------------------------
    |
    | Here you may specify your OpenAI API base URL used to make requests. This
    | is needed if using a custom API endpoint. Defaults to: api.openai.com/v1
    */
    'base_uri' => env('OPENAI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Workflow max execution time (seconds)
    |--------------------------------------------------------------------------
    |
    | PHP script time limit for form workflows that call OpenAI (possibly many
    | times in one request). Override with OPENAI_WORKFLOW_MAX_EXECUTION_TIME.
    */
    'workflow_max_execution_time' => (int) env('OPENAI_WORKFLOW_MAX_EXECUTION_TIME', 1800),

    /*
    |--------------------------------------------------------------------------
    | OpenAI HTTP request timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | Max wait for each chat/completions response. Large menus need high values.
    | Override with OPENAI_REQUEST_TIMEOUT (default 600).
    */
    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 600),

    /** Max tokens per AI parse pass for Meal Store (large category blocks). */
    'meal_store_max_tokens' => (int) env('OPENAI_MEAL_STORE_MAX_TOKENS', 16384),

    /** Max tokens per AI parse pass for Ingredients Store (defaults to meal_store_max_tokens). */
    'ingredients_store_max_tokens' => (int) env('OPENAI_INGREDIENTS_STORE_MAX_TOKENS', env('OPENAI_MEAL_STORE_MAX_TOKENS', 16384)),

    /*
    |--------------------------------------------------------------------------
    | SSL Verification
    |--------------------------------------------------------------------------
    |
    | Set to false to disable SSL certificate verification (local dev only).
    | Use when cURL error 60 occurs due to missing CA bundle.
    */
    'ssl_verify' => filter_var(env('OPENAI_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Default Models
    |--------------------------------------------------------------------------
    */
    'default_model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o-mini'),
    'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o'),
    'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
];
