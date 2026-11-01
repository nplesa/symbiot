
@extends('layouts.app')

@section('content')
<div class="container-fluid profile-page">

    {{-- PAGE HEADER --}}
    <div class="profile-heading">
        <div>
            <span class="profile-eyebrow">ACCOUNT SETTINGS</span>
            <h1>My Profile</h1>
            <p>Gestionează informațiile personale și securitatea contului tău.</p>
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
            <strong>Verifică informațiile introduse.</strong>
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
                    <h2>Profile photo</h2>
                    <p>Fotografia asociată contului tău.</p>
                </div>
                <i class="fa-solid fa-camera"></i>
            </div>

            <div class="profile-avatar" id="profileAvatarPreview">
                @if($profile->avatar)
                    <img
                        src="{{ asset('storage/'.$profile->avatar) }}"
                        alt="Profile photo"
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
                    Choose photo
                </button>

                <div id="avatarFileName" class="profile-help mt-2">
                    No photo selected
                </div>

                <button
                    type="submit"
                    class="btn btn-primary w-100 mt-3"
                    id="uploadAvatarButton"
                    disabled
                >
                    <i class="fa-solid fa-upload me-2"></i>
                    Upload photo
                </button>
            </form>

            @if($profile->avatar)
                <form
                    method="POST"
                    action="{{ route('app.profile.avatar.remove') }}"
                    class="mt-2"
                    onsubmit="return confirm('Sigur dorești să elimini fotografia de profil?');"
                >
                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="btn btn-outline-secondary w-100"
                    >
                        <i class="fa-solid fa-trash me-2"></i>
                        Remove photo
                    </button>
                </form>
            @endif

            <small class="profile-help">
                JPG, PNG sau WEBP. Maximum 4 MB.
            </small>

        </section>

        {{-- PERSONAL INFORMATION --}}
        <section class="profile-card">

            <div class="profile-card-heading">
                <div>
                    <h2>Personal information</h2>
                    <p>Informațiile principale ale contului.</p>
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
                        <label for="name">Full name</label>
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
                        <label for="username">Username</label>
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
                        <label for="email">Email address</label>
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
                        <label for="phone">Phone number</label>
                        <input
                            id="phone"
                            name="phone"
                            type="tel"
                            value="{{ old('phone', $profile->phone) }}"
                            maxlength="30"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="birth_date">Date of birth</label>
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
                        <h2>Address</h2>
                        <p>Adresa ta de domiciliu sau corespondență.</p>
                    </div>
                    <i class="fa-solid fa-location-dot"></i>
                </div>

                <div class="profile-form-grid">

                    <div class="profile-field">
                        <label for="address_country">Country</label>
                        <input
                            id="address_country"
                            name="address_country"
                            type="text"
                            value="{{ old('address_country', $profile->address_country) }}"
                            maxlength="100"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="county">County / State</label>
                        <input
                            id="county"
                            name="county"
                            type="text"
                            value="{{ old('county', $profile->county) }}"
                            maxlength="100"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="city">City</label>
                        <input
                            id="city"
                            name="city"
                            type="text"
                            value="{{ old('city', $profile->city) }}"
                            maxlength="100"
                        >
                    </div>

                    <div class="profile-field">
                        <label for="postal_code">Postal code</label>
                        <input
                            id="postal_code"
                            name="postal_code"
                            type="text"
                            value="{{ old('postal_code', $profile->postal_code) }}"
                            maxlength="20"
                        >
                    </div>

                    <div class="profile-field profile-field-full">
                        <label for="street">Street and number</label>
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
                        Save changes
                    </button>
                </div>

            </form>
        </section>

        {{-- SECURITY --}}
        <section class="profile-card profile-security">

            <div class="profile-card-heading">
                <div>
                    <h2>Security</h2>
                    <p>Schimbă parola contului tău.</p>
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
                    <label for="current_password">Current password</label>
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
                        <label for="password">New password</label>
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
                            Confirm new password
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
                        Update password
                    </button>
                </div>

            </form>
        </section>

    </div>
</div>
@endsection

@push('js')
    @vite([
        'resources/sass/pages/dashboard.scss',
        'resources/sass/pages/profile.scss',
        'resources/js/pages/profile.js',
        ])
@endpush
