@extends('layouts.render')

@section('title', $spec['title'])

@php($story = ($g['mode'] ?? 'parts') === 'story')

@push('head')
@if ($story)
<style>html, body.render { margin: 0; background: #fff; } .ig2 { box-shadow: none; }</style>
@else
<style>html, body.render { margin: 0; width: 1536px; height: 1024px; overflow: hidden; background: #fff; } .ig-canvas { border-radius: 0; box-shadow: none; }</style>
@endif
@endpush

@section('body')
@if ($story)
@include('dashboards._infographic_story', ['imageRoute' => URL::temporarySignedRoute('render.image', now()->addMinutes(10), ['version' => $version->id])])
@else
@include('dashboards._infographic_canvas', ['imageRoute' => URL::temporarySignedRoute('render.image', now()->addMinutes(10), ['version' => $version->id])])
@endif
@endsection

@push('scripts')
@if ($story)
<script src="{{ asset('js/infographic-story.js') }}"></script>
<script>
    MscStory.draw({ renderer: 'svg' });
    MscDashboard.markReady();
</script>
@else
<script src="{{ asset('js/infographic-render.js') }}"></script>
<script>
    MscInfographic.draw({ renderer: 'svg' });
    MscDashboard.markReady();
</script>
@endif
@endpush
