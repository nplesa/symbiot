<?php

namespace App\Http\Controllers;

use App\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();
        $profile = $user->profile()->firstOrCreate([]);

        return view('profile.edit', compact('user', 'profile'));
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'username' => [
                'nullable',
                'string',
                'max:50',
                'alpha_dash',
                Rule::unique('user_profiles', 'username')
                    ->ignore($user->profile?->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'address_country' => ['nullable', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ]);

        if ($user->email !== $validated['email']) {
            $validated['email_verified_at'] = null;
        }

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'email_verified_at' => $validated['email'] !== $user->email
                ? null
                : $user->email_verified_at,
        ])->save();

        $user->profile()->updateOrCreate(
            ['user_id' => $user->id],
            collect($validated)->only([
                'username',
                'phone',
                'birth_date',
                'address_country',
                'county',
                'city',
                'street',
                'postal_code',
            ])->all()
        );

        return back()->with('success', 'Profilul a fost actualizat.');
    }

    public function avatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $user = $request->user();
        $profile = $user->profile()->firstOrCreate([]);

        if ($profile->avatar) {
            Storage::disk('public')->delete($profile->avatar);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $profile->update(['avatar' => $path]);

        return back()->with('success', 'Fotografia de profil a fost actualizată.');
    }

    public function removeAvatar(Request $request): RedirectResponse
    {
        $profile = $request->user()->profile;

        if ($profile?->avatar) {
            Storage::disk('public')->delete($profile->avatar);
            $profile->update(['avatar' => null]);
        }

        return back()->with('success', 'Fotografia de profil a fost eliminată.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Parola a fost schimbată.');
    }
}
