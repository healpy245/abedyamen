<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class FormWorkflowPausedException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        string $message = 'Workflow paused.',
        public readonly array $report = [],
    ) {
        parent::__construct($message);
    }
}
