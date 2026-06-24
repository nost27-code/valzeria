<?php

namespace App\Livewire\Admin;

use App\Models\City;
use App\Models\Item;
use Livewire\Component;
use Livewire\WithPagination;

class ItemManager extends Component
{
    use WithPagination;

    public string $search = '';
    public string $typeFilter = 'all';
    public ?int $editingItemId = null;

    public array $form = [];
    public int $perPage = 30;

    private array $defaults = [
        'name' => '',
        'type' => 'weapon',
        'sub_type' => '',
        'description' => '',
        'rarity' => 'normal',
        'element' => '',
        'weapon_category' => '',
        'weapon_hand_type' => '',
        'weapon_role' => '',
        'armor_category' => '',
        'armor_weight' => '',
        'armor_role' => '',
        'price' => 0,
        'sell_price' => 0,
        'hp_bonus' => 0,
        'mp_bonus' => 0,
        'str_bonus' => 0,
        'def_bonus' => 0,
        'agi_bonus' => 0,
        'mag_bonus' => 0,
        'spr_bonus' => 0,
        'luk_bonus' => 0,
        'required_level' => 1,
        'unlock_city_id' => '',
        'is_shop_item' => false,
        'is_active' => false,
        'sort_order' => 0,
    ];

    public function mount(): void
    {
        $this->resetForm();
    }

    public function createNew(string $type = 'weapon'): void
    {
        $this->resetForm();
        $this->form['type'] = in_array($type, ['weapon', 'armor', 'accessory', 'consumable', 'material'], true) ? $type : 'weapon';
        $this->editingItemId = null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function edit(int $itemId): void
    {
        $item = Item::findOrFail($itemId);
        $this->editingItemId = $item->id;
        $this->form = array_merge($this->defaults, [
            'name' => $item->name,
            'type' => $item->type,
            'sub_type' => $item->sub_type ?? '',
            'description' => $item->description ?? '',
            'rarity' => $item->rarity ?? 'normal',
            'element' => $item->element ?? '',
            'weapon_category' => $item->weapon_category ?? '',
            'weapon_hand_type' => $item->weapon_hand_type ?? '',
            'weapon_role' => $item->weapon_role ?? '',
            'armor_category' => $item->armor_category ?? '',
            'armor_weight' => $item->armor_weight ?? '',
            'armor_role' => $item->armor_role ?? '',
            'price' => (int) $item->price,
            'sell_price' => (int) ($item->sell_price ?? 0),
            'hp_bonus' => (int) $item->hp_bonus,
            'mp_bonus' => (int) $item->mp_bonus,
            'str_bonus' => (int) $item->str_bonus,
            'def_bonus' => (int) $item->def_bonus,
            'agi_bonus' => (int) $item->agi_bonus,
            'mag_bonus' => (int) $item->mag_bonus,
            'spr_bonus' => (int) $item->spr_bonus,
            'luk_bonus' => (int) $item->luk_bonus,
            'required_level' => (int) $item->required_level,
            'unlock_city_id' => $item->unlock_city_id ? (string) $item->unlock_city_id : '',
            'is_shop_item' => (bool) $item->is_shop_item,
            'is_active' => (bool) $item->is_active,
            'sort_order' => (int) $item->sort_order,
        ]);
    }

    public function save(): void
    {
        $validated = $this->validate([
            'form.name' => 'required|string|max:100',
            'form.type' => 'required|in:weapon,armor,accessory,consumable,material',
            'form.sub_type' => 'nullable|string|max:100',
            'form.description' => 'nullable|string|max:500',
            'form.rarity' => 'required|string|max:30',
            'form.element' => 'nullable|string|max:30',
            'form.weapon_category' => 'nullable|string|max:50',
            'form.weapon_hand_type' => 'nullable|string|max:20',
            'form.weapon_role' => 'nullable|string|max:20',
            'form.armor_category' => 'nullable|string|max:50',
            'form.armor_weight' => 'nullable|string|max:20',
            'form.armor_role' => 'nullable|string|max:20',
            'form.price' => 'required|integer|min:0|max:999999999',
            'form.sell_price' => 'required|integer|min:0|max:999999999',
            'form.hp_bonus' => 'required|integer|min:-999999|max:999999',
            'form.mp_bonus' => 'required|integer|min:-999999|max:999999',
            'form.str_bonus' => 'required|integer|min:-999999|max:999999',
            'form.def_bonus' => 'required|integer|min:-999999|max:999999',
            'form.agi_bonus' => 'required|integer|min:-999999|max:999999',
            'form.mag_bonus' => 'required|integer|min:-999999|max:999999',
            'form.spr_bonus' => 'required|integer|min:-999999|max:999999',
            'form.luk_bonus' => 'required|integer|min:-999999|max:999999',
            'form.required_level' => 'required|integer|min:1|max:9999',
            'form.unlock_city_id' => 'nullable',
            'form.is_shop_item' => 'boolean',
            'form.is_active' => 'boolean',
            'form.sort_order' => 'required|integer|min:0|max:999999',
        ])['form'];

        $data = $validated;
        $data['unlock_city_id'] = $data['unlock_city_id'] !== '' ? (int) $data['unlock_city_id'] : null;
        foreach (['weapon_category', 'weapon_hand_type', 'weapon_role', 'armor_category', 'armor_weight', 'armor_role'] as $field) {
            $data[$field] = $data[$field] !== '' ? $data[$field] : null;
        }
        $data['is_shop_item'] = (bool) $data['is_shop_item'];
        $data['is_active'] = (bool) $data['is_active'];

        if ($this->editingItemId) {
            Item::findOrFail($this->editingItemId)->update($data);
        } else {
            Item::create($data);
        }

        session()->flash('message', $this->editingItemId ? 'アイテムを更新しました。' : 'アイテムを追加しました。');
        $this->resetForm();
    }

    public function toggleActive(int $itemId): void
    {
        $item = Item::findOrFail($itemId);
        $item->is_active = !$item->is_active;
        $item->save();
        session()->flash('message', "{$item->name} の公開状態を変更しました。");
    }

    public function resetForm(): void
    {
        $this->editingItemId = null;
        $this->form = $this->defaults;
    }

    public function render()
    {
        $items = Item::query()
            ->when($this->typeFilter !== 'all', fn($q) => $q->where('type', $this->typeFilter))
            ->when($this->search !== '', fn($q) => $q->where('name', 'like', '%' . $this->search . '%'))
            ->orderByDesc('id')
            ->paginate($this->perPage);

        return view('livewire.admin.item-manager', [
            'items' => $items,
            'cities' => City::orderBy('id')->get(['id', 'name']),
        ])->layout('components.layouts.admin');
    }
}
