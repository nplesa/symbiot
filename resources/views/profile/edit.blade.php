
@extends('layouts.app')

@section('content')
<div class="container-fluid profile-page">

    {{-- PAGE HEADER --}}
    <div class="profile-heading">
        <div>
            <span class="profile-eyebrow">{{ __('ACCOUNT SETTINGS') }}</span>
            <h1>{{ __('My Profile') }}</h1>
            <p>{{ __('Manage your personal information and account security.') }}</p>
        </div>
    </div>

    {{-- SUCCESS MESSAGE --}}
    @if(session('success'))
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check me-2"></i>
            {{ session('success') }}
        </div>
    @endif

    {{-- VALIDATION ERRORS --}}
    @if($errors->any())
        <div class="alert alert-danger">
            <strong>{{ __('Please check the information you entered.') }}</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="profile-grid">

        {{-- PROFILE PHOTO --}}
        <section class="profile-card profile-identity">

            <div class="profile-card-heading">
                <div>
                    <h2>{{ __('Profile photo') }}</h2>
                    <p>{{ __('The photo linked to your account.') }}</p>
                </div>
                <i class="fa-solid fa-camera"></i>
            </div>

            <div class="profile-avatar" id="profileAvatarPreview">
                @if($profile->avatar)
                    <img
                        src="{{ asset('storage/'.$profile->avatar) }}"
                        alt="{{ __('Profile photo') }}"
                    >
                @else
                    <span>{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                @endif
            </div>

            <form
                id="avatarUploadForm"
                method="POST"
                action="{{ route('app.profile.avatar') }}"
                enctype="multipart/form-data"
            >
                @csrf

                <input
                    type="file"
                    id="avatarInput"
                    name="avatar"
                    accept="image/jpeg,image/png,image/webp"
                    hidden
                    required
                >

                <button
                    type="button"
                    class="btn btn-outline-primary w-100"
                    id="chooseAvatar"
                >
                    <i class="fa-solid fa-image me-2"></i>
                    {{ __('Choose photo') }}
                </button>

                <div id="avatarFileName" class="profile-help mt-2">
                    {{ __('No photo selected') }}
                </div>

                <button
                    type="submit"
                    class="btn btn-primary w-100 mt-3"
                    id="uploadAvatarButton"
                    disabled
                >
                    <i class="fa-solid fa-upload me-2"></i>
                    {{ __('Upload photo') }}
                </button>
            </form>

            @if($profile->avatar)
                <form
                    method="POST"
                    action="{{ route('app.profile.avatar.remove') }}"
                    class="mt-2"
                    onsubmit="return confirm(@js(__('Are you sure you want to remove your profile photo?')));"
                >
                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn btn-outline-secondary w-100"
                    >
                        <i class="fa-solid fa-trash me-2"></i>
                        {{ __('Remove photo') }}
                    </button>
                </form>
            @endif

            <small class="profile-help">
                {{ __('JPG, PNG or WEBP. Maximum 4 MB.') }}
            </small>

        </section>

        {{-- PERSONAL INFORMATION --}}
        <section class="profile-card">

            <div class="profile-card-heading">
                <div>
                    <h2>{{ __('Personal information') }}</h2>
                    <p>{{ __('The main information of your account.') }}</p>
                </div>
                <i class="fa-solid fa-user"></i>
            </div>

            <form
                method="POST"
                action="{{ route('app.profile.update') }}"
            >
                @csrf
                @method('PUT')

                <div class="profile-form-grid">

                    <div class="profile-field">
                        <label for="name">{{ __('Full name') }}</label>
                        <input
                            id="name"
                            name="name"
                            type="text"
                            value="{{ old('name', $user->name) }}"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div class="profile-field">
                        <label for="username">{{ __('Username') }}</label>
                        <input
                            id="username"
                            name="username"
                            type="text"
                            value="{{ old('username', $profile->username) }}"
                            maxlength="50"
                            autocomplete="username"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="email">{{ __('Email address') }}</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email', $user->email) }}"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div class="profile-field">
                        <label for="profile_current_password">{{ __('Current password') }}</label>
                        <input
                            id="profile_current_password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            aria-describedby="profile_current_password_hint"
                        >
                        <small id="profile_current_password_hint" class="profile-help mt-1">
                            {{ __('Only needed if you change your email address.') }}
                        </small>
                    </div>

                    <div class="profile-field">
                        <label for="phone">{{ __('Phone number') }}</label>
                        <input
                            id="phone"
                            name="phone"
                            type="tel"
                            value="{{ old('phone', $profile->phone) }}"
                            maxlength="30"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="birth_date">{{ __('Date of birth') }}</label>
                        <input
                            id="birth_date"
                            name="birth_date"
                            type="date"
                            value="{{ old('birth_date', $profile->birth_date?->format('Y-m-d')) }}"
                        >
                    </div>

                </div>

                <div class="profile-section-divider"></div>

                {{-- ADDRESS --}}
                <div class="profile-card-heading profile-address-heading">
                    <div>
                        <h2>{{ __('Address') }}</h2>
                        <p>{{ __('Your home or mailing address.') }}</p>
                    </div>
                    <i class="fa-solid fa-location-dot"></i>
                </div>

                <div class="profile-form-grid">

                    <div class="profile-field">
                        <label for="address_country">{{ __('Country') }}</label>
                        <input
                            id="address_country"
                            name="address_country"
                            type="text"
                            value="{{ old('address_country', $profile->address_country) }}"
                            maxlength="100"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="county">{{ __('County / State') }}</label>
                        <input
                            id="county"
                            name="county"
                            type="text"
                            value="{{ old('county', $profile->county) }}"
                            maxlength="100"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="city">{{ __('City') }}</label>
                        <input
                            id="city"
                            name="city"
                            type="text"
                            value="{{ old('city', $profile->city) }}"
                            maxlength="100"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="postal_code">{{ __('Postal code') }}</label>
                        <input
                            id="postal_code"
                            name="postal_code"
                            type="text"
                            value="{{ old('postal_code', $profile->postal_code) }}"
                            maxlength="20"
                        >
                    </div>

                    <div class="profile-field profile-field-full">
                        <label for="street">{{ __('Street and number') }}</label>
                        <input
                            id="street"
                            name="street"
                            type="text"
                            value="{{ old('street', $profile->street) }}"
                            maxlength="255"
                        >
                    </div>

                </div>

                <div class="profile-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check me-2"></i>
                        {{ __('Save changes') }}
                    </button>
                </div>

            </form>
        </section>

        {{-- SECURITY --}}
        <section class="profile-card profile-security">

            <div class="profile-card-heading">
                <div>
                    <h2>{{ __('Security') }}</h2>
                    <p>{{ __('Change your account password.') }}</p>
                </div>
                <i class="fa-solid fa-shield-halved"></i>
            </div>

            <form
                method="POST"
                action="{{ route('app.profile.password') }}"
            >
                @csrf
                @method('PUT')

                <div class="profile-field">
                    <label for="current_password">{{ __('Current password') }}</label>
                    <input
                        id="current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <div class="profile-form-grid mt-3">

                    <div class="profile-field">
                        <label for="password">{{ __('New password') }}</label>
                        <input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="profile-field">
                        <label for="password_confirmation">
                            {{ __('Confirm new password') }}
                        </label>
                        <input
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                </div>

                <div class="profile-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-lock me-2"></i>
                        {{ __('Update password') }}
                    </button>
                </div>

            </form>
        </section>

    </div>
</div>
@endsection

@push('css')
    @vite('resources/sass/pages/profile.scss')
@endpush

@push('js')
    @vite('resources/js/pages/profile.js')
@endpush
