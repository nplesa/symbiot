<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;

class HomeController extends Controller
{
    
    public function __construct()
    {
        $this->middleware('auth');
    }

    
    public function index()
    {
        $locations = json_decode(config('services.geoapify.locations', []), true);

        return view('home', [
            'locations' => $locations,
        ]);
    }
}
