<?php

namespace App\Http\Controllers;

use App\Models\TrackingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class TrackingSessionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        abort(501); // Not implemented
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        abort(501); // Not implemented
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        abort(501); // Not implemented
    }

    /**
     * Display the specified resource.
     */
    public function show(TrackingSession $trackingSession): View
    {
        abort(501); // Not implemented
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TrackingSession $trackingSession): View
    {
        abort(501); // Not implemented
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TrackingSession $trackingSession): RedirectResponse
    {
        abort(501); // Not implemented
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TrackingSession $trackingSession): Response
    {
        abort(501); // Not implemented
    }
}
