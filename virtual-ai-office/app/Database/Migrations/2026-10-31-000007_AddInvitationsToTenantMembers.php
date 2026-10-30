<?php

namespace App\Database\Migrations;

use App\Database\BaseMigration;

/**
 * Invitation support on tenant_members (status "invited").
 * Only a SHA-256 hash of the invite token is stored; the raw token exists solely in the link.
 */
class AddInvitationsToTenantMembers extends BaseMigration
{
    public function up(): void
    {
        $this->forge->addColumn('tenant_members', [
            'invited_by'        => $this->fk(true),
            'invite_token_hash' => $this->str(64, true),
            'invite_expires_at' => $this->datetime(),
        ]);
        $this->forge->addUniqueKey('invite_token_hash', 'tenant_members_invite_token_hash');
        $this->forge->addKey('invited_by', false, false, 'tenant_members_invited_by');
        $this->forge->processIndexes('tenant_members');
    }

    public function down(): void
    {
        $this->forge->dropKey('tenant_members', 'tenant_members_invite_token_hash', false);
        $this->forge->dropKey('tenant_members', 'tenant_members_invited_by', false);
        $this->forge->dropColumn('tenant_members', ['invited_by', 'invite_token_hash', 'invite_expires_at']);
    }
}
