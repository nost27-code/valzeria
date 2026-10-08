<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\RequestPerformanceReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class RequestPerformanceController extends Controller
{
    public function index(Request $request, RequestPerformanceReportService $reports)
    {
        $validator = Validator::make($request->query(), [
            'minutes' => ['nullable', Rule::in(array_keys(RequestPerformanceReportService::PERIODS))],
            'sort' => ['nullable', Rule::in(array_keys(RequestPerformanceReportService::SORTS))],
            'until' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'op' => ['nullable', 'regex:/\A[0-9a-f]{64}\z/'],
            'release' => ['nullable', 'regex:/\A(?:[0-9a-f]{40}|local)\z/'],
            'exploration_mode' => ['nullable', Rule::in(array_keys(RequestPerformanceReportService::EXPLORATION_MODES))],
        ]);
        abort_if($validator->fails(), 422, '集計条件が正しくありません。');
        $filters = $validator->validated();
        $minutes = (int) ($filters['minutes'] ?? 60);
        $until = isset($filters['until']) ? Carbon::createFromFormat('Y-m-d\TH:i', $filters['until'], 'Asia/Tokyo')->startOfMinute()->timestamp : time();
        abort_if($until > time() + 60 || $until < time() - 48 * 3600, 422, '終了時刻は現在から48時間以内を指定してください。');
        $sort = $filters['sort'] ?? 'db_ms';

        return response()->view('admin.request-performance', $reports->read($minutes, $until, $sort, $filters['op'] ?? null, $filters['release'] ?? '', $filters['exploration_mode'] ?? '') + [
            'minutes' => $minutes, 'sort' => $sort, 'filters' => $filters, 'periods' => RequestPerformanceReportService::PERIODS,
            'sorts' => RequestPerformanceReportService::SORTS, 'enabled' => (bool) config('request_performance.enabled'),
            'explorationModes' => RequestPerformanceReportService::EXPLORATION_MODES,
        ])->header('Cache-Control', 'no-store, private');
    }
}
