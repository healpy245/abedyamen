<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class PastaMealsStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        // TODO: Implement Pasta Meals Store AI workflow (image analysis + text)
        return [
            'success' => true,
            'message' => 'Pasta meals store processed (workflow not yet implemented)',
            'data' => $payload,
        ];
    }
}
