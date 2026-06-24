# レシピデータインポートタスク

- [x] `docs/import_recipes/` フォルダの作成と計画書の保存
- [x] `php artisan make:model Recipe -m` を実行しモデルとマイグレーションを作成
- [x] `database/migrations/...create_recipes_table.php` にカラム定義を記述
- [x] `app/Models/Recipe.php` に `$fillable` やキャスト、リレーションを定義
- [x] `php artisan make:command ImportRecipes` を実行し、インポートコマンドを作成
- [x] `ImportRecipes` コマンド内に `docs/recipe_master` のパースと登録処理を実装
- [x] `php artisan migrate` および `php artisan ffa:import-recipes` を実行
- [x] 登録されたデータを `php artisan tinker` 等で検証
- [x] `walkthrough.md` に結果と教訓を記録
