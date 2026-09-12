<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Services\AdminWebPushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BugReportController extends Controller
{
    public function create(Request $request)
    {
        return view('bug-reports.create', [
            'character' => Auth::user()->currentCharacter(),
            'userAgent' => $request->userAgent(),
        ]);
    }

    public function store(Request $request, AdminWebPushNotificationService $adminNotifications)
    {
        $validated = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', BugReport::KINDS)],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'kind.required' => '送りたい内容の種類を選んでください。',
            'kind.in' => '送りたい内容の種類を選び直してください。',
            'body.required' => '内容を入力してください。',
            'body.min' => '内容が分かるよう、10文字以上で入力してください。',
            'attachments.max' => '画像は5枚まで添付できます。',
            'attachments.*.uploaded' => '画像のアップロードに失敗しました。画像1枚は5MB以内で選び直してください。',
            'attachments.*.image' => '画像ファイルのみ添付できます。',
            'attachments.*.max' => '画像1枚の容量は5MBまでです。',
        ]);

        $user = Auth::user();
        $character = $user->currentCharacter();

        $report = DB::transaction(function () use ($request, $validated, $user, $character): BugReport {
            $report = BugReport::create([
                'user_id' => $user->id,
                'character_id' => $character?->id,
                'kind' => $validated['kind'],
                'body' => trim($validated['body']),
                'status' => 'new',
                'reported_url' => $request->headers->get('referer'),
                'user_agent' => $request->userAgent(),
            ]);

            foreach ($request->file('attachments', []) as $position => $file) {
                $path = $file->store("bug-reports/{$report->id}", 'local');

                BugReportAttachment::create([
                    'bug_report_id' => $report->id,
                    'disk' => 'local',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'position' => $position,
                ]);
            }

            return $report;
        });

        $adminNotifications->notifyBugReport($report);
        $statusMessage = $validated['kind'] === BugReport::KIND_SUGGESTION
            ? '改善の要望を受け付けました。届けていただきありがとうございます。今後の改善検討に活用します。'
            : '不具合報告を受け付けました。ご協力ありがとうございます。';

        return redirect()
            ->route('bug-reports.create')
            ->with('status', $statusMessage);
    }

    public function attachment(BugReportAttachment $attachment)
    {
        abort_unless(Auth::user()?->role === 'admin', 403);

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']
        );
    }
}
