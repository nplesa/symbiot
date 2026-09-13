<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(): View
    {
        $locations = json_decode(config('services.geoapify.locations', []), true);

        return view('home', [
            'locations' => $locations,
        ]);
    }
}
