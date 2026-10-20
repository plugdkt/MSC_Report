@extends('layouts.render')

@section('title', $spec['title'])

@push('head')
<style>html, body.render { margin: 0; width: 1536px; height: 1024px; overflow: hidden; background: #fff; } .ig-canvas { border-radius: 0; box-shadow: none; }</style>
@endpush

@section('body')
@include('dashboards._infographic_canvas', ['imageRoute' => URL::temporarySignedRoute('render.image', now()->addMinutes(10), ['version' => $version->id])])
@endsection

@push('scripts')
<script src="{{ asset('js/infographic-render.js') }}"></script>
<script>
    MscInfographic.draw({ renderer: 'svg' });
    MscDashboard.markReady();
</script>
@endpush
