<?php

declare(strict_types=1);

namespace App\Services\AI\Contracts;

interface FormWorkflowContract
{
    /**
     * Run the AI workflow for this form path.
     *
     * @param  array<string, mixed>  $payload  Validated form data (method_type, restaurant_name, password, description, etc.)
     * @param  callable(string, string, array): void|null  $onProgress  Optional callback (step, message, data) for live debugging
     * @return array{success: bool, message?: string, data?: array<string, mixed>, error?: string}
     */
    public function run(array $payload, ?callable $onProgress = null): array;
}
