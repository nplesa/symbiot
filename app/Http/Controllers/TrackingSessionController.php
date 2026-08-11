<?php

namespace App\Http\Controllers;

use App\Models\TrackingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TrackingSessionController extends Controller
{
    
    public function index(): View
    {
        abort(501); 
    }

    
    public function create(): View
    {
        abort(501); 
    }

    
    public function store(Request $request): RedirectResponse
    {
        abort(501); 
    }

    
    public function show(TrackingSession $trackingSession): View
    {
        abort(501); 
    }

    
    public function edit(TrackingSession $trackingSession): View
    {
        abort(501); 
    }

    
    public function update(Request $request, TrackingSession $trackingSession): RedirectResponse
    {
        abort(501); 
    }

    
    public function destroy(TrackingSession $trackingSession): Response
    {
        abort(501); 
    }
}
