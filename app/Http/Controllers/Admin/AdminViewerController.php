<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AdminViewerAccess;
use App\Services\Admin\AdminViewerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminViewerController extends Controller
{
    public function loginForm()
    {
        return response()->view('admin.viewer.login')->header('Cache-Control', 'private, no-store');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string', 'max:1024']]);
        $expected = AdminViewerAccess::fingerprint();
        $validEmail = hash_equals(mb_strtolower((string) config('admin_viewer.email')), mb_strtolower($credentials['email']));
        $validPassword = $expected !== null && Hash::check($credentials['password'], (string) config('admin_viewer.password_hash'));
        if (!$validEmail || !$validPassword) {
            throw ValidationException::withMessages(['email' => '閲覧用の認証情報が一致しません。']);
        }

        // Never inherit a web/admin login, including a remember-me cookie.
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('admin_viewer.fingerprint', $expected);
        return redirect()->route('admin.viewer.index');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('admin_viewer');
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.viewer.login');
    }

    public function index(AdminViewerService $service)
    {
        return view('admin.viewer.index', ['tables' => $service->catalog(), 'settings' => config('admin_viewer.settings', [])]);
    }

    public function table(Request $request, string $table, AdminViewerService $service)
    {
        $filters = $request->validate(['field' => ['nullable', 'string', 'max:100'], 'value' => ['nullable', 'string', 'max:500'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        return view('admin.viewer.table', $service->browse($table, $filters));
    }

    public function settings(string $setting, AdminViewerService $service)
    {
        abort_unless(array_key_exists($setting, config('admin_viewer.settings', [])), 404);
        return view('admin.viewer.settings', ['label' => config('admin_viewer.settings')[$setting], 'values' => $service->sanitize(config($setting, []))]);
    }

    public function attachment(string $kind, int $id)
    {
        $table = match ($kind) {
            'bug-report' => 'bug_report_attachments',
            'icon-design' => 'character_icon_design_message_attachments',
            default => abort(404),
        };
        $attachment = DB::table($table)->find($id);
        abort_unless($attachment, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public function dashboardReport(string $format)
    {
        // Only the two audited, read-only report methods are callable here.
        $dashboard = new \App\Livewire\Admin\AdminDashboard;
        return match ($format) {
            'txt' => $dashboard->downloadAiText(),
            'csv' => $dashboard->downloadCsv(),
            default => abort(404),
        };
    }
}
