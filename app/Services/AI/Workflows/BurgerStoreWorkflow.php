<?php

declare(strict_types=1);

namespace App\Services\AI\Workflows;

final class BurgerStoreWorkflow extends AbstractFormWorkflow
{
    public function run(array $payload, ?callable $onProgress = null): array
    {
        // TODO: Implement Burger Store AI workflow (image analysis + text)
        return [
            'success' => true,
            'message' => 'Burger store processed (workflow not yet implemented)',
            'data' => $payload,
        ];
    }
}
