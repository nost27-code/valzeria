<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthService
{
    /**
     * Google等から取得したユーザー情報でログイン（または作成）する
     */
    public function findOrCreateUser($googleId, $name, $email, $avatarUrl)
    {
        $user = User::where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (!$user) {
            $user = User::create([
                'google_id' => $googleId,
                'name' => $name,
                'email' => $email,
                'avatar_url' => $avatarUrl,
                // パスワードは不要なため設定しない
            ]);
            app(PlayerLifecycleEventService::class)->recordRegistration($user);
        } else {
            // google_idが未設定の場合は更新
            if (empty($user->google_id)) {
                $user->google_id = $googleId;
                $user->avatar_url = $avatarUrl;
                $user->save();
            }
        }

        return $user;
    }

    /**
     * ダミーユーザーを作成してログイン状態にする（開発用モック）
     */
    public function mockLogin()
    {
        $user = $this->findOrCreateUser(
            'mock_google_id_12345',
            'テスト冒険者',
            'test@example.com',
            'https://ui-avatars.com/api/?name=テスト'
        );

        Auth::login($user);

        return $user;
    }

    /**
     * アカウント連携なしのゲストユーザーを作成してログイン状態にする
     */
    public function guestLogin()
    {
        $guestId = Str::uuid()->toString();
        $user = User::create([
            'google_id' => null,
            'name' => 'ゲスト冒険者',
            'email' => "guest_{$guestId}@example.com",
            'avatar_url' => "https://ui-avatars.com/api/?name=Guest&background=random",
        ]);
        app(PlayerLifecycleEventService::class)->recordRegistration($user);

        Auth::login($user);

        return $user;
    }
}
