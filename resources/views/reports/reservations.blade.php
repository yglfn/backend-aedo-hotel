@extends('reports.layout')

@section('content')
<h1>Laporan Reservasi</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Reservasi: <strong>{{ $report['summary']['total'] }}</strong> &mdash; 
    Menunggu: {{ $report['summary']['pending'] }} | 
    Dikonfirmasi: {{ $report['summary']['confirmed'] }} | 
    Check-In: {{ $report['summary']['checked_in'] }} | 
    Check-Out: {{ $report['summary']['checked_out'] }} | 
    Batal: {{ $report['summary']['cancelled'] }}
</div>

<table>
    <thead>
        <tr>
            <th>Kode</th>
            <th>Tamu</th>
            <th>Tgl Check-In</th>
            <th>Tgl Check-Out</th>
            <th>Status</th>
            <th style="text-align:right">Total (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($report['bookings'] as $booking)
        <tr>
            <td>{{ $booking->booking_code }}</td>
            <td>{{ $booking->customer->name ?? '-' }}</td>
            <td>{{ $booking->check_in }}</td>
            <td>{{ $booking->check_out }}</td>
            <td>{{ $booking->status->label() }}</td>
            <td style="text-align:right">{{ number_format($booking->total_price, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
