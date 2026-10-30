<?php

namespace Tests\Unit;

use App\Database\Seeds\DatabaseSeeder;
use App\Exceptions\TenantContextException;
use App\Models\AiAgentModel;
use App\Models\TenantModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;

final class TenantScopedModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = 'App';
    protected $seed      = DatabaseSeeder::class;

    private function actAs(string $slug): array
    {
        $tenant = model(TenantModel::class)->where('slug', $slug)->first();
        Services::tenantContext()->set($tenant, 'tenant_owner');

        return $tenant;
    }

    protected function tearDown(): void
    {
        Services::tenantContext()->clear();
        parent::tearDown();
    }

    public function testQueriesFailWithoutTenantContext(): void
    {
        Services::tenantContext()->clear();
        $this->expectException(TenantContextException::class);
        model(AiAgentModel::class)->findAll();
    }

    public function testReadsAndCountsAreScoped(): void
    {
        $this->actAs('sagara-retail');
        $agents = model(AiAgentModel::class)->findAll();

        $this->assertCount(2, $agents); // Starter plan demo tenant
        $this->assertSame(2, model(AiAgentModel::class)->countAllResults());
    }

    public function testFindByIdOfOtherTenantReturnsNull(): void
    {
        $nusantara  = $this->actAs('nusantara-digital');
        $ownAgentId = (int) model(AiAgentModel::class)->first()['id'];

        $this->actAs('sagara-retail');
        $this->assertNull(model(AiAgentModel::class)->find($ownAgentId));
        $this->assertNotSame($nusantara['id'], Services::tenantContext()->id());
    }

    public function testInsertIsStampedAndUpdateCannotMoveTenant(): void
    {
        $sagara = $this->actAs('sagara-retail');
        $model  = model(AiAgentModel::class);
        $id     = $model->insert(['name' => 'Test', 'job_title' => 'AI Tester', 'department' => 'QA', 'tenant_id' => 999]);
        $row    = $model->find($id);

        $this->assertSame((int) $sagara['id'], (int) $row['tenant_id']);

        $model->update($id, ['tenant_id' => 1, 'name' => 'Renamed']);
        $row = $model->find($id);
        $this->assertSame((int) $sagara['id'], (int) $row['tenant_id']);
        $this->assertSame('Renamed', $row['name']);
    }

    public function testUpdateAndDeleteOfOtherTenantRowsAffectNothing(): void
    {
        $this->actAs('nusantara-digital');
        $foreignId = (int) model(AiAgentModel::class)->first()['id'];

        $this->actAs('sagara-retail');
        model(AiAgentModel::class)->update($foreignId, ['name' => 'Hijacked']);
        model(AiAgentModel::class)->delete($foreignId);

        $this->actAs('nusantara-digital');
        $row = model(AiAgentModel::class)->find($foreignId);
        $this->assertNotNull($row);
        $this->assertNotSame('Hijacked', $row['name']);
    }
}
