@if (session('error'))
    <div class="alert alert-danger f-14 mb-3" role="alert">
        <i class="fa fa-exclamation-triangle mr-1"></i> {!! nl2br(e(session('error'))) !!}
    </div>
@endif
@if (session('success'))
    <div class="alert alert-success f-14 mb-3" role="alert">
        <i class="fa fa-check-circle mr-1"></i> {{ session('success') }}
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger f-14 mb-3" role="alert">
        <ul class="mb-0 pl-3">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
