<?php

namespace App\Services;

use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MarketController;
use App\Livewire\MainScreen;
use App\Models\User;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GameHealthCheckService
{
    public const REQUEST_ATTRIBUTE = 'valzeria_health_probe';

    /** @return array{ok:bool,checked_at:string,checks:array<int, array{key:string,label:string,ok:bool,milliseconds:int}>} */
    public function check(): array
    {
        $checks = [
            $this->run('core', 'ゲーム本体', fn (): mixed => DB::select('select 1')),
        ];

        if (!$checks[0]['ok']) {
            return $this->resultWithUnavailableScreens($checks);
        }

        try {
            $user = User::query()->whereHas('characters')->orderBy('id')->first();
            if (!$user instanceof User || $user->characters()->doesntExist()) {
                throw new RuntimeException('監視用の冒険者を取得できませんでした。');
            }

            $checks[] = $this->run('home', 'ホーム', fn (): mixed => $this->probeMainScreen($user, 'home'));
            $checks[] = $this->run('explore', '探索', fn (): mixed => $this->probeMainScreen($user, 'dungeon'));
            $checks[] = $this->run('equipment', '装備', fn (): mixed => $this->probeController($user, EquipmentController::class));
            $checks[] = $this->run('inventory', '持ち物', fn (): mixed => $this->probeController($user, InventoryController::class));
            $checks[] = $this->run('market', '市場', fn (): mixed => $this->probeController($user, MarketController::class));
        } catch (\Throwable) {
            foreach ([
                ['home', 'ホーム'],
                ['explore', '探索'],
                ['equipment', '装備'],
                ['inventory', '持ち物'],
                ['market', '市場'],
            ] as [$key, $label]) {
                $checks[] = ['key' => $key, 'label' => $label, 'ok' => false, 'milliseconds' => 0];
            }
        }

        return [
            'ok' => collect($checks)->every(fn (array $check): bool => $check['ok']),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /** @param array<int, array{key:string,label:string,ok:bool,milliseconds:int}> $checks
     *  @return array{ok:false,checked_at:string,checks:array<int, array{key:string,label:string,ok:bool,milliseconds:int}>}
     */
    private function resultWithUnavailableScreens(array $checks): array
    {
        foreach ([
            ['home', 'ホーム'],
            ['explore', '探索'],
            ['equipment', '装備'],
            ['inventory', '持ち物'],
            ['market', '市場'],
        ] as [$key, $label]) {
            $checks[] = ['key' => $key, 'label' => $label, 'ok' => false, 'milliseconds' => 0];
        }

        return ['ok' => false, 'checked_at' => now()->toIso8601String(), 'checks' => $checks];
    }

    /** @return array{key:string,label:string,ok:bool,milliseconds:int} */
    private function run(string $key, string $label, Closure $probe): array
    {
        $startedAt = microtime(true);

        try {
            $probe();

            return [
                'key' => $key,
                'label' => $label,
                'ok' => true,
                'milliseconds' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'key' => $key,
                'label' => $label,
                'ok' => false,
                'milliseconds' => (int) round((microtime(true) - $startedAt) * 1000),
            ];
        }
    }

    private function probeMainScreen(User $user, string $location): void
    {
        $this->withProbeUser($user, function () use ($location): void {
            request()->attributes->set(self::REQUEST_ATTRIBUTE, $location);
            $component = app(MainScreen::class);
            $component->mount();
            $view = app()->call([$component, 'render']);
            if ($view instanceof View) {
                $view->with([
                    'currentLocation' => $component->currentLocation,
                    'isIconModalOpen' => $component->isIconModalOpen,
                    'isNameModalOpen' => $component->isNameModalOpen,
                ]);
            }
            $rendered = $view instanceof View ? $view->render() : '';

            if ($rendered === '') {
                throw new RuntimeException('画面を描画できませんでした。');
            }
        });
    }

    /** @param class-string<EquipmentController|InventoryController|MarketController> $controller */
    private function probeController(User $user, string $controller): void
    {
        $this->withProbeUser($user, function () use ($controller): void {
            request()->attributes->set(self::REQUEST_ATTRIBUTE, true);
            $response = app()->call([app($controller), 'index']);

            if (!$response instanceof View && !$response instanceof Response) {
                throw new RuntimeException('画面を描画できませんでした。');
            }

            $rendered = $response instanceof View ? $response->render() : $response->getContent();
            if ($rendered === '') {
                throw new RuntimeException('画面を描画できませんでした。');
            }
        });
    }

    private function withProbeUser(User $user, Closure $callback): void
    {
        $guard = Auth::guard();
        $originalUser = $guard->user();

        try {
            $guard->setUser($user);
            $callback();
        } finally {
            request()->attributes->remove(self::REQUEST_ATTRIBUTE);
            if ($originalUser instanceof User) {
                $guard->setUser($originalUser);
            } else {
                $guard->forgetUser();
            }
        }
    }
}
