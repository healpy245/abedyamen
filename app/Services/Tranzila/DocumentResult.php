<?php

declare(strict_types=1);

namespace App\Services\Tranzila;

/**
 * The identifiers of a document Tranzila issued.
 */
final readonly class DocumentResult
{
    public function __construct(
        public ?string $id,
        public ?string $number,
        public ?string $assignmentNumber,
        public ?string $retrievalKey,
        public ?string $issuedAt,
    ) {}

    public static function fromResponse(TranzilaResponse $response): self
    {
        $document = is_array($response->data['document'] ?? null) ? $response->data['document'] : [];
        $text = fn (string $key): ?string => isset($document[$key]) && $document[$key] !== '' ? (string) $document[$key] : null;

        return new self($text('id'), $text('number'), $text('assignment_number'), $text('retrieval_key'), $text('created_at'));
    }
}
