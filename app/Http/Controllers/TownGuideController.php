<?php

namespace App\Http\Controllers;

class TownGuideController extends Controller
{
    public function index()
    {
        return view('town.guide.index');
    }
}
