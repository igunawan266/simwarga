<?php

namespace App\Libraries\AI;

/** Provider-neutral view of a model response. */
final class LlmResponse
{
    /**
     * @param list<array{id: string, name: string, input: array}> $toolCalls
     */
    public function __construct(
        public readonly string $text,
        public readonly array $toolCalls,
        public readonly string $stopReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $model,
        public readonly ?string $refusalCategory = null,
    ) {
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
