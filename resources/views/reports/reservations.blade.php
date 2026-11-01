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
    Batal: {{ $report['summary']['cancelled'] }} ({{ $report['summary']['cancellation_rate'] }}%) <br>
    <span class="label">Nilai reservasi (tanpa yang dibatalkan):</span> Rp {{ number_format($report['summary']['total_value'], 0, ',', '.') }}
    &nbsp;•&nbsp; Rata-rata Rp {{ number_format($report['summary']['average_value'], 0, ',', '.') }}
    &nbsp;•&nbsp; Rata-rata {{ $report['summary']['average_nights'] }} malam
    &nbsp;•&nbsp; {{ $report['summary']['total_guests'] }} tamu <br>
    <span class="label">Terbayar terverifikasi:</span> Rp {{ number_format($report['summary']['collected'], 0, ',', '.') }}
    &nbsp;•&nbsp; <span class="label">Sisa tagihan:</span> Rp {{ number_format($report['summary']['outstanding'], 0, ',', '.') }}
    &nbsp;•&nbsp; <span class="label">Periode sebelumnya:</span> {{ $report['comparison']['total'] }} reservasi
    @if(!is_null($report['comparison']['change_percent']))
        ({{ $report['comparison']['change_percent'] > 0 ? '+' : '' }}{{ $report['comparison']['change_percent'] }}%)
    @endif
</div>

<table>
    <thead>
        <tr>
            <th>Kode</th>
            <th>Tamu</th>
            <th>Kamar</th>
            <th>Tgl Check-In</th>
            <th>Tgl Check-Out</th>
            <th>Status</th>
            <th>Sumber</th>
            <th style="text-align:right">Total (Rp)</th>
            <th style="text-align:right">Terbayar (Rp)</th>
            <th style="text-align:right">Sisa (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['bookings'] as $booking)
        <tr>
            <td>{{ $booking->booking_code }}</td>
            <td>{{ $booking->customer->name ?? '-' }}</td>
            <td>{{ $booking->bookingRooms->pluck('room.room_number')->filter()->implode(', ') ?: '-' }}</td>
            <td>{{ $booking->check_in?->format('Y-m-d') }}</td>
            <td>{{ $booking->check_out?->format('Y-m-d') }}</td>
            <td>{{ $booking->status->label() }}</td>
            <td>{{ $booking->source->label() }}</td>
            <td style="text-align:right">{{ number_format($booking->total_price, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ number_format($booking->paid_verified, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ number_format($booking->remaining_due, 0, ',', '.') }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="10" style="text-align:center; color:#777;">Tidak ada reservasi pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
</table>
@endsection
