@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">{{ __('Account pending approval') }}</div>
                    <div class="card-body">
                        @if (auth()->user()->isApproved())
                            <p>{{ __('Your account has been approved.') }}</p>
                            <a class="btn btn-primary" href="{{ route('app.home') }}">{{ __('Continue') }}</a>
                        @else
                            <p class="mb-0">{{ __('Your account has been created, but an administrator must approve it before you can use the app. Come back once you receive the confirmation.') }}</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection