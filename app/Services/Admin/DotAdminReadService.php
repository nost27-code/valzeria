<?php

namespace App\Services\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Only explicit SELECT projections; no model accessors, writes or operator actions. */
class DotAdminReadService
{
    public const SECTIONS = [
        'overview' => '運用概要',
        'players' => 'プレイヤー一覧',
        'chat' => '公開チャット',
        'reports' => 'ご意見・不具合報告',
        'icon-design' => 'キャラ制作の問い合わせ',
    ];

    public const STATUSES = [
        'new' => '未読', 'read' => '確認済み', 'resolved' => '解決済み', 'archived' => '保管済み',
    ];

    public function read(string $section, array $filters): array
    {
        if ($section === 'overview') {
            $onlineMinutes = max(1, (int) config('services.pochi_game_portal.online_window_minutes', 5));

            return ['summary' => [
                ['label' => '登録ユーザー', 'value' => DB::table('users')->count(), 'note' => '管理者・検証用を含む全ユーザー'],
                ['label' => 'キャラクター', 'value' => DB::table('characters')->count(), 'note' => '管理者・検証用を含む全キャラクター'],
                ['label' => '活動中のキャラクター', 'value' => DB::table('characters')->where('last_seen_at', '>=', now()->subMinutes($onlineMinutes))->count(), 'note' => "直近{$onlineMinutes}分に活動"],
                ['label' => '未読のご意見・不具合', 'value' => DB::table('bug_reports')->where('status', 'new')->count(), 'note' => 'この画面で読んでも未読のまま'],
                ['label' => 'キャラ制作の新着依頼', 'value' => DB::table('character_icon_design_requests')->whereNotNull('submitted_at')->where('status', 'submitted')->count(), 'note' => '制作状態が「提出済み」の依頼'],
                ['label' => 'キャラ制作の未読メッセージ', 'value' => DB::table('character_icon_design_messages as m')->join('character_icon_design_requests as r', 'r.id', '=', 'm.character_icon_design_request_id')->whereNotNull('r.submitted_at')->where('m.sender_type', 'player')->whereNull('m.read_by_admin_at')->count(), 'note' => '依頼者からの未読連絡・シート更新'],
            ]];
        }

        $query = match ($section) {
            'players' => DB::table('characters as c')
                ->leftJoin('job_classes as j', 'j.id', '=', 'c.current_job_id')
                ->leftJoin('cities as city', 'city.id', '=', 'c.current_city_id')
                ->select(['c.id', 'c.name', 'c.level', 'c.last_seen_at', 'j.name as job_name', 'city.name as city_name'])
                ->orderByDesc('c.level')->orderByDesc('c.exp')->orderByDesc('c.id'),
            'chat' => DB::table('public_logs as l')
                ->leftJoin('characters as c', 'c.id', '=', 'l.character_id')
                ->whereNull('l.receiver_id')->whereIn('l.type', ['chat', 'admin'])
                ->select(['l.id', 'l.type', 'l.message', 'l.created_at', 'c.name as character_name'])
                ->orderByDesc('l.id'),
            'reports' => DB::table('bug_reports as r')
                ->leftJoin('characters as c', 'c.id', '=', 'r.character_id')
                ->select(['r.id', 'r.kind', 'r.body', 'r.status', 'r.created_at', 'c.name as character_name'])
                ->orderByDesc('r.id'),
            'icon-design' => DB::table('character_icon_design_requests as r')
                ->leftJoin('characters as c', 'c.id', '=', 'r.character_id')
                ->whereNotNull('r.submitted_at')
                ->select(['r.id', 'r.status', 'r.submitted_at', 'r.updated_at', 'c.name as character_name'])
                ->selectSub($this->unreadIconMessages()->whereColumn('m.character_icon_design_request_id', 'r.id')->selectRaw('count(*)'), 'unread_count')
                ->orderByDesc('r.updated_at')->orderByDesc('r.id'),
        };

        $this->filter($query, $section, $filters);

        // No unbounded fetch or full log count on every browser visit.
        return ['rows' => $query->simplePaginate(50)->withQueryString()];
    }

    private function filter(Builder $query, string $section, array $filters): void
    {
        $search = trim($filters['q'] ?? '');
        if ($search !== '') {
            $query->where(function (Builder $query) use ($section, $search): void {
                $query->where('c.name', 'like', '%'.$search.'%');
                if ($section === 'players') {
                    if (ctype_digit($search)) {
                        $query->orWhere('c.id', $search);
                    }
                } elseif ($section !== 'icon-design') {
                    $query->orWhere($section === 'chat' ? 'l.message' : 'r.body', 'like', '%'.$search.'%');
                }
            });
        }
        if ($section === 'reports' && ! empty($filters['status'])) {
            $query->where('r.status', $filters['status']);
        }
        if ($section === 'icon-design' && ($filters['only_new'] ?? '1') === '1') {
            $query->where(function (Builder $query): void {
                $query->where('r.status', 'submitted')->orWhereExists(
                    $this->unreadIconMessages()->whereColumn('m.character_icon_design_request_id', 'r.id')->select('m.id')
                );
            });
        }
        if ($section !== 'players') {
            $column = match ($section) {
                'chat' => 'l.created_at', 'icon-design' => 'r.updated_at', default => 'r.created_at',
            };
            if (! empty($filters['from'])) {
                $query->where($column, '>=', $filters['from'].' 00:00:00');
            }
            if (! empty($filters['to'])) {
                $query->where($column, '<', \Illuminate\Support\Carbon::parse($filters['to'])->addDay()->startOfDay());
            }
        }
    }

    public function iconDesign(int $id): array
    {
        $design = DB::table('character_icon_design_requests as r')
            ->leftJoin('characters as c', 'c.id', '=', 'r.character_id')
            ->where('r.id', $id)->whereNotNull('r.submitted_at')
            ->first(['r.id', 'r.status', 'r.submitted_at', 'r.updated_at', 'r.form_data', 'c.name as character_name']);
        abort_unless($design, 404);

        $messages = DB::table('character_icon_design_messages')
            ->where('character_icon_design_request_id', $id)
            ->select(['id', 'sender_type', 'body', 'read_by_admin_at', 'created_at'])
            ->orderByDesc('id')->simplePaginate(50)->withQueryString();
        $attachments = DB::table('character_icon_design_message_attachments')
            ->whereIn('character_icon_design_message_id', $messages->getCollection()->pluck('id'))
            ->get(['id', 'character_icon_design_message_id', 'original_name'])
            ->groupBy('character_icon_design_message_id');

        $data = json_decode((string) $design->form_data, true) ?? [];
        $fields = [];
        foreach (config('character_icon_design.display_fields', []) as $field) {
            $raw = data_get($data, $field['key']);
            $values = is_array($raw) ? $raw : [$raw];
            $labels = [];
            foreach ($values as $value) {
                if (is_scalar($value) && (string) $value !== '') {
                    $labels[] = isset($field['options'])
                        ? config('character_icon_design.options.'.$field['options'].'.'.$value, (string) $value)
                        : (string) $value;
                }
            }
            if ($labels !== []) {
                $fields[] = ['label' => $field['label'], 'value' => implode('、', $labels)];
            }
        }

        return compact('design', 'messages', 'attachments', 'fields');
    }

    private function unreadIconMessages(): Builder
    {
        return DB::table('character_icon_design_messages as m')
            ->where('m.sender_type', 'player')->whereNull('m.read_by_admin_at');
    }
}
