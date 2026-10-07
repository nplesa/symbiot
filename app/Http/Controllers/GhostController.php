<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GhostController extends Controller
{
    public function enter(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();

        abort_if($user->is($admin) || ! $user->isApproved(), 403);

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

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('app.admin.users');
    }
}
