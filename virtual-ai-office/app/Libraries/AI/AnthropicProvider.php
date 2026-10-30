<?php

namespace App\Libraries\AI;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Config\Ai;

/**
 * Claude via the official Anthropic PHP SDK (anthropic-ai/sdk).
 *
 * - Adaptive thinking is the default on current models, so `thinking` is omitted; depth is
 *   controlled with output_config.effort (set explicitly: Claude Opus 5.5 defaults to medium).
 * - Forced tool_choice is not supported on current models: tools are offered with `auto`,
 *   `strict: true` schemas, and the system prompt says when to use them.
 * - Server-side refusal fallback (beta) is enabled for models that support it.
 */
class AnthropicProvider implements LlmProvider
{
    /** Models that accept `fallbacks: "default"` (server-side refusal fallback). */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    private Client $client;

    public function __construct(private readonly Ai $config)
    {
        $this->client = new Client(
            apiKey: $config->anthropicApiKey,
            baseUrl: $config->baseUrl(),
            requestOptions: ['timeout' => $config->timeout, 'maxRetries' => $config->sdkMaxRetries],
        );
    }

    public function complete(array $request): LlmResponse
    {
        if (! $this->config->isConfigured()) {
            throw new LlmException('AI provider belum dikonfigurasi (ai.anthropicApiKey di .env).');
        }

        $params = [
            'model'        => $request['model'],
            'maxTokens'    => $request['max_tokens'] ?? $this->config->maxTokens,
            'system'       => $request['system'],
            'messages'     => $this->withDocuments($request['messages'], $request['documents'] ?? []),
            'outputConfig' => ['effort' => $request['effort'] ?? 'medium'],
        ];
        if (! empty($request['tools'])) {
            $params['tools']      = $request['tools'];
            $params['toolChoice'] = ['type' => 'auto'];
        }
        $betas = [];
        if ($this->config->refusalFallback && in_array($request['model'], self::FALLBACK_MODELS, true)) {
            $params['fallbacks'] = 'default';
            $betas[]             = 'server-side-fallback-2026-07-01';
        }

        try {
            $message = $this->client->beta->messages->create(...$params, betas: $betas ?: null);
        } catch (RateLimitException $e) {
            throw new LlmException('Batas laju AI tercapai, akan dicoba lagi.', true, $e);
        } catch (InternalServerException $e) {
            throw new LlmException('Layanan AI sedang sibuk/gangguan, akan dicoba lagi.', true, $e);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw new LlmException('Kredensial AI ditolak. Periksa ai.anthropicApiKey.', false, $e);
        } catch (NotFoundException $e) {
            throw new LlmException("Model {$request['model']} tidak tersedia untuk akun ini.", false, $e);
        } catch (BadRequestException $e) {
            throw new LlmException('Permintaan AI tidak valid: ' . $e->getMessage(), false, $e);
        } catch (APIStatusException $e) {
            throw new LlmException('AI API error: ' . $e->getMessage(), false, $e);
        } catch (APIConnectionException $e) {
            throw new LlmException('Tidak dapat terhubung ke AI API, akan dicoba lagi.', true, $e);
        }

        $text      = '';
        $calls     = [];
        $citations = [];
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
                foreach ($block->citations ?? [] as $c) {
                    if ($c->type === 'content_block_location') {
                        $citations[] = ['document_index' => $c->documentIndex, 'start_block_index' => $c->startBlockIndex,
                            'end_block_index' => $c->endBlockIndex, 'cited_text' => $c->citedText];
                    }
                }
            } elseif ($block->type === 'tool_use') {
                // input arrives parsed; never string-match the serialized JSON
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        return new LlmResponse(
            text: trim($text),
            toolCalls: $calls,
            stopReason: (string) $message->stopReason,
            inputTokens: (int) $message->usage->inputTokens + (int) ($message->usage->cacheReadInputTokens ?? 0) + (int) ($message->usage->cacheCreationInputTokens ?? 0),
            outputTokens: (int) $message->usage->outputTokens,
            model: (string) $message->model,
            refusalCategory: $message->stopReason === 'refusal' ? ($message->stopDetails->category ?? null) : null,
            citations: $citations,
        );
    }

    /**
     * Knowledge documents go first in the first user turn as custom-content documents: one
     * text block per chunk, so a citation's block index maps straight back to a chunk.
     * A stable, cacheable document gets a cache breakpoint (whole small knowledge bases).
     */
    private function withDocuments(array $messages, array $documents): array
    {
        if ($documents === []) {
            return $messages;
        }
        $blocks = [];
        foreach ($documents as $doc) {
            $block = [
                'type'      => 'document',
                'source'    => ['type' => 'content', 'content' => array_map(static fn ($t) => ['type' => 'text', 'text' => $t], $doc['chunks'])],
                'title'     => $doc['title'],
                'citations' => ['enabled' => true],
            ];
            if (! empty($doc['context'])) {
                $block['context'] = $doc['context'];
            }
            if (! empty($doc['cache'])) {
                $block['cacheControl'] = ['type' => 'ephemeral'];
            }
            $blocks[] = $block;
        }
        $first             = $messages[0];
        $messages[0]       = ['role' => $first['role'], 'content' => [...$blocks, ['type' => 'text', 'text' => $first['content']]]];

        return $messages;
    }
}
