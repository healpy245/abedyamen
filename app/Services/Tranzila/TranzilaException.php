<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

use RuntimeException;

/**
 * Anything that stopped a Tranzila operation. Its message is safe to show to staff.
 */
class TranzilaException extends RuntimeException {}
