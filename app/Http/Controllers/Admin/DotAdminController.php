<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DotAdminReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DotAdminController extends Controller
{
    public function show(Request $request, DotAdminReadService $service, string $section = 'overview')
    {
        $validator = Validator::make($request->query(), [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(DotAdminReadService::STATUSES))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'only_new' => ['nullable', Rule::in(['0', '1'])],
        ]);
        abort_if($validator->fails(), 422, '検索条件が正しくありません。');
        $filters = $validator->validated();
        if ($section === 'icon-design') {
            $filters['only_new'] ??= '1';
        }

        return view('admin.dot.show', $service->read($section, $filters) + [
            'section' => $section,
            'sections' => DotAdminReadService::SECTIONS,
            'statuses' => DotAdminReadService::STATUSES,
            'filters' => $filters,
            'generatedAt' => now()->timezone('Asia/Tokyo'),
        ]);
    }

    public function iconDesign(Request $request, DotAdminReadService $service, int $id)
    {
        $validator = Validator::make($request->query(), ['page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        abort_if($validator->fails(), 422, 'ページ番号が正しくありません。');

        return view('admin.dot.icon-design', $service->iconDesign($id) + [
            'generatedAt' => now()->timezone('Asia/Tokyo'),
        ]);
    }
}
