
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

    @vite(['resources/sass/app.scss', 'resources/js/app.js'])

    @livewireStyles
    @stack('css')
</head>

<body>

<div id="app">

    @auth

        @if (request()->routeIs('app.*'))

            <div class="symbiot-shell" id="symbiotShell">

                {{-- MOBILE HEADER --}}
                <header class="symbiot-mobile-header">

                    <button type="button"
                            class="symbiot-menu-toggle"
                            id="sidebarMobileToggle"
                            aria-label="Open menu"
                            aria-expanded="false"
                            aria-controls="symbiotSidebar">

                        <i class="fa-solid fa-bars"></i>

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
                                <i class="fa-solid fa-location-crosshairs"></i>
                            </span>

                            <span class="symbiot-brand-name">
                                SYMBIOT
                            </span>

                        </a>

                        <button type="button"
                                class="symbiot-sidebar-toggle"
                                id="sidebarCollapse"
                                aria-label="Collapse sidebar">

                            <i class="fa-solid fa-angles-left"></i>

                        </button>

                        <button type="button"
                                class="symbiot-mobile-close"
                                id="sidebarMobileClose"
                                aria-label="Close menu">

                            <i class="fa-solid fa-xmark"></i>

                        </button>

                    </div>

                    <div class="symbiot-sidebar-body">

                        <div class="symbiot-nav-label">
                            HARTĂ ȘI TRASEE
                        </div>

                        <nav class="symbiot-nav">

                            <a href="{{ route('app.home') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.home') ? 'active' : '' }}">

                                <i class="fa-solid fa-house"></i>
                                <span>Hartă</span>

                            </a>

                            <a href="{{ route('app.position') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.position') ? 'active' : '' }}">

                                <i class="fa-solid fa-crosshairs"></i>
                                <span>Poziția mea</span>

                            </a>

                            <a href="{{ route('app.trackings.index') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.trackings.*') ? 'active' : '' }}">

                                <i class="fa-solid fa-location-dot"></i>
                                <span>Sesiuni GPS</span>

                            </a>

                            <a href="{{ route('app.trasee.index') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.trasee.*') ? 'active' : '' }}">

                                <i class="fa-solid fa-route"></i>
                                <span>Trasee</span>

                            </a>

                        </nav>

                        <div class="symbiot-nav-label">
                            ACCOUNT
                        </div>

                        <nav class="symbiot-nav">

                            @if (Auth::user()->is_admin)

                                <a href="{{ route('app.admin.users') }}"
                                   class="symbiot-nav-link">

                                    <i class="fa-solid fa-users"></i>
                                    <span>Users</span>

                                </a>

                            @endif

                            <a href="{{ route('app.profile.edit') }}"
                               class="symbiot-nav-link {{ request()->routeIs('app.profile.*') ? 'active' : '' }}">
                                <i class="fa-solid fa-user"></i>
                                <span>Profile</span>
                            </a>

                            <button type="button"
                                    class="symbiot-nav-link"
                                    id="themeToggle">

                                <i class="fa-solid fa-moon"
                                   id="themeIcon"></i>

                                <span>Dark / Light mode</span>

                            </button>

                        </nav>

                    </div>

                    {{-- SIDEBAR FOOTER --}}
                    <div class="symbiot-sidebar-footer">

                        <div class="symbiot-user">

                            <div class="symbiot-user-avatar">
                                {{ strtoupper(substr(Auth::user()->name, 0, 1)) }}
                            </div>

                            <div class="symbiot-user-info">

                                <strong>
                                    {{ Auth::user()->name }}
                                </strong>

                                <small>
                                    {{ Auth::user()->is_admin ? 'Administrator' : 'Account' }}
                                </small>

                            </div>

                        </div>

                        <form action="{{ route('logout') }}" method="POST">
                            @csrf

                            <button type="submit"
                                    class="symbiot-nav-link symbiot-logout">

                                <i class="fa-solid fa-right-from-bracket"></i>
                                <span>Logout</span>

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
                                Esti conectat ca
                                <strong>{{ Auth::user()->name }}</strong>
                                (mod GHOST).
                            </span>

                            <form method="POST" action="{{ route('ghost.leave') }}">
                                @csrf

                                <button type="submit" class="btn btn-sm btn-dark">
                                    Revino la contul tau
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
                                    Dashboard
                                </a>
                            </li>

                            <li class="nav-item">

                                <a class="nav-link"
                                   href="{{ route('logout') }}"
                                   onclick="event.preventDefault(); this.nextElementSibling.submit();">

                                    Logout

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
                            Login
                        </a>
                    @endif

                    @if (Route::has('register'))
                        <a class="nav-link" href="{{ route('register') }}">
                            Register
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
