<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class SweetsStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        // TODO: Implement Sweets Store AI workflow (image analysis + text)
        return [
            'success' => true,
            'message' => 'Sweets store processed (workflow not yet implemented)',
            'data' => $payload,
        ];
    }
}
