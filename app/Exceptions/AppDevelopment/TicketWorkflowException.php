<?php

declare(strict_types=1);

namespace App\Exceptions\AppDevelopment;

use RuntimeException;

class TicketWorkflowException extends RuntimeException
{
    public static function denied(string $message): self
    {
        return new self($message, 403);
    }

    public static function invalid(string $message): self
    {
        return new self($message, 422);
    }

    public static function conflict(string $message): self
    {
        return new self($message, 409);
    }
}
