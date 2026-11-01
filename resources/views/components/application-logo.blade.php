@props(['variant' => 'min'])

@php
    $logoAsset = $variant === 'full'
        ? asset('storage/oatcake_logo_full.jpg')
        : asset('storage/oatcake_logo_min.jpg');
@endphp

<img src="{{ $logoAsset }}"
     alt="{{ config('app.name', 'OATCAKE') }}"
     {{ $attributes->merge(['class' => 'rounded-lg object-contain']) }} />
