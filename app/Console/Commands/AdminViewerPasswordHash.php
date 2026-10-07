<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminViewerPasswordHash extends Command
{
    protected $signature = 'admin:viewer-password-hash';
    protected $description = '閲覧専用管理画面のパスワードハッシュを生成（DB変更なし）';

    public function handle(): int
    {
        $password = $this->secret('閲覧専用パスワード（16文字以上）');
        if (!is_string($password) || mb_strlen($password) < 16) {
            $this->error('16文字以上のパスワードを指定してください。');
            return self::FAILURE;
        }
        if ($password !== $this->secret('確認のため再入力')) {
            $this->error('パスワードが一致しません。');
            return self::FAILURE;
        }
        $this->line("ADMIN_VIEWER_PASSWORD_HASH='".Hash::make($password)."'");
        return self::SUCCESS;
    }
}
