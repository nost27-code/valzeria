<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TITLES = [
        '旅装ギルド設立準備',
        '遠征外套の試作',
        '祭礼布と護符の修繕',
        '深層調査隊の防護布',
        '空織り調査隊の装備準備',
    ];

    public function up(): void
    {
        if (
            ! Schema::hasTable('npc_procurement_request_templates')
            || ! Schema::hasTable('npc_procurement_request_template_materials')
            || ! Schema::hasTable('npc_procurement_requests')
            || ! Schema::hasTable('npc_procurement_request_materials')
        ) {
            return;
        }

        $existingTemplateIds = DB::table('npc_procurement_requests')
            ->whereNotNull('npc_procurement_request_template_id')
            ->whereIn('title', self::TITLES)
            ->whereIn('status', ['active', 'completed'])
            ->pluck('npc_procurement_request_template_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $templates = DB::table('npc_procurement_request_templates')
            ->where('is_active', true)
            ->whereIn('title', self::TITLES)
            ->when($existingTemplateIds !== [], fn ($query) => $query->whereNotIn('id', $existingTemplateIds))
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        foreach ($templates as $template) {
            $templateMaterials = DB::table('npc_procurement_request_template_materials')
                ->where('npc_procurement_request_template_id', $template->id)
                ->get();

            if ($templateMaterials->isEmpty()) {
                continue;
            }

            $requestId = DB::table('npc_procurement_requests')->insertGetId([
                'npc_procurement_request_template_id' => $template->id,
                'city_id' => $template->city_id,
                'npc_id' => $template->npc_id,
                'title' => $template->title,
                'requester_name' => $template->requester_name,
                'requester_type' => $template->requester_type,
                'description' => $template->description,
                'purpose_label' => $template->purpose_label,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => now()->addHours(max(1, (int) $template->duration_hours)),
                'completed_at' => null,
                'reward_gold_on_complete' => $template->reward_gold_on_complete,
                'reward_association_point_on_complete' => $template->reward_association_point_on_complete,
                'reward_items_json' => $template->reward_items_json,
                'display_order' => $template->display_order,
                'generated_for_date' => now()->toDateString(),
                'generated_batch_key' => now()->toDateString() . '-persistent-cloth',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($templateMaterials as $templateMaterial) {
                DB::table('npc_procurement_request_materials')->insert([
                    'npc_procurement_request_id' => $requestId,
                    'material_id' => $templateMaterial->material_id,
                    'required_quantity' => $templateMaterial->required_quantity,
                    'delivered_quantity' => 0,
                    'reward_gold_per_unit' => $templateMaterial->reward_gold_per_unit,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('npc_procurement_requests')
            || ! Schema::hasTable('npc_procurement_request_materials')
        ) {
            return;
        }

        $requestIds = DB::table('npc_procurement_requests')
            ->whereIn('title', self::TITLES)
            ->where('generated_batch_key', 'like', '%-persistent-cloth')
            ->pluck('id');

        if ($requestIds->isEmpty()) {
            return;
        }

        DB::table('npc_procurement_request_materials')
            ->whereIn('npc_procurement_request_id', $requestIds)
            ->delete();

        DB::table('npc_procurement_requests')
            ->whereIn('id', $requestIds)
            ->delete();
    }
};
