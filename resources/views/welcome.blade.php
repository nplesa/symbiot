<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
</head>
<body class="bg-light">

    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="#">
                {{ config('app.name', 'Laravel') }}
            </a>

            <button class="navbar-toggler" type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">

                    @auth
                        <li class="nav-item">
                            <a class="nav-link" href="{{ url('/home') }}">
                                Dashboard
                            </a>
                        </li>
                    @else
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('login') }}">
                                Login
                            </a>
                        </li>

                        <li class="nav-item">
                            <a class="nav-link" href="{{ route('register') }}">
                                Register
                            </a>
                        </li>
                    @endauth

                </ul>
            </div>
        </div>
    </nav>

    <!-- Hero -->
    <section class="py-5">
        <div class="container">

            <div class="row align-items-center">
                <div class="col-12 desktop-center d-flex flex-row flex-wrap justify-content-center ">
                    <div>
                        <img src="images/iob.webp" class="img-fluid w-100" alt="" style="border-radius: 15px;"> 
                    </div>
                </div>
            </div>

        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-dark text-white py-3 position-absolute w-100 bottom-0">
        <div class="container text-center">
            <small>
                © {{ date('Y') }} Copyright by AsmoDeusINC
            </small>
        </div>
    </footer>

</body>
</html>