@extends('layouts.app')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">Cont în așteptare</div>
                    <div class="card-body">
                        @if (auth()->user()->isApproved())
                            <p>Contul tău a fost aprobat.</p>
                            <a class="btn btn-primary" href="{{ route('app.home') }}">Continuă</a>
                        @else
                            <p class="mb-0">Contul tău a fost creat, dar trebuie aprobat de administrator înainte de a putea folosi aplicația. Revino după ce primești confirmarea.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection