<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

/**
 * The request may have reached Tranzila, but no usable answer came back (network failure, 5xx or an
 * unreadable body). The charge or document may exist, so it must be checked before anyone tries again.
 */
class TranzilaOutcomeUnknown extends TranzilaException {}
