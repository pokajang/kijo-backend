@extends('emails.layouts.standard')

@section('title', 'Monthly KPI Tracker Reminder')
@section('preheader')Please complete your KPI Tracker form for this month.@endsection
@section('headerLabel', 'KPI Tracker')
@section('headerTitle', 'Monthly KPI Tracker Reminder')
@section('headerSubtitle', 'Monthly reminder')

@section('content')
    <p style="margin:0 0 14px;">Dear {{ $recipientName }},</p>
    <p style="margin:0 0 14px;">This is your monthly reminder to please <strong>complete your KPI Tracker form</strong> for this month.</p>
    <p style="margin:0;">Thank you.</p>
@endsection
