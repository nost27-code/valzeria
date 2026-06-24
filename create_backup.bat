@echo off
setlocal enabledelayedexpansion

:: タイムスタンプの取得 (YYYYMMDD_HHMMSS形式)
set D=%date:/=%
set T=%time: =0%
set TIMESTAMP=%D%_%T:~0,2%%T:~3,2%%T:~6,2%

:: バックアップ先のディレクトリ設定 (プロジェクトフォルダの1つ上の backups フォルダ内に作成)
set BACKUP_DIR=..\backups\ffa_backup_%TIMESTAMP%

echo ========================================================
echo FFA プロジェクト バックアップ開始
echo ========================================================
echo.
echo 実行時刻: %date% %time%
echo バックアップ先: %BACKUP_DIR%
echo.
echo ※ vendor, node_modules などの巨大フォルダは除外します。
echo ※ データベース(database.sqlite)は含まれます。
echo.

:: バックアップ先フォルダの作成
if not exist "..\backups" mkdir "..\backups"
mkdir "%BACKUP_DIR%"

:: Robocopyを使って高速コピー (除外フォルダ指定)
:: /MIR : ミラーリングコピー (サブディレクトリ含む)
:: /XD  : 除外するディレクトリ
:: /NJH /NJS /NDL /NFL : ログ出力を最小限にしてコンソールをスッキリさせる
robocopy . "%BACKUP_DIR%" /MIR /XD vendor node_modules .git storage\framework\cache storage\framework\views /NJH /NJS /NDL /NFL

echo.
echo ========================================================
echo バックアップが完了しました！
echo ========================================================
echo.
echo 【デグレ時に元に戻す方法】
echo 1. %BACKUP_DIR% フォルダを開く
echo 2. 中にあるファイル・フォルダをすべてコピーする
echo 3. 現在のプロジェクトフォルダ (C:\Users\yuta\tool\tool\ffa) に貼り付けて上書きする
echo.

pause
