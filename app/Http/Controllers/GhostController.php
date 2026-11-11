<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GhostController extends Controller
{
    public function enter(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_if($user->is($admin) || ! $user->isApproved(), 403);

        $this->audit('ghost.enter', $request, $admin, $user);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('ghost_admin_id', $admin->id);

        return redirect()->route('app.home');
    }

    public function leave(Request $request): RedirectResponse
    {
        $adminId = $request->session()->pull('ghost_admin_id');

        abort_unless($adminId, 403);

        $admin = User::query()->whereKey($adminId)->where('is_admin', true)->whereNotNull('approved_at')->firstOrFail();

        $this->audit('ghost.leave', $request, $admin, $request->user());

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('app.admin.users');
    }

    private function audit(string $event, Request $request, User $admin, ?User $target): void
    {
        Log::channel('audit')->info($event, [
            'admin_id' => $admin->id,
            'admin_email' => $admin->email,
            'target_id' => $target?->id,
            'target_email' => $target?->email,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
    }
}
