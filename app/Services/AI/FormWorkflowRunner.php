<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Contracts\FormWorkflowContract;
use App\Services\AI\Workflows\CategoryAndIngredientsStoreWorkflow;
use App\Services\AI\Workflows\CategoryAndMealStoreWorkflow;
use App\Services\AI\Workflows\CategoryIngredientsStoreWorkflow;
use App\Services\AI\Workflows\CategoryStoreWithAiImageWorkflow;
use App\Services\AI\Workflows\CategoryStoreWorkflow;
use App\Services\AI\Workflows\DrinksStoreWorkflow;
use App\Services\AI\Workflows\IngredientsStoreWorkflow;
use App\Services\AI\Workflows\MealStoreWithAiImagesWorkflow;
use App\Services\AI\Workflows\MealStoreWorkflow;
use Illuminate\Support\Facades\Log;

final class FormWorkflowRunner
{
    private const WORKFLOW_MAP = [
        'Meal Store' => MealStoreWorkflow::class,
        'Meal Store With AI Images' => MealStoreWithAiImagesWorkflow::class,
        'Category Store' => CategoryStoreWorkflow::class,
        'Category Store With AI Image' => CategoryStoreWithAiImageWorkflow::class,
        'Category Ingredients Store' => CategoryIngredientsStoreWorkflow::class,
        'Meal Store' => MealStoreWorkflow::class,
        'Meal Store With AI Images' => MealStoreWithAiImagesWorkflow::class,
        'Category and Meal Store' => CategoryAndMealStoreWorkflow::class,
        'Ingredients Store' => IngredientsStoreWorkflow::class,
        'Category and Ingredients Store' => CategoryAndIngredientsStoreWorkflow::class,
        'Drinks Store' => DrinksStoreWorkflow::class,
    ];

    /**
     * @return list<string>
     */
    public static function methodTypes(): array
    {
        return array_keys(self::WORKFLOW_MAP);
    }

    /**
     * @param  callable(string, string, array): void|null  $onProgress
     */
    public function run(string $methodType, array $payload, ?callable $onProgress = null): array
    {
        $workflowClass = self::WORKFLOW_MAP[$methodType] ?? null;

        if (! $workflowClass) {
            Log::warning('No workflow registered for method_type', ['method_type' => $methodType]);

            return [
                'success' => false,
                'error' => "No AI workflow registered for method type: {$methodType}",
            ];
        }

        $workflow = app($workflowClass);

        if (! $workflow instanceof FormWorkflowContract) {
            Log::error('Workflow does not implement FormWorkflowContract', [
                'workflow' => $workflowClass,
            ]);

            return [
                'success' => false,
                'error' => 'Invalid workflow implementation',
            ];
        }

        try {
            return $workflow->run($payload, $onProgress);
        } catch (\Throwable $e) {
            Log::error('Form workflow failed', [
                'method_type' => $methodType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
