<?php

namespace App\Http\Controllers;

use App\Services\JobArtPresetService;
use App\Services\JobArtService;
use App\Services\JobArtV2StarterPresetService;
use App\Services\JobArtV2OfficialPresetCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class JobArtPresetController extends Controller
{
    public function applyStarter(
        Request $request,
        string $style,
        JobArtV2StarterPresetService $starterPresetService,
        JobArtV2OfficialPresetCatalog $officialPresetCatalog,
        JobArtService $jobArtService,
    ) {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character && $starterPresetService->enabledFor($character), 404);
        $data = $request->validate([
            'lineage' => ['required', 'string', Rule::in($officialPresetCatalog->lineages())],
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
            'variant' => ['required', 'string', Rule::in(['advanced', 'super', 'crown'])],
        ]);

        $starterPresetService->apply($character, $data['lineage'], $style, $data['slot_context'], $data['variant']);

        return back()->with('message', '公式プリセットを現在の戦技セットへ適用しました。順番や構成は続けて調整できます。');
    }

    public function store(Request $request, JobArtPresetService $presetService, JobArtService $jobArtService)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character && $presetService->enabledFor($character), 404);

        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:20'],
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
        ]);

        $presetService->createFromCurrentLoadout($character, $data['name'], $data['slot_context']);

        return back()->with('message', '現在の戦技構成をマイプリセットへ保存しました。');
    }

    public function apply(Request $request, int $preset, JobArtPresetService $presetService, JobArtService $jobArtService)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character && $presetService->enabledFor($character), 404);
        $data = $request->validate([
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
        ]);

        $presetService->apply($character, $preset, $data['slot_context']);

        return back()->with('message', 'マイプリセットを現在の戦技セットへ適用しました。');
    }

    public function update(Request $request, int $preset, JobArtPresetService $presetService)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character && $presetService->enabledFor($character), 404);

        $request->merge(['name' => trim((string) $request->input('name'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:20']]);
        $presetService->rename($character, $preset, $data['name']);

        return back()->with('message', 'マイプリセット名を変更しました。');
    }

    public function overwrite(Request $request, int $preset, JobArtPresetService $presetService, JobArtService $jobArtService)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character && $presetService->enabledFor($character), 404);
        $data = $request->validate([
            'slot_context' => ['required', 'string', Rule::in($jobArtService->slotContexts())],
        ]);

        $presetService->overwriteFromCurrentLoadout($character, $preset, $data['slot_context']);

        return back()->with('message', '現在の戦技構成でマイプリセットを上書き保存しました。');
    }

    public function destroy(int $preset, JobArtPresetService $presetService)
    {
        $character = Auth::user()?->currentCharacter();
        abort_unless($character && $presetService->enabledFor($character), 404);
        $presetService->delete($character, $preset);

        return back()->with('message', 'マイプリセットを削除しました。');
    }
}
