@extends('layouts.app')
@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">{{ __('Tracks') }}</div>

                <div class="card-body">
                    @if (session('status'))
                        <div class="alert alert-success" role="alert">
                            {{ session('status') }}
                        </div>
                    @endif

                    <div class="content container">
                        <div class="page-header mb-0 pb-0 border-bottom-0">
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label">Date</label>
                                    <input
                                        type="date"
                                        class="form-control"
                                        id="trackDay">
                                </div>
                                <div class="col-md-4 col-12">
                                    <div class="mb-2">
                                        <label for="trackDate" class="form-label">Available Tracks</label>
                                        <select class="form-select" aria-label="Select tracks" id="trackDate">
                                          <option value="" selected>Select Track</option>
                                        </select>
                                    </div>                                    
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <div class="map-tracking" id="track_map"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>        
        </div>    
    </div>    
</div>

@endsection
@push('js')
    @vite([
        'resources/sass/pages/tracks.scss',
        'resources/js/pages/tracks.js',
        ])
@endpush
