<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'SYMBIOT') }}</title>

    <link rel="shortcut icon" href="/favicon.ico">
    <link rel="dns-prefetch" href="//fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=Nunito" rel="stylesheet">

    {{-- Translations for resources/js/i18n.js; must run before the module scripts below. --}}
    <script>
        window.SymbiotI18n = {!! json_encode([
            'locale' => app()->getLocale(),
            'messages' => (object) app('translator')->getLoader()->load(app()->getLocale(), '*', '*'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    </script>

    @vite(['resources/sass/app.scss', 'resources/js/app.js'])

    @livewireStyles
    @stack('css')
</head>

<body>

<div id="app">

    @auth

        @if (request()->routeIs('app.*'))

            <div class="symbiot-shell" id="symbiotShell">
                {{-- Applies the saved/system theme before the page paints (dashboard.js only runs after DOMContentLoaded). --}}
                <script>
                    (function (shell) {
                        var theme = null;
                        try { theme = window.localStorage.getItem('symbiot-theme'); } catch (e) {}
                        if (theme !== 'dark' && theme !== 'light') {
                            theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
                        }
                        shell.dataset.theme = theme;
                    })(document.getElementById('symbiotShell'));
                </script>

                {{-- MOBILE HEADER --}}
                <header class="symbiot-mobile-header">

                    <button type="button"
                            class="symbiot-menu-toggle"
                            id="sidebarMobileToggle"
                            aria-label="{{ __('Open menu') }}"
                            aria-expanded="false"
                            aria-controls="symbiotSidebar">

                        <i class="fa-solid fa-bars" aria-hidden="true"></i>

                    </button>

                    <span class="symbiot-mobile-title">
                        SYMBIOT
                    </span>

                </header>

                {{-- SIDEBAR --}}
                <aside class="symbiot-sidebar" id="symbiotSidebar">

                    <div class="symbiot-sidebar-header">

                        <a href="{{ route('app.home') }}"
                           class="symbiot-brand">

                            <span class="symbiot-brand-icon">
                                <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
                            </span>

                            <span class="symbiot-brand-name">
                                SYMBIOT
                            </span>

                        </a>

                        <button type="button"
                                class="symbiot-sidebar-toggle"
                                id="sidebarCollapse"
                                aria-label="{{ __('Collapse sidebar') }}">

                            <i class="fa-solid fa-angles-left" aria-hidden="true"></i>

                        </button>

                        <button type="button"
                                class="symbiot-mobile-close"
                                id="sidebarMobileClose"
                                aria-label="{{ __('Close menu') }}">

                            <i class="fa-solid fa-xmark" aria-hidden="true"></i>

                        </button>

                    </div>

                    <div class="symbiot-sidebar-body">

                        <div class="symbiot-nav-label">
                            {{ __('MAP AND ROUTES') }}
                        </div>

                        <nav class="symbiot-nav">

                            <a href="{{ route('app.home') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.home') ? 'active' : '' }}">

                                <i class="fa-solid fa-house" aria-hidden="true"></i>
                                <span>{{ __('Map') }}</span>

                            </a>

                            <a href="{{ route('app.position') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.position') ? 'active' : '' }}">

                                <i class="fa-solid fa-crosshairs" aria-hidden="true"></i>
                                <span>{{ __('My position') }}</span>

                            </a>

                            <a href="{{ route('app.trackings.index') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.trackings.*') ? 'active' : '' }}">

                                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                                <span>{{ __('GPS sessions') }}</span>

                            </a>

                            <a href="{{ route('app.trasee.index') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.trasee.*') ? 'active' : '' }}">

                                <i class="fa-solid fa-route" aria-hidden="true"></i>
                                <span>{{ __('Routes') }}</span>

                            </a>

                        </nav>

                        <div class="symbiot-nav-label">
                            {{ __('ACCOUNT') }}
                        </div>

                        <nav class="symbiot-nav">

                            @if (Auth::user()->is_admin)

                                <a href="{{ route('app.admin.users') }}"
                                   class="symbiot-nav-link">

                                    <i class="fa-solid fa-users" aria-hidden="true"></i>
                                    <span>{{ __('Users') }}</span>

                                </a>

                            @endif

                            <a href="{{ route('app.profile.edit') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.profile.*') ? 'active' : '' }}">
                                <i class="fa-solid fa-user" aria-hidden="true"></i>
                                <span>{{ __('Profile') }}</span>
                            </a>

                            <button type="button"
                                    class="symbiot-nav-link"
                                    id="themeToggle">

                                <i class="fa-solid fa-moon"
                                   id="themeIcon" aria-hidden="true"></i>

                                <span>{{ __('Dark / Light mode') }}</span>

                            </button>

                        </nav>

                    </div>

                    {{-- SIDEBAR FOOTER --}}
                    <div class="symbiot-sidebar-footer">

                        <!-- Subsolul sidebar-ului: Selector de limbă centrat + Utilizator curent -->
                        <div class="dashboard-sidebar-footer p-3 border-top mt-auto">
                            <div class="mb-2 text-center">
                                <span class="small text-muted d-block mb-1" style="font-size: 0.75rem;">{{ __('Language') }}</span>
                                <div class="d-flex justify-content-center align-items-center gap-2">
                                    <!-- Steag Anglia (EN) -->
                                    <a href="{{ route('lang.switch', 'en') }}" class="btn btn-sm p-1 @if(app()->getLocale() == 'en') border border-primary bg-primary bg-opacity-25 @else opacity-50 @endif" title="English" aria-label="English" hreflang="en" lang="en" @if(app()->getLocale() == 'en') aria-current="true" @endif>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 300" width="22" height="13" style="border-radius: 2px;" aria-hidden="true" focusable="false">
                                            <rect width="600" height="300" fill="#012169"/>
                                            <path d="M0,0 L600,300 M600,0 L0,300" stroke="#fff" stroke-width="60"/>
                                            <path d="M0,0 L600,300 M600,0 L0,300" stroke="#C8102E" stroke-width="40"/>
                                            <path d="M300,0 V300 M0,150 H600" stroke="#fff" stroke-width="100"/>
                                            <path d="M300,0 V300 M0,150 H600" stroke="#C8102E" stroke-width="60"/>
                                        </svg>
                                    </a>
                                    <!-- Steag România (RO) -->
                                    <a href="{{ route('lang.switch', 'ro') }}" class="btn btn-sm p-1 @if(app()->getLocale() == 'ro') border border-primary bg-primary bg-opacity-25 @else opacity-50 @endif" title="Română" aria-label="Română" hreflang="ro" lang="ro" @if(app()->getLocale() == 'ro') aria-current="true" @endif>
                                        {{-- Blue | yellow | red: each rect covers the stripes to its right. --}}
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3 2" width="22" height="13" style="border-radius: 2px;" aria-hidden="true" focusable="false">
                                            <rect width="3" height="2" fill="#CE1126"/>
                                            <rect width="2" height="2" fill="#FCD116"/>
                                            <rect width="1" height="2" fill="#002B7F"/>
                                        </svg>
                                    </a>
                                    <!-- Steag Italia (IT) -->
                                    <a href="{{ route('lang.switch', 'it') }}" class="btn btn-sm p-1 @if(app()->getLocale() == 'it') border border-primary bg-primary bg-opacity-25 @else opacity-50 @endif" title="Italiano" aria-label="Italiano" hreflang="it" lang="it" @if(app()->getLocale() == 'it') aria-current="true" @endif>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 3 2" width="22" height="13" style="border-radius: 2px;" aria-hidden="true" focusable="false">
                                            <rect width="3" height="2" fill="#CE1126"/>
                                            <rect width="2" height="2" fill="#FFFFFF"/>
                                            <rect width="1" height="2" fill="#009246"/>
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="symbiot-user">

                            <div class="symbiot-user-avatar">
                                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                            </div>

                            <div class="symbiot-user-info">

                                <strong>
                                    {{ Auth::user()->name }}
                                </strong>

                                <small>
                                    {{ Auth::user()->is_admin ? __('Administrator') : __('Account') }}
                                </small>

                            </div>

                        </div>

                        <form action="{{ route('logout') }}" method="POST">
                            @csrf

                            <button type="submit"
                                    class="symbiot-nav-link symbiot-logout">

                                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                                <span>{{ __('Logout') }}</span>

                            </button>

                        </form>

                    </div>

                </aside>

                {{-- MOBILE OVERLAY --}}
                <div class="symbiot-sidebar-overlay"
                     id="sidebarOverlay"
                     aria-hidden="true">
                </div>

                {{-- MAIN CONTENT --}}
                <main class="symbiot-main">

                    @if (session('ghost_admin_id'))

                        <div class="alert alert-warning rounded-0 mb-0
                                    d-flex flex-wrap align-items-center
                                    justify-content-between gap-2">

                            <span>
                                {{ __('Connected as') }}
                                <strong>{{ Auth::user()->name }}</strong>
                                ({{ __('GHOST mode') }}).
                            </span>

                            <form method="POST" action="{{ route('ghost.leave') }}">
                                @csrf

                                <button type="submit" class="btn btn-sm btn-dark">
                                    {{ __('Return to your account') }}
                                </button>

                            </form>

                        </div>

                    @endif

                    <div class="symbiot-content">
                        @yield('content')
                    </div>

                </main>

            </div>

        @else

            <nav class="navbar navbar-expand-md navbar-dark bg-dark shadow-sm">

                <div class="container">

                    <a class="navbar-brand" href="{{ url('/') }}">
                        {{ config('app.name', 'Laravel') }}
                    </a>

                    <button class="navbar-toggler"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#navbarSupportedContent">

                        <span class="navbar-toggler-icon"></span>

                    </button>

                    <div class="collapse navbar-collapse"
                         id="navbarSupportedContent">

                        <ul class="navbar-nav ms-auto">

                            <li class="nav-item">
                                <a class="nav-link" href="{{ route('app.home') }}">
                                    {{ __('Dashboard') }}
                                </a>
                            </li>

                            <li class="nav-item">

                                <a class="nav-link"
                                   href="{{ route('logout') }}"
                                   onclick="event.preventDefault(); this.nextElementSibling.submit();">

                                    {{ __('Logout') }}

                                </a>

                                <form action="{{ route('logout') }}"
                                      method="POST"
                                      class="d-none">
                                    @csrf
                                </form>

                            </li>

                        </ul>

                    </div>

                </div>

            </nav>

            <main class="py-4">
                @yield('content')
            </main>

        @endif

    @else

        <nav class="navbar navbar-expand-md navbar-dark bg-dark shadow-sm">

            <div class="container">

                <a class="navbar-brand" href="{{ url('/') }}">
                    {{ config('app.name', 'Laravel') }}
                </a>

                <div class="navbar-nav ms-auto">

                    @if (Route::has('login'))
                        <a class="nav-link" href="{{ route('login') }}">
                            {{ __('Login') }}
                        </a>
                    @endif

                    @if (Route::has('register'))
                        <a class="nav-link" href="{{ route('register') }}">
                            {{ __('Register') }}
                        </a>
                    @endif

                </div>

            </div>

        </nav>

        <main class="py-4">
            @yield('content')
        </main>

    @endauth

</div>

@stack('lazy_css')

@routes()

@stack('lazy_js')

@livewireScripts

@stack('js')

</body>
</html>