<?php

namespace App\Services\Flows\Builder;

final readonly class CompiledFlow
{
    /**
     * @param  array<string, mixed>  $flowJson
     * @param  list<array{response_key: string, target: string}>  $fieldMappings
     */
    public function __construct(
        public array $flowJson,
        public array $fieldMappings,
    ) {}
}
