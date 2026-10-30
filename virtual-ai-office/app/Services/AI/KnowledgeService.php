<?php

namespace App\Services\AI;

use App\Exceptions\DomainRuleException;
use App\Libraries\Knowledge\Chunker;
use App\Libraries\Knowledge\TextExtractor;
use App\Models\AiAgentModel;
use App\Models\KnowledgeBaseModel;
use App\Models\KnowledgeChunkModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Database;
use Config\Services;
use Throwable;

/**
 * Tenant knowledge bases (TenantContext scoped). Text is extracted once on upload, split
 * into chunks and stored; agents linked to a knowledge base receive the relevant chunks
 * as a cited document with each task.
 */
class KnowledgeService
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024;
    public const MAX_CHARS      = 2_000_000;
    public const MAX_PER_TENANT = 50;

    public function list(): array
    {
        $rows  = model(KnowledgeBaseModel::class)->select('id, name, description, source_type, status, chunk_count, char_count, original_filename, size_bytes, error_message, created_at')
            ->orderBy('id', 'DESC')->findAll();
        $usage = [];
        foreach (model(AiAgentModel::class)->select('id, name, knowledge_base_id')->where('knowledge_base_id IS NOT NULL', null, false)->findAll() as $a) {
            $usage[(int) $a['knowledge_base_id']][] = $a['name'];
        }

        return array_map(fn (array $kb) => $this->present($kb) + ['agents' => $usage[(int) $kb['id']] ?? []], $rows);
    }

    public function detail(int $id): array
    {
        $kb     = $this->find($id);
        $chunks = model(KnowledgeChunkModel::class)->select('id, seq, content, char_count')
            ->where('knowledge_base_id', $id)->orderBy('seq')->findAll(5);
        $agents = model(AiAgentModel::class)->select('id, name, job_title')->where('knowledge_base_id', $id)->findAll();

        return [
            'knowledge_base' => $this->present($kb),
            'preview'        => array_map(static fn ($c) => ['seq' => (int) $c['seq'], 'text' => mb_substr($c['content'], 0, 600)], $chunks),
            'agents'         => $agents,
        ];
    }

    public function createFromText(string $name, string $description, string $text): array
    {
        $this->assertCapacity();
        $text = (new TextExtractor())->normalize($text);
        $id   = model(KnowledgeBaseModel::class)->insert([
            'name' => $name, 'description' => $description, 'source_type' => 'text', 'status' => 'indexing', 'size_bytes' => strlen($text),
        ], true);
        $this->ingest($id, $text);
        Services::auditLogger()->record('knowledge.create', ['entity_type' => 'knowledge_base', 'entity_id' => $id, 'new' => ['name' => $name, 'source' => 'text']]);

        return $this->detail($id);
    }

    public function createFromUpload(string $name, string $description, UploadedFile $file): array
    {
        $this->assertCapacity();
        if (! $file->isValid() || $file->hasMoved()) {
            throw new DomainRuleException('Unggahan gagal: ' . $file->getErrorString());
        }
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            throw new DomainRuleException('Ukuran file maksimal 10 MB.');
        }
        $ext = strtolower($file->getClientExtension());
        if (! in_array($ext, TextExtractor::EXTENSIONS, true)) {
            throw new DomainRuleException('Format file tidak didukung. Gunakan: ' . implode(', ', TextExtractor::EXTENSIONS) . '.');
        }

        // Stored outside the public web root, under a random name.
        $tenantId = Services::tenantContext()->id();
        $dir      = WRITEPATH . 'uploads/knowledge/' . $tenantId;
        $stored   = $file->getRandomName();
        $file->move($dir, $stored);
        $path = $dir . '/' . $stored;

        $id = model(KnowledgeBaseModel::class)->insert([
            'name' => $name ?: pathinfo($file->getClientName(), PATHINFO_FILENAME), 'description' => $description,
            'source_type' => 'file', 'status' => 'indexing', 'file_path' => 'knowledge/' . $tenantId . '/' . $stored,
            'original_filename' => mb_substr($file->getClientName(), 0, 255), 'mime_type' => $file->getClientMimeType(), 'size_bytes' => (int) filesize($path),
        ], true);

        try {
            $text = (new TextExtractor())->extract($path, $ext);
            $this->ingest($id, $text);
        } catch (DomainRuleException $e) {
            model(KnowledgeBaseModel::class)->update($id, ['status' => 'error', 'error_message' => mb_substr($e->getMessage(), 0, 255)]);
        } catch (Throwable $e) {
            log_message('error', 'Knowledge ingest {id} failed: {msg}', ['id' => $id, 'msg' => $e->getMessage()]);
            model(KnowledgeBaseModel::class)->update($id, ['status' => 'error', 'error_message' => 'Dokumen tidak dapat diproses.']);
        }
        Services::auditLogger()->record('knowledge.create', ['entity_type' => 'knowledge_base', 'entity_id' => $id, 'new' => ['name' => $name, 'file' => $file->getClientName()]]);

        return $this->detail($id);
    }

    public function delete(int $id): void
    {
        $kb = $this->find($id);
        $db = Database::connect();
        $db->transException(true)->transStart();
        model(AiAgentModel::class)->where('knowledge_base_id', $id)->set(['knowledge_base_id' => null])->update();
        model(KnowledgeChunkModel::class)->where('knowledge_base_id', $id)->delete();
        model(KnowledgeBaseModel::class)->delete($id);
        $db->transComplete();
        if ($kb['file_path'] && is_file(WRITEPATH . 'uploads/' . $kb['file_path'])) {
            @unlink(WRITEPATH . 'uploads/' . $kb['file_path']);
        }
        Services::auditLogger()->record('knowledge.delete', ['entity_type' => 'knowledge_base', 'entity_id' => $id, 'old' => ['name' => $kb['name']]]);
    }

    public function find(int $id): array
    {
        return model(KnowledgeBaseModel::class)->find($id) ?? throw new DomainRuleException('Knowledge base tidak ditemukan.', 404);
    }

    /** Ready chunks of a knowledge base, for the worker (TenantContext already set). */
    public function chunksFor(int $id): array
    {
        $kb = model(KnowledgeBaseModel::class)->find($id);
        if ($kb === null || $kb['status'] !== 'ready') {
            return [];
        }

        return array_map(static fn ($c) => ['id' => (int) $c['id'], 'seq' => (int) $c['seq'], 'content' => $c['content']],
            model(KnowledgeChunkModel::class)->select('id, seq, content')->where('knowledge_base_id', $id)->orderBy('seq')->findAll());
    }

    private function ingest(int $id, string $text): void
    {
        if ($text === '') {
            model(KnowledgeBaseModel::class)->update($id, ['status' => 'error', 'error_message' => 'Dokumen tidak berisi teks.']);

            return;
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            model(KnowledgeBaseModel::class)->update($id, ['status' => 'error', 'error_message' => 'Dokumen terlalu besar (maksimal 2 juta karakter).']);

            return;
        }
        $chunks = (new Chunker())->chunk($text);
        $db     = Database::connect();
        $db->transException(true)->transStart();
        model(KnowledgeChunkModel::class)->where('knowledge_base_id', $id)->delete();
        $rows = [];
        foreach ($chunks as $i => $chunk) {
            $rows[] = ['knowledge_base_id' => $id, 'seq' => $i, 'content' => $chunk, 'char_count' => mb_strlen($chunk), 'created_at' => date('Y-m-d H:i:s')];
        }
        foreach (array_chunk($rows, 200) as $batch) {
            model(KnowledgeChunkModel::class)->insertBatch($batch);
        }
        model(KnowledgeBaseModel::class)->update($id, [
            'status' => 'ready', 'chunk_count' => count($chunks), 'char_count' => mb_strlen($text), 'error_message' => null,
        ]);
        $db->transComplete();
    }

    private function assertCapacity(): void
    {
        if (model(KnowledgeBaseModel::class)->countAllResults() >= self::MAX_PER_TENANT) {
            throw new DomainRuleException('Maksimal ' . self::MAX_PER_TENANT . ' knowledge base per tenant.');
        }
    }

    private function present(array $kb): array
    {
        return [
            'id' => (int) $kb['id'], 'name' => $kb['name'], 'description' => $kb['description'], 'source_type' => $kb['source_type'],
            'status' => $kb['status'], 'chunk_count' => (int) $kb['chunk_count'], 'char_count' => (int) $kb['char_count'],
            'original_filename' => $kb['original_filename'], 'size_bytes' => (int) $kb['size_bytes'], 'error_message' => $kb['error_message'],
            'created_at' => $kb['created_at'],
        ];
    }
}
