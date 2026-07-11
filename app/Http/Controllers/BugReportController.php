<?php

namespace App\Http\Controllers;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
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

    public function store(Request $request)
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ], [
            'body.required' => '不具合の内容を入力してください。',
            'body.min' => '状況が分かるよう、10文字以上で入力してください。',
            'attachments.max' => '画像は5枚まで添付できます。',
            'attachments.*.uploaded' => '画像のアップロードに失敗しました。画像1枚は5MB以内で選び直してください。',
            'attachments.*.image' => '画像ファイルのみ添付できます。',
            'attachments.*.max' => '画像1枚の容量は5MBまでです。',
        ]);

        $user = Auth::user();
        $character = $user->currentCharacter();

        DB::transaction(function () use ($request, $validated, $user, $character): void {
            $report = BugReport::create([
                'user_id' => $user->id,
                'character_id' => $character?->id,
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
        });

        return redirect()
            ->route('bug-reports.create')
            ->with('status', '不具合報告を受け付けました。ご協力ありがとうございます。');
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
