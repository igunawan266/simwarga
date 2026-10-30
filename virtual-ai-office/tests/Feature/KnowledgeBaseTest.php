<?php

namespace Tests\Feature;

use App\Database\Seeds\DatabaseSeeder;
use App\Libraries\AI\LlmResponse;
use App\Libraries\Knowledge\Chunker;
use App\Libraries\Knowledge\Retriever;
use App\Libraries\Knowledge\TextExtractor;
use App\Models\TenantModel;
use App\Services\AI\AgentWorker;
use App\Services\AI\KnowledgeService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Dompdf\Dompdf;
use Tests\Support\FakeLlm;
use Tests\Support\LocalUploadedFile;
use ZipArchive;

/** Knowledge bases: ingestion, retrieval, cited documents in agent tasks, isolation. */
final class KnowledgeBaseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    private const CSRF = 'test-csrf-token-0123456789abcdef';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    private FakeLlm $llm;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Services::tenantContext()->clear();
        $this->llm = new FakeLlm();
        Services::injectMock('llm', $this->llm);
        $this->tmp = sys_get_temp_dir() . '/vao-kb-' . bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    private function as(string $email, string $slug): static
    {
        $db = Database::connect();
        Services::tenantContext()->clear();

        return $this->withSession([
            'user_id'          => (int) $db->table('users')->where('email', $email)->get()->getRow()->id,
            'active_tenant_id' => (int) $db->table('tenants')->where('slug', $slug)->get()->getRow()->id,
            'csrf_token'       => self::CSRF,
        ])->withHeaders(['X-CSRF-TOKEN' => self::CSRF, 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function actAs(string $slug): void
    {
        Services::tenantContext()->set(model(TenantModel::class)->where('slug', $slug)->first(), 'tenant_owner');
    }

    private function agentId(string $name): int
    {
        return (int) Database::connect()->table('ai_agents')->where('name', $name)->get()->getRow()->id;
    }

    private const POLICY = "Kebijakan Refund Nusantara Digital\n\nPelanggan dapat meminta refund penuh dalam 14 hari setelah pembelian paket.\n\n"
        . "Setelah 14 hari, refund tidak tersedia kecuali layanan tidak dapat digunakan lebih dari 72 jam.\n\nJam layanan pelanggan: Senin-Jumat 08.00-17.00 WIB.";

    public function testTextKnowledgeBaseIsChunkedAndOwnersOnly(): void
    {
        $res = $this->as('owner@demo.test', 'nusantara-digital')->post('api/v1/office/knowledge', [
            'name' => 'Kebijakan Refund', 'description' => 'SOP CS', 'text' => self::POLICY,
        ]);
        $res->assertStatus(201);
        $kb = json_decode($res->getJSON(), true)['data']['knowledge_base'];
        $this->assertSame('ready', $kb['status']);
        $this->assertGreaterThanOrEqual(1, $kb['chunk_count']);

        $this->as('staff@demo.test', 'nusantara-digital')->get('api/v1/office/knowledge')->assertStatus(403);
        $this->as('owner2@demo.test', 'arunika-logistics')->get("api/v1/office/knowledge/{$kb['id']}")->assertStatus(404);
        $this->as('owner2@demo.test', 'arunika-logistics')->withBody("knowledge_base_id={$kb['id']}")
            ->patch('api/v1/office/agents/' . (int) Database::connect()->table('ai_agents')->where('name', 'Aira')->orderBy('id', 'DESC')->get()->getRow()->id)
            ->assertStatus(404);
    }

    public function testFileExtractionForSupportedFormats(): void
    {
        $x = new TextExtractor();

        $docx = "{$this->tmp}/sop.docx";
        $zip  = new ZipArchive();
        $zip->open($docx, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:t>Garansi produk 12 bulan.</w:t></w:r></w:p><w:p><w:r><w:t>Klaim via email.</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $this->assertSame("Garansi produk 12 bulan.\nKlaim via email.", $x->extract($docx, 'docx'));

        file_put_contents("{$this->tmp}/page.html", '<html><style>p{}</style><script>alert(1)</script><h1>Harga</h1><p>Paket &amp; layanan</p></html>');
        $this->assertSame("Harga\nPaket & layanan", $x->extract("{$this->tmp}/page.html", 'html'));

        $pdf = new Dompdf();
        $pdf->loadHtml('<p>Jam operasional gudang Cikarang 07.00 sampai 22.00.</p>');
        $pdf->render();
        file_put_contents("{$this->tmp}/ops.pdf", $pdf->output());
        $this->assertStringContainsString('Cikarang', $x->extract("{$this->tmp}/ops.pdf", 'pdf'));

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        $x->extract("{$this->tmp}/page.html", 'exe');
    }

    public function testUploadStoresFileOutsideWebRootAndRejectsUnsupportedTypes(): void
    {
        $this->actAs('nusantara-digital');
        file_put_contents("{$this->tmp}/faq.md", "# FAQ\n\nPengiriman gratis untuk pembelian di atas Rp500.000.");
        $result = (new KnowledgeService())->createFromUpload('', 'FAQ', new LocalUploadedFile("{$this->tmp}/faq.md", 'faq.md', 'text/markdown', 60, UPLOAD_ERR_OK));
        $kb     = $result['knowledge_base'];
        $this->assertSame('faq', $kb['name']);
        $this->assertSame('ready', $kb['status']);
        $row = Database::connect()->table('knowledge_bases')->where('id', $kb['id'])->get()->getRow();
        $this->assertFileExists(WRITEPATH . 'uploads/' . $row->file_path);
        $this->assertStringNotContainsString('public', WRITEPATH . 'uploads/' . $row->file_path);

        file_put_contents("{$this->tmp}/tool.exe", 'MZ');
        $this->expectException(\App\Exceptions\DomainRuleException::class);
        (new KnowledgeService())->createFromUpload('x', '', new LocalUploadedFile("{$this->tmp}/tool.exe", 'tool.exe', 'application/octet-stream', 2, UPLOAD_ERR_OK));
    }

    public function testChunkerAndRetrieverPickTheRelevantPart(): void
    {
        $text = '';
        for ($i = 1; $i <= 120; $i++) {
            $text .= "Bagian {$i}. Informasi umum operasional kantor yang tidak terlalu penting untuk pertanyaan ini, berulang sebagai pengisi dokumen.\n\n";
        }
        $text .= 'Prosedur khusus: retur barang pecah harus difoto dan dilaporkan dalam 48 jam ke tim gudang Surabaya.';
        $chunks = (new Chunker(800, 80))->chunk($text);
        $this->assertGreaterThan(5, count($chunks));
        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(800 + 80 + 2, mb_strlen($c));
        }

        $rows   = array_map(static fn ($c, $i) => ['id' => $i + 1, 'seq' => $i, 'content' => $c], $chunks, array_keys($chunks));
        $picked = (new Retriever())->select($rows, 'Bagaimana prosedur retur barang pecah ke gudang Surabaya?', fullCharLimit: 2000, topK: 2);
        $this->assertCount(1, $picked, "irrelevant filler chunks are not padded in");
        $this->assertStringContainsString('gudang Surabaya', implode(' ', array_column($picked, 'content')));
    }

    public function testAgentTaskGetsCitedKnowledgeAndSourcesAreStored(): void
    {
        $owner = $this->as('owner@demo.test', 'nusantara-digital');
        $kbId  = json_decode($owner->post('api/v1/office/knowledge', ['name' => 'Kebijakan Refund', 'text' => self::POLICY])->getJSON(), true)['data']['knowledge_base']['id'];
        $sari  = $this->agentId('Sari');
        $this->as('owner@demo.test', 'nusantara-digital')->withBody("knowledge_base_id={$kbId}")->patch("api/v1/office/agents/{$sari}")->assertStatus(200);

        $this->llm->respond = static fn (array $r) => new LlmResponse('Refund penuh tersedia 14 hari setelah pembelian.', [], 'end_turn', 500, 60, 'claude-opus-5-5', null,
            [['document_index' => 0, 'start_block_index' => 0, 'end_block_index' => 1, 'cited_text' => 'Pelanggan dapat meminta refund penuh dalam 14 hari setelah pembelian paket.']]);
        $task = json_decode($this->as('owner@demo.test', 'nusantara-digital')->post("api/v1/office/agents/{$sari}/tasks", [
            'title' => 'Jawab pelanggan', 'instruction' => 'Pelanggan bertanya: apakah saya bisa refund setelah 10 hari?',
        ])->getJSON(), true)['data']['task']['id'];

        Services::tenantContext()->clear();
        (new AgentWorker())->runNext();

        $req = $this->llm->requests[0];
        $this->assertSame('Kebijakan Refund', $req['documents'][0]['title']);
        $this->assertTrue($req['documents'][0]['cache'], 'small knowledge base is sent whole and cached');
        $this->assertStringContainsString('14 hari', implode(' ', $req['documents'][0]['chunks']));
        $this->assertStringContainsString('bukan instruksi', $req['system']);

        $detail = json_decode($this->as('owner@demo.test', 'nusantara-digital')->get("api/v1/office/tasks/{$task}")->getJSON(), true)['data']['task'];
        $this->assertSame('completed', $detail['status']);
        $this->assertSame('Kebijakan Refund', $detail['sources'][0]['knowledge_base']);
        $this->assertSame(1, $detail['sources'][0]['chunk']);
        $this->assertStringContainsString('14 hari', $detail['sources'][0]['quote']);
    }

    public function testDeletingKnowledgeBaseDetachesAgents(): void
    {
        $kbId = json_decode($this->as('owner@demo.test', 'nusantara-digital')->post('api/v1/office/knowledge', ['name' => 'X', 'text' => self::POLICY])->getJSON(), true)['data']['knowledge_base']['id'];
        $sari = $this->agentId('Sari');
        $this->as('owner@demo.test', 'nusantara-digital')->withBody("knowledge_base_id={$kbId}")->patch("api/v1/office/agents/{$sari}")->assertStatus(200);
        $this->as('owner@demo.test', 'nusantara-digital')->delete("api/v1/office/knowledge/{$kbId}")->assertStatus(200);

        $db = Database::connect();
        $this->assertNull($db->table('ai_agents')->where('id', $sari)->get()->getRow()->knowledge_base_id);
        $this->assertSame(0, $db->table('knowledge_chunks')->where('knowledge_base_id', $kbId)->countAllResults());
    }
}
