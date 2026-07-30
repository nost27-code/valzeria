<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CharacterIconDesignMessageAttachment;
use App\Models\CharacterIconDesignRequest as DesignRequest;
use App\Services\CharacterIconDesignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CharacterIconDesignController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        $designRequests = DesignRequest::query()
            ->with(['character.user'])
            ->whereNotNull('submitted_at')
            ->when($search !== '', fn ($query) => $query->whereHas(
                'character',
                fn ($characterQuery) => $characterQuery->where('name', 'like', '%'.$search.'%')
            ))
            ->withCount([
                'messages as unread_player_messages_count' => fn ($query) => $query
                    ->where('sender_type', 'player')
                    ->whereNull('read_by_admin_at'),
            ])
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        return view('admin.character-icon-design.index', compact(
            'search',
            'designRequests',
        ));
    }

    public function show(DesignRequest $designRequest, CharacterIconDesignService $service)
    {
        abort_unless($designRequest->submitted_at, 404);

        $service->markPlayerMessagesRead($designRequest);
        $designRequest->load([
            'character.user',
            'messages' => fn ($query) => $query
                ->with(['attachments', 'adminUser'])
                ->orderBy('id'),
        ]);

        return view('admin.character-icon-design.show', compact('designRequest'));
    }

    public function updateStatus(
        Request $request,
        DesignRequest $designRequest,
        CharacterIconDesignService $service,
    ) {
        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in(config('character_icon_design.admin_editable_statuses', [])),
            ],
        ]);
        $result = $service->updateStatus($designRequest, $validated['status']);

        return redirect()
            ->route('admin.character-icon-design.show', $designRequest)
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function sendMessage(
        Request $request,
        DesignRequest $designRequest,
        CharacterIconDesignService $service,
    ) {
        $validated = $request->validate($this->messageRules(), [
            'body.required_without' => 'メッセージまたは候補画像を追加してください。',
            'attachments.max' => '候補画像は4枚まで添付できます。',
            'attachments.*.image' => '画像ファイルのみ添付できます。',
            'attachments.*.max' => '画像1枚の容量は5MBまでです。',
        ]);
        $result = $service->addMessage(
            $designRequest,
            'admin',
            $validated['body'] ?? null,
            $request->file('attachments', []),
            Auth::user(),
        );

        return redirect()
            ->route('admin.character-icon-design.show', $designRequest)
            ->with($result['success'] ? 'status' : 'error', $result['message']);
    }

    public function attachment(CharacterIconDesignMessageAttachment $attachment)
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']
        );
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
}
