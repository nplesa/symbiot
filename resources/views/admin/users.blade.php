@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="card">
            <div class="card-header">{{ __('Administration') }} &middot; {{ __('Users') }}</div>
            <div class="card-body">
                <livewire:admin.users />
            </div>
        </div>
    </div>
@endsection

@push('css')
    @vite('resources/sass/pages/admin-users.scss')
@endpush