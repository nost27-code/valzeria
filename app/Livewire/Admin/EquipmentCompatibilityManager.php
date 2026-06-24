<?php

namespace App\Livewire\Admin;

use App\Models\JobClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class EquipmentCompatibilityManager extends Component
{
    public string $rankFilter = 'all';

    public string $search = '';

    private const RANK_LABELS = [
        'normal' => '一般',
        'middle' => '中級',
        'advanced' => '上級',
        'legend' => '伝説',
    ];

    private const DEFAULT_WEAPON_CATEGORIES = [
        ['key' => 'sword', 'name' => '剣', 'description' => '剣士・騎士系が扱う標準武器。', 'sort_order' => 10],
        ['key' => 'axe', 'name' => '斧', 'description' => '戦士・狂戦士系が扱う高威力武器。', 'sort_order' => 20],
        ['key' => 'dagger', 'name' => '短剣', 'description' => '盗賊・忍者系が扱う軽量武器。', 'sort_order' => 30],
        ['key' => 'bow', 'name' => '弓', 'description' => '弓使い・狙撃手系が扱う遠距離武器。', 'sort_order' => 40],
        ['key' => 'staff', 'name' => '杖', 'description' => '魔法職・僧侶職向けの魔法武器。', 'sort_order' => 50],
        ['key' => 'magic_device', 'name' => '魔導具', 'description' => '魔力を増幅する特殊装備。', 'sort_order' => 60],
        ['key' => 'gun', 'name' => '銃', 'description' => '機工系・狙撃系が扱う遠距離武器。', 'sort_order' => 70],
        ['key' => 'spear', 'name' => '槍', 'description' => '騎士・竜騎士系が扱う長柄武器。', 'sort_order' => 80],
        ['key' => 'fist', 'name' => '拳甲', 'description' => '格闘家・武神系が扱う近接武器。', 'sort_order' => 90],
        ['key' => 'katana', 'name' => '刀', 'description' => '侍・剣聖系が扱う技量武器。', 'sort_order' => 100],
    ];

    private const DEFAULT_ARMOR_CATEGORIES = [
        ['key' => 'clothes', 'name' => '服・旅装', 'description' => '軽く扱いやすい基本防具。', 'sort_order' => 10],
        ['key' => 'robe', 'name' => 'ローブ・法衣', 'description' => '魔法職・僧侶職向けの防具。', 'sort_order' => 20],
        ['key' => 'cloak', 'name' => '外套・マント', 'description' => '身軽さと防御を両立する防具。', 'sort_order' => 30],
        ['key' => 'light_armor', 'name' => '革鎧・軽鎧', 'description' => '前衛・軽量職向けの防具。', 'sort_order' => 40],
        ['key' => 'heavy_armor', 'name' => '鎧・重鎧', 'description' => '戦士・騎士系向けの重防具。', 'sort_order' => 50],
    ];

    public function toggleWeapon(int $jobId, string $category): void
    {
        if (!$this->isValidCategory($category, $this->weaponCategories())) {
            return;
        }

        $this->togglePermission('job_weapon_permissions', 'weapon_category', $jobId, $category);
        session()->flash('message', '武器相性を更新しました。');
    }

    public function toggleArmor(int $jobId, string $category): void
    {
        if (!$this->isValidCategory($category, $this->armorCategories())) {
            return;
        }

        $this->togglePermission('job_armor_permissions', 'armor_category', $jobId, $category);
        session()->flash('message', '防具相性を更新しました。');
    }

    public function render()
    {
        $allJobs = JobClass::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $jobs = $allJobs
            ->when($this->rankFilter !== 'all', fn (Collection $items) => $items->where('rank', $this->rankFilter))
            ->when(trim($this->search) !== '', function (Collection $items) {
                $keyword = mb_strtolower(trim($this->search));

                return $items->filter(function (JobClass $job) use ($keyword) {
                    return str_contains(mb_strtolower($job->name), $keyword)
                        || str_contains(mb_strtolower($job->key ?? ''), $keyword)
                        || str_contains(mb_strtolower($job->category ?? ''), $keyword);
                });
            })
            ->values();

        $weaponCategories = $this->weaponCategories();
        $armorCategories = $this->armorCategories();
        $weaponPermissions = $this->permissionMap('job_weapon_permissions', 'weapon_category');
        $armorPermissions = $this->permissionMap('job_armor_permissions', 'armor_category');

        return view('livewire.admin.equipment-compatibility-manager', [
            'jobs' => $jobs,
            'rankLabels' => self::RANK_LABELS,
            'weaponCategories' => $weaponCategories,
            'armorCategories' => $armorCategories,
            'weaponPermissions' => $weaponPermissions,
            'armorPermissions' => $armorPermissions,
            'diagnostics' => $this->diagnostics($allJobs, $weaponCategories, $armorCategories, $weaponPermissions, $armorPermissions),
        ])->layout('components.layouts.admin');
    }

    private function togglePermission(string $table, string $categoryColumn, int $jobId, string $category): void
    {
        $query = DB::table($table)
            ->where('job_id', $jobId)
            ->where($categoryColumn, $category);

        if ($query->exists()) {
            $query->delete();

            return;
        }

        DB::table($table)->insert([
            'job_id' => $jobId,
            $categoryColumn => $category,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function weaponCategories(): array
    {
        return $this->categoriesFromTable(
            'weapon_categories',
            self::DEFAULT_WEAPON_CATEGORIES,
            $this->categoryItemCounts('weapon', 'weapon_category'),
        );
    }

    private function armorCategories(): array
    {
        return $this->categoriesFromTable(
            'armor_categories',
            self::DEFAULT_ARMOR_CATEGORIES,
            $this->categoryItemCounts('armor', 'armor_category'),
        );
    }

    private function categoriesFromTable(string $table, array $defaults, array $itemCounts): array
    {
        if (!Schema::hasTable($table)) {
            return $this->withItemCounts($defaults, $itemCounts);
        }

        $categories = DB::table($table)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['category_key', 'name', 'description', 'sort_order'])
            ->map(fn ($category) => [
                'key' => $category->category_key,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => (int) $category->sort_order,
                'item_count' => (int) ($itemCounts[$category->category_key] ?? 0),
            ])
            ->all();

        return $categories ?: $this->withItemCounts($defaults, $itemCounts);
    }

    private function withItemCounts(array $categories, array $itemCounts): array
    {
        return array_map(function (array $category) use ($itemCounts) {
            $category['item_count'] = (int) ($itemCounts[$category['key']] ?? 0);

            return $category;
        }, $categories);
    }

    private function categoryItemCounts(string $type, string $column): array
    {
        if (!Schema::hasTable('items') || !Schema::hasColumn('items', $column)) {
            return [];
        }

        return DB::table('items')
            ->where('type', $type)
            ->whereNotNull($column)
            ->selectRaw("{$column} as category_key, COUNT(*) as total")
            ->groupBy($column)
            ->pluck('total', 'category_key')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function permissionMap(string $table, string $categoryColumn): array
    {
        if (!Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->get(['job_id', $categoryColumn])
            ->groupBy('job_id')
            ->map(function (Collection $rows) use ($categoryColumn) {
                return $rows
                    ->pluck($categoryColumn)
                    ->mapWithKeys(fn (string $category) => [$category => true])
                    ->all();
            })
            ->all();
    }

    private function diagnostics(Collection $jobs, array $weaponCategories, array $armorCategories, array $weaponPermissions, array $armorPermissions): array
    {
        $items = [];
        $activeJobs = $jobs->where('is_active', true)->values();
        $normalJobs = $activeJobs->where('rank', 'normal');
        $normalAverage = max(1, $normalJobs->avg(fn (JobClass $job) => $this->permissionCount($job, $weaponPermissions) + $this->permissionCount($job, $armorPermissions)) ?? 1);

        foreach ($activeJobs as $job) {
            $weaponCount = $this->permissionCount($job, $weaponPermissions);
            $armorCount = $this->permissionCount($job, $armorPermissions);

            if ($weaponCount === 0) {
                $items[] = $this->diagnostic('danger', "{$job->name} は装備可能武器がありません。", 'この職業では武器装備の導線が詰まる可能性があります。');
            } elseif ($weaponCount === 1) {
                $items[] = $this->diagnostic('warning', "{$job->name} は装備可能武器が1種類だけです。", '狙った尖りでなければ、候補を増やすと育成の自由度が上がります。');
            }

            if ($armorCount === 0) {
                $items[] = $this->diagnostic('danger', "{$job->name} は装備可能防具がありません。", '防具更新ができず、序盤から耐久面で詰まる可能性があります。');
            }

            if ($job->rank === 'legend' && ($weaponCount + $armorCount) < $normalAverage) {
                $items[] = $this->diagnostic('warning', "{$job->name} は伝説職ですが装備制限が一般職平均より厳しめです。", '伝説職の強みとして意図した制限か確認してください。');
            }
        }

        foreach ($weaponCategories as $category) {
            if ($this->categoryPermissionCount($category['key'], $weaponPermissions) === 0) {
                $items[] = $this->diagnostic('danger', "武器種「{$category['name']}」を装備できる職業がありません。", '該当カテゴリの武器が実質的に使えない状態です。');
            }
        }

        foreach ($armorCategories as $category) {
            if ($this->categoryPermissionCount($category['key'], $armorPermissions) === 0) {
                $items[] = $this->diagnostic('warning', "防具種「{$category['name']}」を装備できる職業がありません。", '該当カテゴリの防具を残すなら、最低1職は装備可能にしてください。');
            }
        }

        foreach (array_merge($weaponCategories, $armorCategories) as $category) {
            if (($category['item_count'] ?? 0) === 0) {
                $items[] = $this->diagnostic('info', "カテゴリ「{$category['name']}」には登録済みアイテムがありません。", '将来用カテゴリなら問題ありません。不要ならマスタ整理候補です。');
            }
        }

        return $items;
    }

    private function diagnostic(string $severity, string $title, string $body): array
    {
        return compact('severity', 'title', 'body');
    }

    private function permissionCount(JobClass $job, array $permissions): int
    {
        return count($permissions[$job->id] ?? []);
    }

    private function categoryPermissionCount(string $category, array $permissions): int
    {
        $count = 0;
        foreach ($permissions as $jobPermissions) {
            if (!empty($jobPermissions[$category])) {
                $count++;
            }
        }

        return $count;
    }

    private function isValidCategory(string $category, array $categories): bool
    {
        return collect($categories)->contains(fn (array $item) => $item['key'] === $category);
    }
}
