@extends('reports.layout')

@section('content')
<h1>Laporan Transaksi Pembayaran</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Transaksi: <strong>{{ $report['total'] }}</strong> &mdash;
    Nominal: Rp {{ number_format($report['summary']['total_amount'], 0, ',', '.') }} <br>
    <span class="label">
        Terverifikasi: {{ $report['summary']['verified_count'] }} (Rp {{ number_format($report['summary']['verified_amount'], 0, ',', '.') }}) |
        Menunggu: {{ $report['summary']['pending_count'] }} (Rp {{ number_format($report['summary']['pending_amount'], 0, ',', '.') }}) |
        Gagal: {{ $report['summary']['failed_count'] }} (Rp {{ number_format($report['summary']['failed_amount'], 0, ',', '.') }}) |
        Refund: Rp {{ number_format($report['summary']['refund_amount'], 0, ',', '.') }}
    </span>
</div>

<table>
    <thead>
        <tr>
            <th>Kode Booking</th>
            <th>Tamu</th>
            <th>Metode</th>
            <th>Tipe</th>
            <th>Status</th>
            <th>Tanggal</th>
            <th>Dicatat Oleh</th>
            <th style="text-align:right">Nominal (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['payments'] as $payment)
        <tr>
            <td>{{ $payment->booking->booking_code ?? '-' }}</td>
            <td>{{ $payment->booking->customer->name ?? '-' }}</td>
            <td>{{ $payment->method->label() ?? $payment->method }}</td>
            <td>{{ $payment->type->label() ?? $payment->type }}</td>
            <td>{{ $payment->status->label() ?? $payment->status }}</td>
            <td>{{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('Y-m-d H:i') : '-' }}</td>
            <td>{{ $payment->createdBy->name ?? '-' }}</td>
            <td style="text-align:right">{{ number_format($payment->amount, 0, ',', '.') }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="8" style="text-align:center; color:#777;">Tidak ada transaksi pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
</table>
@endsection
