<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/render.css') }}">
    @stack('head')
</head>
<body class="render">
    @yield('body')
    <script src="{{ asset('vendor/echarts.min.js') }}"></script>
    <script src="{{ asset('js/dashboard-render.js') }}"></script>
    @stack('scripts')
</body>
</html>
