<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GameSettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Exception;

class AuthController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    /**
     * 現在ログイン中のゲストデータへGoogleログインを追加する。
     */
    public function beginGoogleLink(Request $request)
    {
        $user = Auth::user();

        if (!$this->authService->isGuestUser($user)) {
            return redirect()
                ->route('home')
                ->with('error', 'Google連携はゲストプレイ中にだけ行えます。');
        }

        $request->session()->put('auth.google_link_user_id', $user->id);

        return Socialite::driver('google')->stateless()->redirect();
    }

    public function showEmailLoginForm()
    {
        return view('auth.email-login');
    }

    public function showEmailRegisterForm()
    {
        if (Auth::check()) {
            return redirect()->route('character.select');
        }

        return view('auth.email-register', [
            'registrationOpen' => $this->registrationOpen(),
        ]);
    }

    public function emailLogin(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'メールアドレスまたはパスワードが違います。',
            ]);
        }

        $request->session()->regenerate();
        app(\App\Services\PlayerLifecycleEventService::class)->recordLogin(Auth::user());

        return redirect()->intended(route('character.select'));
    }

    public function emailRegister(Request $request)
    {
        if (!$this->registrationOpen()) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => '現在、新規登録の受付を停止しています。既存アカウントはログインできます。']);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $accountName = $this->accountNameFromEmail($validated['email']);

        $user = \App\Models\User::create([
            'name' => $accountName,
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'avatar_url' => 'https://ui-avatars.com/api/?name=' . urlencode($accountName) . '&background=random',
        ]);

        Auth::login($user);
        $request->session()->regenerate();
        app(\App\Services\PlayerLifecycleEventService::class)->recordRegistration($user);
        app(\App\Services\PlayerLifecycleEventService::class)->recordLogin($user);

        return redirect()->route('character.select');
    }

    private function accountNameFromEmail(string $email): string
    {
        $name = trim((string) str($email)->before('@'));

        return $name !== '' ? mb_substr($name, 0, 40) : 'メール冒険者';
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->stateless()->user();

            $linkUserId = $request->session()->pull('auth.google_link_user_id');
            if ($linkUserId !== null) {
                $currentUser = Auth::user();
                if (!$currentUser || (int) $currentUser->id !== (int) $linkUserId) {
                    return redirect()
                        ->route('top')
                        ->with('error', '連携元のゲストデータを確認できませんでした。もう一度お試しください。');
                }

                $this->authService->linkGuestToGoogle(
                    $currentUser,
                    (string) $googleUser->getId(),
                    (string) $googleUser->getEmail(),
                    $googleUser->getAvatar(),
                );

                $request->session()->regenerate();

                return redirect()
                    ->route('home')
                    ->with('message', 'Google連携が完了しました。冒険データはこのGoogleアカウントで引き継げます。');
            }

            $existingUser = User::where('google_id', $googleUser->getId())
                ->orWhere('email', $googleUser->getEmail())
                ->first();

            if (!$existingUser && !$this->registrationOpen()) {
                return redirect()
                    ->route('top')
                    ->with('error', '現在、新規登録の受付を停止しています。既存のGoogle連携アカウントはログインできます。');
            }
            
            $user = $this->authService->findOrCreateUser(
                $googleUser->getId(),
                $googleUser->getName(),
                $googleUser->getEmail(),
                $googleUser->getAvatar()
            );

            Auth::login($user);
            app(\App\Services\PlayerLifecycleEventService::class)->recordLogin($user);

            // ログイン成功後は必ずキャラ選択画面へ遷移する
            return redirect()->route('character.select');

        } catch (\InvalidArgumentException | \LogicException $e) {
            return redirect()
                ->route('home')
                ->with('error', $e->getMessage());
        } catch (Exception $e) {
            Log::warning('Google login failed.', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            // キャンセルされたりエラーになった場合はトップへ戻す
            return redirect()->route('top')->with('error', 'ログインに失敗しました。');
        }
    }

    public function mockLogin()
    {
        // ローカル環境以外ではモックログインを禁止する
        if (app()->environment() !== 'local') {
            abort(403, 'Unauthorized action.');
        }

        $user = $this->authService->mockLogin();
        app(\App\Services\PlayerLifecycleEventService::class)->recordLogin($user);
        
        // ログイン成功後は必ずキャラ選択画面へ遷移する
        return redirect()->route('character.select');
    }

    public function guestLogin()
    {
        if (!$this->registrationOpen()) {
            return redirect()
                ->route('top')
                ->with('error', '現在、新規登録の受付を停止しています。既存アカウントでログインしてください。');
        }

        $user = $this->authService->guestLogin();
        app(\App\Services\PlayerLifecycleEventService::class)->recordLogin($user);

        // ログイン成功後は必ずキャラ選択画面へ遷移する
        return redirect()->route('character.select');
    }

    private function registrationOpen(): bool
    {
        return app(GameSettingService::class)->getBool('auth.registration_open', true);
    }

    public function logout(Request $request)
    {
        $character = Auth::user()?->currentCharacter();
        if ($character) {
            try {
                app(\App\Services\ExplorationStateService::class)->reset($character);
            } catch (\Throwable $e) {
                // reset失敗してもログアウトは継続
            }
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = redirect()->route('auth.email.login');
        foreach ($request->cookies->keys() as $name) {
            if (str_starts_with($name, 'remember_')) {
                $response->withCookie(cookie()->forget($name));
            }
        }

        return $response;
    }
}
