<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class IngredientsImagesStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        // TODO: Implement Ingredients Images Store AI workflow (image analysis + text)
        return [
            'success' => true,
            'message' => 'Ingredients images store processed (workflow not yet implemented)',
            'data' => $payload,
        ];
    }
}
