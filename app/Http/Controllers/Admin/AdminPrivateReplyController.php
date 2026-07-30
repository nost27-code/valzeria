<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PublicLog;
use App\Services\PublicLogService;
use Illuminate\Http\JsonResponse;

class AdminPrivateReplyController extends Controller
{
    public function resolve(PublicLog $reply, PublicLogService $logService): JsonResponse
    {
        if (!$logService->resolvePendingAdminReply($reply)) {
            return response()
                ->json([
                    'resolved' => false,
                    'message' => 'この返信はすでに対応済みか、より新しい返信があります。',
                ], 409)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        return response()
            ->json([
                'resolved' => true,
                'message' => '通知を対応済みにしました。会話履歴は残っています。',
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
