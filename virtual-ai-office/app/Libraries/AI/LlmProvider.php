<?php

namespace App\Libraries\AI;

/**
 * One request/response round trip with a language model. Kept tiny on purpose so the
 * orchestration logic can be tested with a fake provider and no network access.
 */
interface LlmProvider
{
    /**
     * @param array{
     *   model: string,
     *   system: string,
     *   messages: list<array{role: string, content: string}>,
     *   tools?: list<array>,
     *   documents?: list<array{title: string, context?: string, chunks: list<string>, cache?: bool}>,
     *   effort?: string,
     *   max_tokens?: int
     * } $request
     */
    public function complete(array $request): LlmResponse;
}
