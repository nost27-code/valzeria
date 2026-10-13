<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use App\Services\JobArtService;
use App\Services\JobArtUpdateReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobArtUpdateReadController extends Controller
{
    public function __invoke(Request $request, Skill $skill, JobArtService $arts, JobArtUpdateReadService $reads): JsonResponse
    {
        $validator = Validator::make($request->only('revision'), ['revision' => ['required', 'string', 'max:64']]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $data = $validator->validated();
        $update = $reads->updateFor($skill);
        abort_unless($update !== null, 404);
        abort_unless($data['revision'] === $update['revision'], 409, '新しい更新があります。画面を再読み込みしてください。');
        $character = $request->user()->currentCharacter();
        abort_unless($character !== null, 403);
        $accessible = false;
        foreach ($arts->slotContexts() as $context) {
            if ($arts->availableArts($character, $arts->availabilityContextForSlotContext($context))->contains('id', $skill->id)) {
                $accessible = true;
                break;
            }
        }
        abort_unless($accessible, 403);
        $reads->markRead((int) $request->user()->id, $skill, $data['revision']);

        return response()->json(['skill_id' => (int) $skill->id, 'revision' => $data['revision'], 'unread' => false]);
    }
}
