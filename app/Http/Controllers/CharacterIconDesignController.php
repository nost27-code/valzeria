<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CharacterIconDesignMessageAttachment;
use App\Models\CharacterIconDesignRequest;
use App\Services\CharacterIconDesignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CharacterIconDesignController extends Controller
{
    private const ARRAY_FIELDS = [
        'usage_scenes',
        'atmosphere',
        'hairstyles',
        'additional_elements',
        'motifs',
        'outfit_directions',
        'personalities',
    ];

    public function show(Request $request, CharacterIconDesignService $service)
    {
        $character = $this->authorizedCharacter($service);
        $draftRequest = $service->draftFor($character);
        $submittedRequests = $service->submittedRequestsFor($character);
        $selectedRequestId = $request->integer('request');
        $requestedView = (string) $request->query('view', '');
        $viewMode = match (true) {
            $selectedRequestId > 0 => 'submitted',
            in_array($requestedView, ['new', 'submitted'], true) => $requestedView,
            $draftRequest !== null => 'new',
            $submittedRequests->isNotEmpty() => 'submitted',
            default => 'new',
        };
        $designRequest = $viewMode === 'new'
            ? $draftRequest
            : ($selectedRequestId > 0
                ? $submittedRequests->firstWhere('id', $selectedRequestId)
                : $submittedRequests->first());

        if ($selectedRequestId > 0 && ! $designRequest) {
            abort(404);
        }

        if ($viewMode === 'submitted' && $designRequest?->isChatOpen()) {
            $service->markAdminMessagesRead($designRequest);
            $designRequest->load([
                'messages' => fn ($query) => $query->with('attachments')->orderBy('id'),
            ]);
        }

        return view('character-icon-design.show', [
            'character' => $character,
            'designRequest' => $designRequest,
            'draftRequest' => $draftRequest,
            'submittedRequests' => $submittedRequests,
            'viewMode' => $viewMode,
            'totalKiseki' => (int) ($character->free_kiseki ?? 0)
                + (int) ($character->paid_kiseki ?? 0),
        ]);
    }

    public function saveForm(Request $request, CharacterIconDesignService $service)
    {
        $character = $this->authorizedCharacter($service);

        if (! $request->filled('intent')) {
            $request->merge(['intent' => 'draft']);
        }

        $intent = $request->validate([
            'intent' => ['required', 'string', Rule::in(['draft', 'confirm'])],
        ], [
            'intent.required' => '保存方法を確認できませんでした。もう一度お試しください。',
            'intent.string' => '保存方法の指定を確認してください。',
            'intent.in' => '保存方法の指定を確認してください。',
        ])['intent'];
        $confirm = $intent === 'confirm';
        $validated = $request->validate(
            $this->formRules($confirm, $request->all()),
            $this->formMessages(),
            $this->formAttributes()
        );
        $formData = $this->formData($validated);
        $result = $service->saveForm($character, $formData, false);

        if ($request->expectsJson()) {
            return response()->json($result, $result['success'] ? 200 : 422);
        }

        if ($confirm && $result['success']) {
            return redirect()->route('character-icon-design.form.confirm');
        }

        return redirect()
            ->route('character-icon-design.show')
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function confirm(CharacterIconDesignService $service)
    {
        $character = $this->authorizedCharacter($service);
        $designRequest = $service->draftFor($character);

        if (
            ! $designRequest
            || ! in_array($designRequest->status, ['eligible', 'draft'], true)
            || blank($designRequest->form_data)
        ) {
            return redirect()
                ->route('character-icon-design.show')
                ->with('error', '確認できるヒアリング内容がありません。');
        }

        return view('character-icon-design.confirm', [
            'character' => $character,
            'designRequest' => $designRequest,
            'totalKiseki' => (int) ($character->free_kiseki ?? 0)
                + (int) ($character->paid_kiseki ?? 0),
        ]);
    }

    public function submit(CharacterIconDesignService $service)
    {
        $character = $this->authorizedCharacter($service);
        $designRequest = $service->draftFor($character);

        if (! $designRequest || ! in_array($designRequest->status, ['eligible', 'draft'], true)) {
            return redirect()
                ->route('character-icon-design.show')
                ->with('error', '提出できるヒアリングシートがありません。');
        }

        $formData = (array) $designRequest->form_data;
        $validator = Validator::make(
            $formData,
            $this->formRules(true, $formData),
            $this->formMessages(),
            $this->formAttributes()
        );

        if ($validator->fails()) {
            return redirect()
                ->route('character-icon-design.show')
                ->withErrors($validator)
                ->withInput($formData);
        }

        $result = $service->saveForm(
            $character,
            $this->formData($validator->validated()),
            true,
            $designRequest->id,
        );
        $routeParameters = $result['success']
            ? ['request' => $designRequest->id]
            : ['view' => 'new'];

        return redirect()
            ->route('character-icon-design.show', $routeParameters)
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function sendMessage(
        Request $request,
        CharacterIconDesignRequest $designRequest,
        CharacterIconDesignService $service,
    ) {
        $character = $this->authorizedCharacter($service);
        abort_unless((int) $designRequest->character_id === (int) $character->id, 404);

        $validated = $request->validate($this->messageRules(), $this->messageValidationMessages());
        $result = $service->addMessage(
            $designRequest,
            'player',
            $validated['body'] ?? null,
            $request->file('attachments', []),
        );

        return redirect()
            ->route('character-icon-design.show', ['request' => $designRequest->id])
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function attachment(
        CharacterIconDesignMessageAttachment $attachment,
        CharacterIconDesignService $service,
    ) {
        $character = $this->authorizedCharacter($service);
        $attachment->load('message.designRequest');

        abort_unless(
            (int) $attachment->message?->designRequest?->character_id === (int) $character->id,
            404
        );
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']
        );
    }

    private function authorizedCharacter(CharacterIconDesignService $service): Character
    {
        $character = Auth::user()->currentCharacter();
        abort_unless($service->canAccess($character), 404);

        return $character;
    }

    private function formRules(bool $submit, array $input = []): array
    {
        $required = $submit ? ['required'] : ['nullable'];
        $requiredArray = $submit ? ['required', 'array', 'min:1'] : ['nullable', 'array'];
        $options = config('character_icon_design.options', []);

        return [
            'usage_scenes' => [...$requiredArray, 'max:1'],
            'usage_scenes.*' => [Rule::in(array_keys($options['usage_scenes'] ?? []))],
            'priority' => [...$required, 'string', Rule::in(array_keys($options['priority'] ?? []))],
            'gender' => [...$required, 'string', Rule::in(array_keys($options['gender'] ?? []))],
            'age' => [...$required, 'string', Rule::in(array_keys($options['age'] ?? []))],
            'body_type' => ['nullable', 'string', Rule::in(array_keys($options['body_type'] ?? []))],
            'atmosphere' => [...$requiredArray, 'max:5'],
            'atmosphere.*' => [Rule::in(array_keys($options['atmosphere'] ?? []))],
            'hair_color' => ['nullable', 'string', Rule::in(array_keys($options['hair_color'] ?? []))],
            'hairstyles' => ['nullable', 'array', 'max:5'],
            'hairstyles.*' => [Rule::in(array_keys($options['hairstyles'] ?? []))],
            'face_impression' => ['nullable', 'string', Rule::in(array_keys($options['face_impression'] ?? []))],
            'additional_elements' => ['nullable', 'array', 'max:6'],
            'additional_elements.*' => [Rule::in(array_keys($options['additional_elements'] ?? []))],
            'role' => [...$required, 'string', Rule::in(array_keys($options['role'] ?? []))],
            'role_other' => [
                Rule::requiredIf($submit && ($input['role'] ?? null) === 'other'),
                'nullable',
                'string',
                'max:200',
            ],
            'region' => [...$required, 'string', Rule::in(array_keys($options['region'] ?? []))],
            'motifs' => [...$requiredArray, 'max:6'],
            'motifs.*' => [Rule::in(array_keys($options['motifs'] ?? []))],
            'held_item' => [...$required, 'string', Rule::in(array_keys($options['held_item'] ?? []))],
            'held_item_other' => [
                Rule::requiredIf($submit && ($input['held_item'] ?? null) === 'other'),
                'nullable',
                'string',
                'max:200',
            ],
            'weapon_mood' => ['nullable', 'string', Rule::in(array_keys($options['weapon_mood'] ?? []))],
            'outfit_directions' => ['nullable', 'array', 'max:5'],
            'outfit_directions.*' => [Rule::in(array_keys($options['outfit_directions'] ?? []))],
            'main_color_1' => [...$required, 'string', 'max:50'],
            'main_color_2' => ['nullable', 'string', 'max:50'],
            'main_color_3' => ['nullable', 'string', 'max:50'],
            'avoid_colors' => ['nullable', 'string', 'max:200'],
            'expression' => ['nullable', 'string', Rule::in(array_keys($options['expression'] ?? []))],
            'personalities' => ['nullable', 'array', 'max:5'],
            'personalities.*' => [Rule::in(array_keys($options['personalities'] ?? []))],
            'must_have' => ['nullable', 'string', 'max:2000'],
            'ng_elements' => [...$required, 'string', 'max:2000'],
            'reference_mood' => ['nullable', 'string', 'max:2000'],
            'one_line' => [...$required, 'string', 'max:300'],
        ];
    }

    private function formData(array $validated): array
    {
        $formData = [];

        foreach (config('character_icon_design.display_fields', []) as $field) {
            $key = (string) $field['key'];
            $formData[$key] = $validated[$key]
                ?? (in_array($key, self::ARRAY_FIELDS, true) ? [] : null);
        }

        return $formData;
    }

    private function formMessages(): array
    {
        return [
            'required' => ':attributeを入力または選択してください。',
            'required_if' => ':attributeを入力してください。',
            'required_without' => ':attributeを入力してください。',
            'array' => ':attributeの選択内容を確認してください。',
            'min' => ':attributeを1つ以上選択してください。',
            'max' => ':attributeの入力数または文字数が上限を超えています。',
            'in' => ':attributeの選択内容を確認してください。',
            'usage_scenes.required' => '使用したい場面を1つ以上選択してください。',
            'atmosphere.required' => '雰囲気を1つ以上選択してください。',
            'motifs.required' => 'モチーフを1つ以上選択してください。',
        ];
    }

    private function formAttributes(): array
    {
        $attributes = [];

        foreach (config('character_icon_design.display_fields', []) as $field) {
            $key = (string) $field['key'];
            $attributes[$key] = (string) $field['label'];

            if (in_array($key, self::ARRAY_FIELDS, true)) {
                $attributes[$key.'.*'] = (string) $field['label'];
            }
        }

        return $attributes;
    }

    private function messageRules(): array
    {
        $maxFiles = (int) config('character_icon_design.max_message_attachments', 4);
        $maxKilobytes = (int) config('character_icon_design.max_attachment_kilobytes', 5120);

        return [
            'body' => ['nullable', 'string', 'max:3000', 'required_without:attachments'],
            'attachments' => ['nullable', 'array', 'max:'.$maxFiles],
            'attachments.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:'.$maxKilobytes,
            ],
        ];
    }

    private function messageValidationMessages(): array
    {
        return [
            'body.required_without' => 'メッセージまたは画像を追加してください。',
            'attachments.max' => '画像は4枚まで添付できます。',
            'attachments.*.image' => '画像ファイルのみ添付できます。',
            'attachments.*.max' => '画像1枚の容量は5MBまでです。',
        ];
    }
}
