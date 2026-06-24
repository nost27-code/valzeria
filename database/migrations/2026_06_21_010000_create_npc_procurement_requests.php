<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('npc_procurement_requests')) {
            Schema::create('npc_procurement_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('city_id')->nullable();
                $table->string('title', 120);
                $table->string('requester_name', 120);
                $table->string('requester_type', 50)->default('npc');
                $table->text('description')->nullable();
                $table->string('purpose_label', 100)->nullable();
                $table->string('status', 30)->default('active');
                $table->dateTime('starts_at');
                $table->dateTime('expires_at');
                $table->dateTime('completed_at')->nullable();
                $table->integer('reward_gold_on_complete')->default(0);
                $table->integer('reward_association_point_on_complete')->default(0);
                $table->json('reward_items_json')->nullable();
                $table->integer('display_order')->default(0);
                $table->timestamps();

                $table->index(['status', 'expires_at'], 'npc_requests_status_expires_idx');
                $table->index(['city_id', 'status'], 'npc_requests_city_status_idx');
                $table->index('starts_at', 'npc_requests_starts_at_idx');
                $table->unique(['title', 'requester_name'], 'npc_requests_unique_title_requester');
            });
        }

        if (! Schema::hasTable('npc_procurement_request_materials')) {
            Schema::create('npc_procurement_request_materials', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('npc_procurement_request_id');
                $table->unsignedBigInteger('material_id');
                $table->integer('required_quantity');
                $table->integer('delivered_quantity')->default(0);
                $table->integer('reward_gold_per_unit')->default(0);
                $table->timestamps();

                $table->index('npc_procurement_request_id', 'npc_request_material_request_idx');
                $table->index('material_id', 'npc_request_material_material_idx');
                $table->unique(['npc_procurement_request_id', 'material_id'], 'npc_request_material_unique');
            });
        }

        if (! Schema::hasTable('npc_procurement_deliveries')) {
            Schema::create('npc_procurement_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('npc_procurement_request_id');
                $table->unsignedBigInteger('npc_procurement_request_material_id');
                $table->unsignedBigInteger('character_id');
                $table->unsignedBigInteger('material_id');
                $table->integer('quantity');
                $table->integer('reward_gold')->default(0);
                $table->integer('reward_association_point')->default(0);
                $table->timestamp('created_at')->nullable();

                $table->index(['character_id', 'created_at'], 'npc_deliveries_character_created_idx');
                $table->index('npc_procurement_request_id', 'npc_deliveries_request_idx');
                $table->index('material_id', 'npc_deliveries_material_idx');
            });
        }

        $this->seedInitialRequests();
    }

    public function down(): void
    {
        Schema::dropIfExists('npc_procurement_deliveries');
        Schema::dropIfExists('npc_procurement_request_materials');
        Schema::dropIfExists('npc_procurement_requests');
    }

    private function seedInitialRequests(): void
    {
        if (! Schema::hasTable('materials')) {
            return;
        }

        $now = now();
        $requests = [
            [
                'title' => '炉の補修素材募集',
                'requester_name' => '鉄鋼都市の鍛冶組合',
                'requester_type' => 'blacksmith_guild',
                'description' => '高温炉の補修に使う鉱石素材を集めています。',
                'purpose_label' => '炉の補修',
                'material_code' => 'MAT_COMMON_MAGIC_ORE',
                'required_quantity' => 30,
                'reward_gold_per_unit' => 55,
                'display_order' => 10,
            ],
            [
                'title' => '薬草研究の素材募集',
                'requester_name' => '薬師見習いミリカ',
                'requester_type' => 'apothecary',
                'description' => '回復薬の仕込みに向く森の素材を集めています。',
                'purpose_label' => '回復薬の仕込み',
                'material_code' => 'MAT_REGION_WORLD_TREE_LEAF',
                'required_quantity' => 12,
                'reward_gold_per_unit' => 75,
                'display_order' => 20,
            ],
            [
                'title' => '小鬼討伐の証明品募集',
                'requester_name' => '街道警備隊',
                'requester_type' => 'city_guard',
                'description' => '街道の安全確認のため、小鬼の素材を買い取っています。',
                'purpose_label' => '街道警備',
                'material_code' => 'MAT_COMMON_GOBLIN_FANG',
                'required_quantity' => 15,
                'reward_gold_per_unit' => 120,
                'display_order' => 30,
            ],
            [
                'title' => '防具補修素材募集',
                'requester_name' => '防具職人ギルド',
                'requester_type' => 'guild',
                'description' => '軽装防具の補修に使う毛皮素材を募集しています。',
                'purpose_label' => '防具補修',
                'material_code' => 'MAT_COMMON_BEAST_FUR',
                'required_quantity' => 25,
                'reward_gold_per_unit' => 45,
                'display_order' => 40,
            ],
            [
                'title' => '氷晶片の研究素材募集',
                'requester_name' => '雪都の魔導研究員',
                'requester_type' => 'npc',
                'description' => '氷属性研究のため、安定した氷晶片を必要としています。',
                'purpose_label' => '氷属性研究',
                'material_code' => 'MAT_REGION_ICE_CRYSTAL',
                'required_quantity' => 10,
                'reward_gold_per_unit' => 300,
                'display_order' => 50,
            ],
        ];

        foreach ($requests as $requestData) {
            $material = DB::table('materials')
                ->where('material_code', $requestData['material_code'])
                ->first();

            if (! $material) {
                continue;
            }

            $requestId = DB::table('npc_procurement_requests')->updateOrInsert(
                [
                    'title' => $requestData['title'],
                    'requester_name' => $requestData['requester_name'],
                ],
                [
                    'city_id' => null,
                    'requester_type' => $requestData['requester_type'],
                    'description' => $requestData['description'],
                    'purpose_label' => $requestData['purpose_label'],
                    'status' => 'active',
                    'starts_at' => $now,
                    'expires_at' => $now->copy()->addDay(),
                    'completed_at' => null,
                    'reward_gold_on_complete' => 0,
                    'reward_association_point_on_complete' => 0,
                    'reward_items_json' => null,
                    'display_order' => $requestData['display_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $request = DB::table('npc_procurement_requests')
                ->where('title', $requestData['title'])
                ->where('requester_name', $requestData['requester_name'])
                ->first();

            if (! $request) {
                continue;
            }

            DB::table('npc_procurement_request_materials')->updateOrInsert(
                [
                    'npc_procurement_request_id' => $request->id,
                    'material_id' => $material->id,
                ],
                [
                    'required_quantity' => $requestData['required_quantity'],
                    'delivered_quantity' => 0,
                    'reward_gold_per_unit' => $requestData['reward_gold_per_unit'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
