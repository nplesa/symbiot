{{--
    Pages outside the app shell: login, register, password pages (guests) and, signed in, the pending
    approval and e-mail verification pages. Same look as the app (brand, theme, languages).
    $authenticated: the links on the right (Dashboard / Logout instead of Login / Register).
--}}
<div class="auth-shell" id="authShell">
    {{-- The saved or system theme, before the page paints (same key as the app). --}}
    <script>
        (function (shell) {
            var theme = null;
            try { theme = window.localStorage.getItem('symbiot-theme'); } catch (e) {}
            if (theme !== 'dark' && theme !== 'light') {
                theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            }
            shell.dataset.theme = theme;
        })(document.getElementById('authShell'));
    </script>

    <header class="auth-topbar">
        <a href="{{ url('/') }}" class="auth-brand">
            <span class="auth-brand-icon"><i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i></span>
            <span>SYMBIOT</span>
        </a>

        <nav class="auth-nav" aria-label="{{ __('Account') }}">
            @if ($authenticated)
                <a class="auth-nav-link" href="{{ route('app.home') }}">{{ __('Dashboard') }}</a>
                <form action="{{ route('logout') }}" method="POST" class="d-inline">
                    @csrf
                    <button type="submit" class="auth-nav-link">{{ __('Logout') }}</button>
                </form>
            @else
                @if (Route::has('login'))
                    <a class="auth-nav-link @if (request()->routeIs('login')) is-active @endif" href="{{ route('login') }}" @if (request()->routeIs('login')) aria-current="page" @endif>{{ __('Login') }}</a>
                @endif
                @if (Route::has('register'))
                    <a class="auth-nav-link @if (request()->routeIs('register')) is-active @endif" href="{{ route('register') }}" @if (request()->routeIs('register')) aria-current="page" @endif>{{ __('Register') }}</a>
                @endif
            @endif
        </nav>
    </header>

    <main class="auth-main">
        @yield('content')
    </main>

    <footer class="auth-footer">
        <span class="auth-footer-label">{{ __('Language') }}</span>
        <div class="auth-languages">
            @include('layouts.partials.language-links')
        </div>
    </footer>
</div>
