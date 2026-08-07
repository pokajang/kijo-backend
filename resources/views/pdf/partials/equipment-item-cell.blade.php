@php
    $pdfItemName = trim((string) ($itemName ?? ''));
    $pdfItemDescription = trim((string) ($description ?? ''));
    $pdfItemRemarks = trim((string) ($remarks ?? ''));
    $pdfDescriptionLabel = trim((string) ($descriptionLabel ?? 'Description')) ?: 'Description';
    $pdfRemarksLabel = trim((string) ($remarksLabel ?? 'Remarks')) ?: 'Remarks';
    $pdfShowItemName = (bool) ($showItemName ?? true);
    $pdfShowDescriptionLabel = (bool) ($showDescriptionLabel ?? true);
    $pdfShowRemarksLabel = (bool) ($showRemarksLabel ?? true);
@endphp

@if($pdfShowItemName)
    <div data-pdf-item-name><strong>{{ $pdfItemName !== '' ? $pdfItemName : '-' }}</strong></div>
@endif
@if($pdfItemDescription !== '')
    <div data-pdf-item-description style="margin-top: 2px; line-height: 1.25; word-wrap: break-word;">@if($pdfShowDescriptionLabel)<strong data-pdf-item-description-label>{{ $pdfDescriptionLabel }}:</strong> @endif{!! nl2br(e($pdfItemDescription), false) !!}</div>
@endif
@if($pdfItemRemarks !== '')
    <div data-pdf-item-remarks style="margin-top: 2px; line-height: 1.25; word-wrap: break-word;">@if($pdfShowRemarksLabel)<strong data-pdf-item-remarks-label>{{ $pdfRemarksLabel }}:</strong> @endif{!! nl2br(e($pdfItemRemarks), false) !!}</div>
@endif
