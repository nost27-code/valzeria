<?php

namespace App\Http\Controllers;

use App\Services\JobArtUpdateHistoryService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class JobArtUpdateHistoryController extends Controller
{
    public function __invoke(Request $request, JobArtUpdateHistoryService $history): View
    {
        $groups = $history->forDisplay(app()->environment(['local', 'testing']));
        $page = max(1, $request->integer('page', 1));
        $updates = new LengthAwarePaginator($groups->forPage($page, 10)->values(), $groups->count(), 10, $page, [
            'path' => route('job-arts.updates'),
        ]);

        return view('job-arts.update-history', compact('updates'));
    }
}
