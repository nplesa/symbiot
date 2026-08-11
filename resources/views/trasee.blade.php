@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">{{ __('Trasee') }}</div>
                    <div class="card-body">
                        <livewire:trasee.index />
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('css')
    @vite('resources/sass/pages/traseu.scss')
@endpush

@push('js')
    @vite('resources/js/pages/traseu.js')
@endpush
