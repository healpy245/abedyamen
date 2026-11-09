<?php

declare(strict_types=1);

namespace App\Enums\Tranzila;

enum DocumentRelation: int
{
    case ByNumber = 1;
    case ByDocumentId = 2;
}
