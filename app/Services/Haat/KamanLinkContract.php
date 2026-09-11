<?php

declare(strict_types=1);

namespace App\Services\Haat;

final class KamanLinkContract
{
    public function __construct(
        public string $endpoint,
        public string $method,
        public string $path,
        public string $shape,
        public array $fields,
        public bool $asJson = false,
        public bool $methodSpoof = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'path' => $this->path,
            'shape' => $this->shape,
            'fields' => $this->fields,
            'as_json' => $this->asJson,
            'method_spoof' => $this->methodSpoof,
        ];
    }
}
