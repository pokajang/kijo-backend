@php
    $tone = $notice['tone'] ?? 'info';
    $styles = [
        'warning' => ['bg' => '#fff7ed', 'border' => '#fed7aa', 'text' => '#9a3412'],
        'danger' => ['bg' => '#fef2f2', 'border' => '#fecaca', 'text' => '#991b1b'],
        'success' => ['bg' => '#ecfdf5', 'border' => '#bbf7d0', 'text' => '#166534'],
        'info' => ['bg' => '#eff6ff', 'border' => '#bfdbfe', 'text' => '#1d4ed8'],
    ][$tone] ?? ['bg' => '#f9fafb', 'border' => '#e5e7eb', 'text' => '#374151'];
@endphp

<div style="margin:0 0 18px; padding:12px 14px; background-color:{{ $styles['bg'] }}; border:1px solid {{ $styles['border'] }}; border-radius:8px; font-size:14px; line-height:1.65; color:{{ $styles['text'] }};">
    <strong>{{ $notice['label'] ?? 'Note' }}:</strong> {!! nl2br(e($notice['body'] ?? '')) !!}
</div>
