<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class SandwichesStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        // TODO: Implement Sandwiches Store AI workflow (image analysis + text)
        return [
            'success' => true,
            'message' => 'Sandwiches store processed (workflow not yet implemented)',
            'data' => $payload,
        ];
    }
}
