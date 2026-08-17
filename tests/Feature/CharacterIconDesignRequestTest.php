<?php

namespace Tests\Feature;

use App\Livewire\Admin\ExtraContentManager;
use App\Livewire\MainScreen;
use App\Models\Character;
use App\Models\CharacterIconDesignMessageAttachment;
use App\Models\CharacterIconDesignRequest;
use App\Models\PlayerValmon;
use App\Models\User;
use App\Models\ValmonMaster;
use App\Services\AdminWebPushNotificationService;
use App\Services\CharacterIconDesignService;
use App\Services\ExtraContentControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class CharacterIconDesignRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'character_icon_design.public_access_enabled' => true,
            'character_icon_design.preview_character_ids' => [],
        ]);
        app(ExtraContentControlService::class)->setEnabled(CharacterIconDesignService::CONTENT_KEY, true);
    }

    public function test_feature_is_hidden_and_player_routes_are_blocked_while_disabled(): void
    {
        app(ExtraContentControlService::class)->setEnabled(CharacterIconDesignService::CONTENT_KEY, false);
        [$player, $character] = $this->createPlayer('公開前利用者', 40, 0);

        $menuMethod = new ReflectionMethod(MainScreen::class, 'homeMenuItems');
        $menuItems = $menuMethod->invoke(new MainScreen);

        $this->assertNotContains('キャラアイコン作成', array_column($menuItems, 'name'));

        $this->actingAs($player)
            ->get(route('character-icon-design.show'))
            ->assertNotFound();
        $this->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ])->assertNotFound();

        $this->assertDatabaseCount('character_icon_design_requests', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);

        $designRequest = CharacterIconDesignRequest::query()->create([
            'character_id' => $character->id,
            'status' => 'submitted',
            'price_kiseki' => 40,
            'form_data' => $this->validFormPayload(),
            'submitted_at' => now(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.character-icon-design.show', $designRequest))
            ->assertOk()
            ->assertSee('公開前利用者');
    }

    public function test_enabled_feature_allows_drafts_but_blocks_confirmation_and_submission(): void
    {
        config([
            'character_icon_design.public_access_enabled' => false,
            'character_icon_design.preview_character_ids' => [],
        ]);
        [$player, $character] = $this->createPlayer('準備中確認者', 40, 0);

        $screen = new MainScreen;
        $screen->character = $character;
        $menuMethod = new ReflectionMethod(MainScreen::class, 'homeMenuItems');
        $menuItems = $menuMethod->invoke($screen);
        $menuItem = collect($menuItems)->firstWhere('name', 'キャラアイコン作成');

        $this->assertIsArray($menuItem);
        $this->assertSame('案内', $menuItem['group']);
        $this->assertSame('character-icon-design.show', $menuItem['route']);
        $this->assertArrayNotHasKey('modal_message', $menuItem);

        $this->actingAs($player)
            ->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('現在は下書き保存まで利用できます。')
            ->assertSee('確認');

        $this->post(route('character-icon-design.form.save'), [
            'one_line' => '準備中に保存する冒険者',
            'intent' => 'confirm',
        ])
            ->assertRedirect(route('character-icon-design.show', ['view' => 'new']))
            ->assertSessionHas(
                'character_icon_design_preparing_message',
                '現在準備中です。もうしばらくお待ちください。下書き保存しました。'
            );

        $this->get(route('character-icon-design.show', ['view' => 'new']))
            ->assertOk()
            ->assertSee('現在準備中です。もうしばらくお待ちください。下書き保存しました。')
            ->assertSee('準備中に保存する冒険者');

        $this->get(route('character-icon-design.form.confirm'))
            ->assertRedirect(route('character-icon-design.show', ['view' => 'new']))
            ->assertSessionHas('character_icon_design_preparing_message');

        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['view' => 'new']))
            ->assertSessionHas('character_icon_design_preparing_message');

        $serviceResult = app(CharacterIconDesignService::class)->saveForm(
            $character->fresh(),
            $this->validFormPayload(),
            true,
        );
        $this->assertFalse($serviceResult['success']);
        $this->assertSame(
            '現在準備中です。もうしばらくお待ちください。下書き保存しました。',
            $serviceResult['message']
        );

        $this->assertDatabaseHas('character_icon_design_requests', [
            'character_id' => $character->id,
            'status' => 'draft',
            'purchased_at' => null,
            'submitted_at' => null,
        ]);
        $this->assertSame(40, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_preview_character_can_submit_while_other_players_are_limited_to_drafts(): void
    {
        config(['character_icon_design.public_access_enabled' => false]);
        [$previewPlayer, $previewCharacter] = $this->createPlayer('先行確認者', 40, 0);
        [$otherPlayer, $otherCharacter] = $this->createPlayer('一般利用者', 40, 0);
        config(['character_icon_design.preview_character_ids' => [$previewCharacter->id]]);

        $this->actingAs($previewPlayer)
            ->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('ヒアリングシート');
        $this->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ])->assertRedirect(route('character-icon-design.form.confirm'));

        $this->actingAs($otherPlayer)
            ->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('現在は下書き保存まで利用できます。');

        $this->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ])
            ->assertRedirect(route('character-icon-design.show', ['view' => 'new']))
            ->assertSessionHas('character_icon_design_preparing_message');

        $this->assertSame(40, (int) $previewCharacter->fresh()->kiseki);
        $this->assertSame(40, (int) $otherCharacter->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_admin_can_enable_the_feature_from_extra_content_management(): void
    {
        app(ExtraContentControlService::class)->setEnabled(CharacterIconDesignService::CONTENT_KEY, false);
        $admin = User::factory()->create(['role' => 'admin']);

        Livewire::actingAs($admin)
            ->test(ExtraContentManager::class)
            ->assertSet('contents.character_icon_design.enabled', false)
            ->call('toggle', CharacterIconDesignService::CONTENT_KEY)
            ->assertSet('contents.character_icon_design.enabled', true);

        $this->assertTrue(
            app(ExtraContentControlService::class)->isActive(CharacterIconDesignService::CONTENT_KEY)
        );
    }

    public function test_every_player_can_open_and_save_a_draft_without_spending_kiseki(): void
    {
        [$player, $character] = $this->createPlayer('下書き利用者');

        $this->actingAs($player)
            ->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('記入と下書き保存は無料です')
            ->assertDontSee('40輝石を支払って提出')
            ->assertSee('ヒアリングシート')
            ->assertDontSee('まず答える簡易ヒアリング')
            ->assertDontSee('使用したい場面')
            ->assertDontSee('ゲーム内アバター')
            ->assertDontSee('制作対象はゲーム内アバターのみです')
            ->assertDontSee('完成した画像はSNSなどでも自由にお使いいただけます')
            ->assertDontSee('SNSアイコン')
            ->assertDontSee('配信用')
            ->assertSee('サンプルを見る')
            ->assertSee('王道・主人公系')
            ->assertSee('魔導炉を背負って戦う機甲鍛士')
            ->assertSee('新規作成')
            ->assertSee('提出済み')
            ->assertSee('value="confirm"', false)
            ->assertSee('確認')
            ->assertSee('data-character-icon-autosave', false)
            ->assertSee('data-character-icon-intent', false)
            ->assertSee('変更内容は自動で下書き保存されます')
            ->assertDontSee('return confirm(', false);

        $examples = config('character_icon_design.one_line_examples');
        $this->assertCount(11, $examples);
        $this->assertCount(110, collect($examples)->flatten());

        $this->post(route('character-icon-design.form.save'), [
            'one_line' => '王都の高潔な近衛騎士',
            'intent' => 'draft',
        ])->assertRedirect(route('character-icon-design.show'));

        $this->assertSame(0, (int) $character->fresh()->kiseki);
        $this->assertDatabaseHas('character_icon_design_requests', [
            'character_id' => $character->id,
            'status' => 'draft',
            'price_kiseki' => 40,
            'purchased_at' => null,
        ]);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_missing_intent_defaults_to_draft_and_json_autosave_saves_without_charging(): void
    {
        [$player, $character] = $this->createPlayer('自動保存利用者', 40, 0);

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                'usage_scenes' => ['game_avatar'],
                'one_line' => '入力途中の冒険者',
            ])
            ->assertRedirect(route('character-icon-design.show'))
            ->assertSessionHasNoErrors();

        $this->postJson(route('character-icon-design.form.save'), [
            'usage_scenes' => ['game_avatar'],
            'one_line' => '自動保存された冒険者',
            'intent' => 'draft',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => '下書きを保存しました。',
            ]);

        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();

        $this->assertSame('draft', $designRequest->status);
        $this->assertSame('自動保存された冒険者', $designRequest->form_data['one_line']);
        $this->assertSame(40, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_confirmation_validation_lists_the_missing_field_names(): void
    {
        [$player] = $this->createPlayer('未入力確認者');

        $this->actingAs($player)
            ->followingRedirects()
            ->from(route('character-icon-design.show'))
            ->post(route('character-icon-design.form.save'), [
                'usage_scenes' => ['game_avatar'],
                'intent' => 'confirm',
            ])
            ->assertOk()
            ->assertSee('「確認」に進むには、次の項目を入力・選択してください。')
            ->assertSee('イメージ優先度を入力または選択してください。')
            ->assertSee('性別イメージを入力または選択してください。')
            ->assertDontSee('避けてほしい要素（NG）を入力または選択してください。')
            ->assertDontSee('一言で表すキャラクター像を入力または選択してください。')
            ->assertSee('下書き保存は、未入力の項目があっても利用できます。');
    }

    public function test_confirmation_allows_ng_elements_and_one_line_to_be_blank(): void
    {
        [$player] = $this->createPlayer('任意項目確認者');
        $payload = $this->validFormPayload();
        unset($payload['ng_elements'], $payload['one_line']);

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                ...$payload,
                'intent' => 'confirm',
            ])
            ->assertRedirect(route('character-icon-design.form.confirm'))
            ->assertSessionHasNoErrors();
    }

    public function test_confirmation_step_saves_the_completed_form_without_spending_kiseki(): void
    {
        [$player, $character] = $this->createPlayer('確認利用者', 40, 10);

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                ...$this->validFormPayload(),
                'intent' => 'confirm',
            ])
            ->assertRedirect(route('character-icon-design.form.confirm'));

        $this->assertSame(50, (int) $character->fresh()->kiseki);
        $this->assertDatabaseHas('character_icon_design_requests', [
            'character_id' => $character->id,
            'status' => 'draft',
            'purchased_at' => null,
            'submitted_at' => null,
        ]);
        $this->assertDatabaseCount('kiseki_transactions', 0);

        $this->get(route('character-icon-design.form.confirm'))
            ->assertOk()
            ->assertSee('ヒアリング内容の確認')
            ->assertSee('優しい雰囲気の星読み司書')
            ->assertSee('重装は避けてください')
            ->assertDontSee('使用したい場面')
            ->assertDontSee('ゲーム内アバター')
            ->assertSee('40輝石を支払って提出')
            ->assertSee('申請時の参考画像')
            ->assertSee('添付画像を優先し、ヒアリングシートの回答内容は参考程度として扱います。')
            ->assertSee('data-multi-image-picker', false)
            ->assertSee('data-multi-image-input', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('提出後も制作完了前であれば、提出済み画面からヒアリング内容を修正できます。')
            ->assertDontSee('提出後はヒアリング内容を変更できません。')
            ->assertSee('入力内容を修正');
    }

    public function test_non_game_usage_scene_is_rejected_without_charging(): void
    {
        [$player, $character] = $this->createPlayer('利用場面改ざん者', 40, 0);
        $payload = $this->validFormPayload();
        $payload['usage_scenes'] = ['sns_icon'];
        $payload['intent'] = 'confirm';

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), $payload)
            ->assertSessionHasErrors('usage_scenes.0');

        $this->assertSame(40, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('character_icon_design_requests', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_admin_cannot_see_a_players_unsubmitted_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$player, $character] = $this->createPlayer('下書き非公開者');

        $this->actingAs($player)->post(route('character-icon-design.form.save'), [
            'one_line' => '海の自由人っぽい双剣船長',
            'intent' => 'draft',
        ]);
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.character-icon-design.index'))
            ->assertOk()
            ->assertDontSee('下書き非公開者');
        $this->get(route('admin.character-icon-design.show', $designRequest))
            ->assertNotFound();
    }

    public function test_sheet_submission_spends_free_kiseki_first_and_cannot_charge_twice(): void
    {
        [$admin, $adminCharacter] = $this->createPlayer('ヴァル');
        $admin->forceFill(['role' => 'admin'])->save();
        config()->set('web_push.admin_recipient_character_id', $adminCharacter->id);
        [$player, $character] = $this->createPlayer('提出者', 30, 20);

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                ...$this->validFormPayload(),
                'intent' => 'confirm',
            ])
            ->assertRedirect(route('character-icon-design.form.confirm'));

        $this->assertSame(30, (int) $character->fresh()->free_kiseki);
        $this->assertSame(20, (int) $character->fresh()->paid_kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 0);
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();

        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]));

        $character->refresh();
        $designRequest->refresh();

        $this->assertSame(0, (int) $character->free_kiseki);
        $this->assertSame(10, (int) $character->paid_kiseki);
        $this->assertSame(10, (int) $character->kiseki);
        $this->assertSame('submitted', $designRequest->status);
        $this->assertSame(30, (int) $designRequest->free_kiseki_spent);
        $this->assertSame(10, (int) $designRequest->paid_kiseki_spent);
        $this->assertNotNull($designRequest->submitted_at);
        $this->assertNotNull($designRequest->purchased_at);
        $this->assertDatabaseHas('kiseki_transactions', [
            'character_id' => $character->id,
            'kiseki_type' => 'mixed',
            'amount' => -40,
            'transaction_type' => 'service_purchase',
            'source_type' => 'character_icon_design',
            'source_id' => $designRequest->id,
        ]);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $adminCharacter->id,
            'type' => AdminWebPushNotificationService::TYPE_CHARACTER_ICON_DESIGN,
            'title' => 'キャラ画像作成依頼が届きました',
        ]);
        $this->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('管理人との専用チャット');

        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show'));

        $this->assertSame(1, CharacterIconDesignRequest::query()->where('character_id', $character->id)->count());
        $this->assertDatabaseCount('kiseki_transactions', 1);
        $this->assertSame(1, \App\Models\CharacterNotification::query()
            ->where('type', AdminWebPushNotificationService::TYPE_CHARACTER_ICON_DESIGN)
            ->count());
        $this->assertSame(10, (int) $character->fresh()->kiseki);

        $retryResult = app(CharacterIconDesignService::class)->saveForm(
            $character->fresh(),
            $this->validFormPayload(),
            true,
            $designRequest->id,
        );

        $this->assertTrue($retryResult['success']);
        $this->assertSame('ヒアリングシートは提出済みです。', $retryResult['message']);
        $this->assertFalse($retryResult['submitted_now']);
        $this->assertSame(10, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 1);
    }

    public function test_sheet_submission_can_attach_reference_images_for_the_admin(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        [$player, $character] = $this->createPlayer('参考画像申請者', 40, 0);

        $this->actingAs($player)->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ])->assertRedirect(route('character-icon-design.form.confirm'));
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();

        $this->post(route('character-icon-design.form.submit'), [
            'attachments' => [
                $this->fakePng('reference-1.png'),
                $this->fakePng('reference-2.png'),
            ],
        ])->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, '参考画像も添付しました。'));

        $this->assertSame(0, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 1);
        $this->assertDatabaseHas('character_icon_design_messages', [
            'character_icon_design_request_id' => $designRequest->id,
            'sender_type' => 'player',
            'body' => '申請時の参考画像を添付しました。',
            'read_by_admin_at' => null,
        ]);
        $this->assertDatabaseCount('character_icon_design_message_attachments', 2);

        $attachments = CharacterIconDesignMessageAttachment::query()
            ->orderBy('position')
            ->get();
        $this->assertSame(['reference-1.png', 'reference-2.png'], $attachments->pluck('original_name')->all());
        foreach ($attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment->path);
        }

        $this->actingAs($admin)
            ->get(route('admin.character-icon-design.index'))
            ->assertOk()
            ->assertSee('未読返信 1');
        $this->get(route('admin.character-icon-design.show', $designRequest))
            ->assertOk()
            ->assertSee('申請時の参考画像を添付しました。')
            ->assertSee('reference-1.png')
            ->assertSee('reference-2.png');
    }

    public function test_invalid_submission_attachment_does_not_charge_kiseki(): void
    {
        Storage::fake('local');

        [$player, $character] = $this->createPlayer('不正画像申請者', 40, 0);

        $this->actingAs($player)->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ])->assertRedirect(route('character-icon-design.form.confirm'));
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();

        $this->post(route('character-icon-design.form.submit'), [
            'attachments' => [UploadedFile::fake()->create('not-image.txt', 10, 'text/plain')],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertSame(40, (int) $character->fresh()->kiseki);
        $this->assertSame('draft', $designRequest->fresh()->status);
        $this->assertNull($designRequest->fresh()->purchased_at);
        $this->assertNull($designRequest->fresh()->submitted_at);
        $this->assertDatabaseCount('kiseki_transactions', 0);
        $this->assertDatabaseCount('character_icon_design_messages', 0);
        $this->assertDatabaseCount('character_icon_design_message_attachments', 0);
    }

    public function test_player_can_create_another_request_without_overwriting_submitted_requests(): void
    {
        [$player, $character] = $this->createPlayer('複数依頼者', 80, 0);
        $firstPayload = $this->validFormPayload();
        $firstPayload['one_line'] = '最初に依頼した星読み司書';

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                ...$firstPayload,
                'intent' => 'confirm',
            ])
            ->assertRedirect(route('character-icon-design.form.confirm'));
        $firstRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();
        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['request' => $firstRequest->id]));

        $this->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('新規作成')
            ->assertSee('提出済み')
            ->assertSee('最初に依頼した星読み司書');
        $this->get(route('character-icon-design.show', ['view' => 'new']))
            ->assertOk()
            ->assertSee('記入と下書き保存は無料です')
            ->assertDontSee('最初に依頼した星読み司書');

        $secondPayload = $this->validFormPayload();
        $secondPayload['one_line'] = '新しく依頼する白銀の騎士';
        $this->post(route('character-icon-design.form.save'), [
            ...$secondPayload,
            'intent' => 'confirm',
        ])->assertRedirect(route('character-icon-design.form.confirm'));
        $secondRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->where('status', 'draft')
            ->firstOrFail();
        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['request' => $secondRequest->id]));

        $this->assertNotSame($firstRequest->id, $secondRequest->id);
        $this->assertSame(0, (int) $character->fresh()->kiseki);
        $this->assertSame(
            2,
            CharacterIconDesignRequest::query()->where('character_id', $character->id)->count()
        );
        $this->assertDatabaseCount('kiseki_transactions', 2);
        $this->assertDatabaseHas('character_icon_design_requests', [
            'id' => $firstRequest->id,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('character_icon_design_requests', [
            'id' => $secondRequest->id,
            'status' => 'submitted',
        ]);

        $this->get(route('character-icon-design.show', ['request' => $firstRequest->id]))
            ->assertOk()
            ->assertSee('最初に依頼した星読み司書')
            ->assertSee('新しく依頼する白銀の騎士');
        $this->get(route('character-icon-design.show', ['request' => $secondRequest->id]))
            ->assertOk()
            ->assertSee('新しく依頼する白銀の騎士');

        [$otherPlayer] = $this->createPlayer('別の依頼者');
        $this->actingAs($otherPlayer)
            ->get(route('character-icon-design.show', ['request' => $firstRequest->id]))
            ->assertNotFound();
        $this->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'design_request_id' => $firstRequest->id,
            'intent' => 'confirm',
        ])->assertNotFound();
    }

    public function test_player_can_revise_a_submitted_sheet_without_another_charge_and_admin_sees_update(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$player, $character] = $this->createPlayer('提出後修正者', 40, 0);

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                ...$this->validFormPayload(),
                'intent' => 'confirm',
            ])
            ->assertRedirect(route('character-icon-design.form.confirm'));
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();
        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]));

        $designRequest->refresh();
        $submittedAt = $designRequest->submitted_at?->toISOString();

        $this->get(route('character-icon-design.show', [
            'request' => $designRequest->id,
            'edit' => 1,
        ]))
            ->assertOk()
            ->assertSee('提出済みのヒアリング内容を修正')
            ->assertSee('修正による輝石の追加消費はありません')
            ->assertSee('優しい雰囲気の星読み司書')
            ->assertSee('name="design_request_id"', false)
            ->assertSee('修正内容を保存')
            ->assertDontSee('data-character-icon-autosave', false);

        $revisedPayload = $this->validFormPayload();
        $revisedPayload['one_line'] = '静かな森を守る星読み司書';
        $revisedPayload['must_have'] = '月形の髪飾り';

        $this->post(route('character-icon-design.form.save'), [
            ...$revisedPayload,
            'design_request_id' => $designRequest->id,
            'intent' => 'confirm',
        ])
            ->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]))
            ->assertSessionHas('status', 'ヒアリング内容を修正しました。管理人にも更新をお知らせしました。');

        $designRequest->refresh();
        $this->assertSame('submitted', $designRequest->status);
        $this->assertSame($submittedAt, $designRequest->submitted_at?->toISOString());
        $this->assertSame('静かな森を守る星読み司書', $designRequest->form_data['one_line']);
        $this->assertSame('月形の髪飾り', $designRequest->form_data['must_have']);
        $this->assertSame(0, (int) $character->fresh()->kiseki);
        $this->assertDatabaseCount('kiseki_transactions', 1);
        $this->assertDatabaseHas('character_icon_design_messages', [
            'character_icon_design_request_id' => $designRequest->id,
            'sender_type' => 'player',
            'body' => CharacterIconDesignService::FORM_UPDATED_MESSAGE,
            'read_by_admin_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.character-icon-design.index'))
            ->assertOk()
            ->assertSee('回答更新あり');
        $this->get(route('admin.character-icon-design.show', $designRequest))
            ->assertOk()
            ->assertSee('静かな森を守る星読み司書')
            ->assertSee('月形の髪飾り')
            ->assertSee(CharacterIconDesignService::FORM_UPDATED_MESSAGE);
        $this->get(route('admin.character-icon-design.index'))
            ->assertOk()
            ->assertDontSee('回答更新あり');
    }

    public function test_completed_sheet_cannot_be_revised(): void
    {
        [$player, $character] = $this->createPlayer('制作完了者');
        $designRequest = CharacterIconDesignRequest::query()->create([
            'character_id' => $character->id,
            'status' => 'completed',
            'price_kiseki' => 40,
            'form_data' => $this->validFormPayload(),
            'purchased_at' => now(),
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);
        $revisedPayload = $this->validFormPayload();
        $revisedPayload['one_line'] = '変更されてはいけないキャラ';

        $this->actingAs($player)
            ->get(route('character-icon-design.show', [
                'request' => $designRequest->id,
                'edit' => 1,
            ]))
            ->assertNotFound();

        $this->post(route('character-icon-design.form.save'), [
            ...$revisedPayload,
            'design_request_id' => $designRequest->id,
            'intent' => 'confirm',
        ])
            ->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]))
            ->assertSessionHas('error', '制作完了後のヒアリング内容は修正できません。');

        $this->assertSame(
            '優しい雰囲気の星読み司書',
            $designRequest->fresh()->form_data['one_line']
        );
        $this->assertDatabaseCount('character_icon_design_messages', 0);
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_insufficient_kiseki_saves_the_draft_without_submitting_or_charging(): void
    {
        [$player, $character] = $this->createPlayer('残高不足者', 39, 0);

        $this->actingAs($player)
            ->post(route('character-icon-design.form.save'), [
                ...$this->validFormPayload(),
                'intent' => 'confirm',
            ])
            ->assertRedirect(route('character-icon-design.form.confirm'));

        $this->get(route('character-icon-design.form.confirm'))
            ->assertOk()
            ->assertSee('輝石が不足しています。入力内容は下書きとして保存されています。');

        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['view' => 'new']));

        $this->assertSame(39, (int) $character->fresh()->kiseki);
        $this->assertDatabaseHas('character_icon_design_requests', [
            'character_id' => $character->id,
            'status' => 'draft',
            'free_kiseki_spent' => 0,
            'paid_kiseki_spent' => 0,
            'purchased_at' => null,
            'submitted_at' => null,
        ]);
        $this->assertSame(
            '優しい雰囲気の星読み司書',
            CharacterIconDesignRequest::query()->where('character_id', $character->id)->firstOrFail()->form_data['one_line']
        );
        $this->assertDatabaseCount('kiseki_transactions', 0);
    }

    public function test_submitted_sheet_opens_a_private_image_chat_for_the_player_and_admin(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        [$player, $character] = $this->createPlayer('制作依頼者', 40, 0);

        $this->actingAs($player)->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ])->assertRedirect(route('character-icon-design.form.confirm'));
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $character->id)
            ->firstOrFail();
        $this->post(route('character-icon-design.form.submit'))
            ->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]));

        $designRequest->refresh();
        $this->assertSame('submitted', $designRequest->status);
        $this->assertNotNull($designRequest->submitted_at);
        $this->assertSame('優しい雰囲気の星読み司書', $designRequest->form_data['one_line']);

        $candidateImages = collect(range(1, 4))
            ->map(fn (int $number) => $this->fakePng("candidate-{$number}.png"))
            ->all();

        $this->actingAs($admin)
            ->post(route('admin.character-icon-design.messages.store', $designRequest), [
                'body' => '候補を4案用意しました。残したい方向を教えてください。',
                'attachments' => $candidateImages,
            ])
            ->assertRedirect(route('admin.character-icon-design.show', $designRequest));

        $this->assertDatabaseHas('character_icon_design_messages', [
            'character_icon_design_request_id' => $designRequest->id,
            'sender_type' => 'admin',
            'admin_user_id' => $admin->id,
            'body' => '候補を4案用意しました。残したい方向を教えてください。',
        ]);
        $this->assertDatabaseCount('character_icon_design_message_attachments', 4);
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $character->id,
            'type' => 'character_icon_design_message',
        ]);
        $this->get(route('admin.character-icon-design.show', $designRequest))
            ->assertOk()
            ->assertSee('候補提示・微調整チャット')
            ->assertSee('候補を4案用意しました。残したい方向を教えてください。')
            ->assertSee('AIプロンプト用にコピー')
            ->assertSee('イメージ優先度：世界観重視')
            ->assertSee('絶対に入れたい要素：星形の髪飾り')
            ->assertSee('候補画像')
            ->assertSee('画像は1枚ずつ追加できます。')
            ->assertSee('data-multi-image-picker', false)
            ->assertSee('data-multi-image-input', false)
            ->assertSee('data-attachment-number="1"', false)
            ->assertSee('data-attachment-number="4"', false)
            ->assertSee('1番：candidate-1.png');
        $this->patch(route('admin.character-icon-design.status.update', $designRequest), [
            'status' => 'in_progress',
        ])->assertRedirect(route('admin.character-icon-design.show', $designRequest));
        $this->assertDatabaseHas('character_notifications', [
            'character_id' => $character->id,
            'type' => 'character_icon_design_status',
            'body' => '現在の状態: 候補制作中',
        ]);

        $this->actingAs($player)
            ->get(route('character-icon-design.show'))
            ->assertOk()
            ->assertSee('候補を4案用意しました。残したい方向を教えてください。')
            ->assertSee('candidate-1.png')
            ->assertSee('data-attachment-number="1"', false)
            ->assertSee('data-attachment-number="4"', false)
            ->assertSee('4番：candidate-4.png')
            ->assertSee('参考画像')
            ->assertSee('画像は1枚ずつ追加できます。')
            ->assertDontSee('使用したい場面')
            ->assertDontSee('ゲーム内アバター');

        $this->post(route('character-icon-design.messages.store', $designRequest), [
            'body' => '2番の髪型を残して、衣装を青くしてください。',
        ])->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]));

        $this->assertDatabaseHas('character_icon_design_messages', [
            'character_icon_design_request_id' => $designRequest->id,
            'sender_type' => 'player',
            'body' => '2番の髪型を残して、衣装を青くしてください。',
            'read_by_admin_at' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.character-icon-design.index'))
            ->assertOk()
            ->assertSee('未読返信 1');

        $this->patch(route('admin.character-icon-design.status.update', $designRequest), [
            'status' => 'completed',
        ])->assertRedirect(route('admin.character-icon-design.show', $designRequest));

        $this->get(route('admin.character-icon-design.show', $designRequest))
            ->assertOk()
            ->assertSee('制作完了後も、このチャットで冒険者と連絡できます。')
            ->assertSee('冒険者へのメッセージ');

        $this->post(route('admin.character-icon-design.messages.store', $designRequest), [
            'body' => '公開後の連絡です。',
        ])->assertRedirect(route('admin.character-icon-design.show', $designRequest));

        $this->actingAs($player)
            ->get(route('character-icon-design.show', ['request' => $designRequest->id]))
            ->assertOk()
            ->assertSee('必要な連絡は引き続きこのチャットで行えます。')
            ->assertSee('管理人へのメッセージ');

        $this->post(route('character-icon-design.messages.store', $designRequest), [
            'body' => '確認しました。',
        ])->assertRedirect(route('character-icon-design.show', ['request' => $designRequest->id]));

        $this->assertDatabaseHas('character_icon_design_messages', [
            'character_icon_design_request_id' => $designRequest->id,
            'sender_type' => 'admin',
            'body' => '公開後の連絡です。',
        ]);
        $this->assertDatabaseHas('character_icon_design_messages', [
            'character_icon_design_request_id' => $designRequest->id,
            'sender_type' => 'player',
            'body' => '確認しました。',
        ]);
    }

    public function test_private_candidate_images_cannot_be_read_by_another_player(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin']);
        [$owner, $ownerCharacter] = $this->createPlayer('画像の持ち主', 40, 0);
        [$other] = $this->createPlayer('別の冒険者');

        $this->actingAs($owner)->post(route('character-icon-design.form.save'), [
            ...$this->validFormPayload(),
            'intent' => 'confirm',
        ]);
        $this->post(route('character-icon-design.form.submit'));
        $designRequest = CharacterIconDesignRequest::query()
            ->where('character_id', $ownerCharacter->id)
            ->firstOrFail();

        $this->actingAs($admin)->post(
            route('admin.character-icon-design.messages.store', $designRequest),
            ['attachments' => [$this->fakePng('private-candidate.png')]]
        );
        $attachment = CharacterIconDesignMessageAttachment::query()->firstOrFail();

        $this->actingAs($owner)
            ->get(route('character-icon-design.attachments.show', $attachment))
            ->assertOk();
        $this->actingAs($other)
            ->get(route('character-icon-design.attachments.show', $attachment))
            ->assertNotFound();
        $this->actingAs($admin)
            ->get(route('admin.character-icon-design.attachments.show', $attachment))
            ->assertOk();
    }

    /**
     * @return array{0: User, 1: Character}
     */
    private function createPlayer(
        string $characterName,
        int $freeKiseki = 0,
        int $paidKiseki = 0,
    ): array {
        $user = User::factory()->create();
        $character = Character::query()->create([
            'user_id' => $user->id,
            'name' => $characterName,
            'free_kiseki' => $freeKiseki,
            'paid_kiseki' => $paidKiseki,
            'kiseki' => $freeKiseki + $paidKiseki,
            'explore_stamina' => 0,
        ]);
        $valmonMaster = ValmonMaster::query()->create([
            'valmon_key' => 'icon-design-test-'.$character->id,
            'name' => '制作確認モン',
            'rarity' => 'normal',
            'is_active' => true,
        ]);
        PlayerValmon::query()->create([
            'character_id' => $character->id,
            'valmon_master_id' => $valmonMaster->id,
            'is_partner' => true,
            'obtained_at' => now(),
        ]);

        return [$user, $character];
    }

    private function validFormPayload(): array
    {
        return [
            'usage_scenes' => ['game_avatar'],
            'priority' => 'world',
            'gender' => 'neutral',
            'age' => 'omakase',
            'body_type' => 'omakase',
            'atmosphere' => ['gentle', 'mysterious'],
            'hair_color' => 'silver',
            'hairstyles' => ['long'],
            'face_impression' => 'soft',
            'additional_elements' => ['hair_accessory'],
            'role' => 'scholar',
            'role_other' => null,
            'region' => 'elfia',
            'motifs' => ['star', 'forest'],
            'held_item' => 'book',
            'held_item_other' => null,
            'weapon_mood' => 'sacred',
            'outfit_directions' => ['robe'],
            'main_color_1' => '深い青',
            'main_color_2' => '銀',
            'main_color_3' => null,
            'avoid_colors' => '蛍光色',
            'expression' => 'gentle_smile',
            'personalities' => ['gentle', 'calm'],
            'must_have' => '星形の髪飾り',
            'ng_elements' => '重装は避けてください',
            'reference_mood' => 'エルフィアの静かな図書館',
            'one_line' => '優しい雰囲気の星読み司書',
        ];
    }

    private function fakePng(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
    }
}
