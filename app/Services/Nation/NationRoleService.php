<?php

namespace App\Services\Nation;

use App\Models\NationMembership;

final class NationRoleService
{
    private const PERMISSIONS = [
        'manage_members' => ['ruler'],
        'manage_roles' => ['ruler'],
        'manage_profile' => ['ruler'],
        'manage_recruitment' => ['ruler'],
        'manage_emblem' => ['ruler'],
        'transfer_rulership' => ['ruler'],
        'dissolve_nation' => ['ruler'],
        'declare_war' => ['ruler', 'chancellor'],
        'allocate_war_resources' => ['ruler', 'chancellor', 'logistics_officer'],
        'upgrade_facilities' => ['ruler', 'chancellor', 'logistics_officer'],
        'repair_facilities' => ['ruler', 'chancellor', 'logistics_officer'],
        'set_war_operations' => ['ruler', 'chancellor', 'marshal', 'logistics_officer'],
        'manage_nation_goals' => ['ruler', 'chancellor', 'logistics_officer'],
        'manage_wanted_materials' => ['ruler', 'chancellor', 'logistics_officer'],
        'manage_decorations' => ['ruler'],
        'manage_showcase' => ['ruler'],
        'manage_war_presets' => ['ruler', 'chancellor', 'logistics_officer'],
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
