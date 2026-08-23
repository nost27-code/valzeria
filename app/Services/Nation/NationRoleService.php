<?php

namespace App\Services\Nation;

use App\Models\NationMembership;

final class NationRoleService
{
    private const PERMISSIONS = [
        'manage_members' => ['king', 'chancellor'],
        'manage_roles' => ['king'],
        'declare_war' => ['king', 'chancellor'],
        'allocate_war_resources' => ['king', 'chancellor', 'logistics_officer'],
        'upgrade_facilities' => ['king', 'chancellor', 'logistics_officer'],
        'repair_facilities' => ['king', 'chancellor', 'logistics_officer'],
        'set_war_operations' => ['king', 'chancellor', 'marshal', 'logistics_officer'],
    ];

    public function allows(NationMembership $membership, string $permission): bool
    {
        return in_array($membership->role, self::PERMISSIONS[$permission] ?? [], true);
    }

    public function authorize(NationMembership $membership, string $permission): void
    {
        throw_unless($this->allows($membership, $permission), \DomainException::class, 'この操作を行う役職権限がありません。');
    }
}
