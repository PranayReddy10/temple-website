@if ($errors->any())
    <div class="errors" role="alert">
        @foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif
