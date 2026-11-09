{{-- Shared design + motion layer for every workspace door. Include it after the door's own stylesheets. --}}
<link rel="stylesheet" href="{{ asset('css/workspace-ui.css') }}?v={{ @filemtime(public_path('css/workspace-ui.css')) }}">
<link rel="stylesheet" href="{{ asset('css/door-lock.css') }}?v={{ @filemtime(public_path('css/door-lock.css')) }}">
