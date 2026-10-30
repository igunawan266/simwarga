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

        return ($this->respond)($request, count($this->requests) - 1);
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
