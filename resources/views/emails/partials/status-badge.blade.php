@php
    $tone = $status['tone'] ?? 'neutral';
    $styles = [
        'warning' => ['bg' => '#fff7ed', 'text' => '#9a3412', 'border' => '#fed7aa'],
        'danger' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'border' => '#fecaca'],
        'success' => ['bg' => '#ecfdf5', 'text' => '#166534', 'border' => '#bbf7d0'],
        'info' => ['bg' => '#eff6ff', 'text' => '#1d4ed8', 'border' => '#bfdbfe'],
        'neutral' => ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#e5e7eb'],
    ][$tone] ?? ['bg' => '#f3f4f6', 'text' => '#374151', 'border' => '#e5e7eb'];
@endphp

<div style="margin:0 0 16px;">
    <span style="display:inline-block; padding:5px 9px; border-radius:999px; background-color:{{ $styles['bg'] }}; border:1px solid {{ $styles['border'] }}; color:{{ $styles['text'] }}; font-size:12px; line-height:1.2; font-weight:700;">{{ $status['label'] }}</span>
</div>
