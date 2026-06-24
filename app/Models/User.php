<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'google_id', 'avatar_url', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the characters for the user.
     */
    public function characters()
    {
        return $this->hasMany(\App\Models\Character::class);
    }

    /**
     * 現在セッションで選択されているキャラクターを取得する。
     */
    public function currentCharacter()
    {
        $characterId = session('current_character_id');
        if ($characterId) {
            $character = $this->characters()->find($characterId);
            if ($character) {
                return $character;
            }
        }
        
        // セッションになければ最初のキャラを返す（フォールバック）
        $character = $this->characters()->first();
        if ($character) {
            session(['current_character_id' => $character->id]);
            return $character;
        }

        return null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
