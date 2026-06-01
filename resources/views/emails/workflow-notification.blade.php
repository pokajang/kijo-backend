@if (!empty($intro))
    @foreach ($intro as $paragraph)
        <p style="margin:0 0 14px;">{!! nl2br(e($paragraph)) !!}</p>
    @endforeach
@endif

@if ($status)
    @include('emails.partials.status-badge', ['status' => $status])
@endif

@if (!empty($details))
    @include('emails.partials.detail-panel', [
        'heading' => $detailsHeading,
        'rows' => $details,
    ])
@endif

@if ($notice)
    @include('emails.partials.notice-panel', ['notice' => $notice])
@endif

@if ($actionUrl)
    @include('emails.partials.cta-button', [
        'url' => $actionUrl,
        'label' => $actionLabel,
    ])
@endif

@if ($signOff)
    @include('emails.partials.sign-off', ['signOff' => $signOff])
@endif
