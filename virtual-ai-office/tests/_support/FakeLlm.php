<?php

namespace Tests\Support;

use App\Libraries\AI\LlmProvider;
use App\Libraries\AI\LlmResponse;

/** Scripted stand-in for Claude: $respond(request, callIndex) returns an LlmResponse or throws. */
final class FakeLlm implements LlmProvider
{
    public array $requests = [];

    /** @var callable */
    public $respond;

    public function __construct(?callable $respond = null)
    {
        $this->respond = $respond ?? static fn (array $r) => self::text('Hasil kerja untuk: ' . $r['messages'][0]['content']);
    }

    public function complete(array $request): LlmResponse
    {
        $this->requests[] = $request;
        $r = ($this->respond)($request, count($this->requests) - 1);

        // Mirror the real provider: raw assistant content + the messages that were sent.
        $raw = $r->rawContent ?: array_values(array_filter([
            $r->text !== '' ? ['type' => 'text', 'text' => $r->text] : null,
            ...array_map(static fn ($c) => ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => $c['input']], $r->toolCalls),
        ]));

        return new LlmResponse($r->text, $r->toolCalls, $r->stopReason, $r->inputTokens, $r->outputTokens, $r->model, $r->refusalCategory, $r->citations, $raw, $request['messages']);
    }

    public static function tool(string $name, array $input, string $id = 'toolu_x', string $text = ''): LlmResponse
    {
        return new LlmResponse($text, [['id' => $id, 'name' => $name, 'input' => $input]], 'tool_use', 200, 40, 'claude-opus-5-5');
    }

    public static function text(string $text, string $stop = 'end_turn', int $in = 120, int $out = 80): LlmResponse
    {
        return new LlmResponse($text, [], $stop, $in, $out, 'claude-opus-5-5');
    }

    public static function delegate(array $tasks, string $text = ''): LlmResponse
    {
        return new LlmResponse($text, [['id' => 'toolu_1', 'name' => 'delegate_tasks', 'input' => ['tasks' => $tasks]]], 'tool_use', 300, 150, 'claude-opus-5-5');
    }
}
