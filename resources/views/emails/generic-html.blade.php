@extends('emails.layouts.standard')

@section('title'){{ $subject }}@endsection
@section('preheader'){{ $preheader }}@endsection
@section('headerLabel'){{ $headerLabel ?? 'KIJO Notification' }}@endsection
@section('headerTitle'){{ $headerTitle ?? $subject }}@endsection
@if (!empty($headerSubtitle))
    @section('headerSubtitle'){{ $headerSubtitle }}@endsection
@endif

@section('content')
    {!! $body !!}
@endsection

@if (!empty($footer))
    @section('footer')
        {!! $footer !!}
    @endsection
@endif
