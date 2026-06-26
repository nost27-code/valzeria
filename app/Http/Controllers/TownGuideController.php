<?php

namespace App\Http\Controllers;

use App\Services\HelpContentService;

class TownGuideController extends Controller
{
    public function index(HelpContentService $helpContentService)
    {
        return view('town.guide.index', [
            'helpContent' => $helpContentService->content(),
        ]);
    }
}
