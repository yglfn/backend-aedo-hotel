@extends('reports.layout')

@section('content')
<h1>Laporan Transaksi Pembayaran</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Transaksi: <strong>{{ $report['total'] }}</strong>
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
            <th style="text-align:right">Nominal (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($report['payments'] as $payment)
        <tr>
            <td>{{ $payment->booking->booking_code ?? '-' }}</td>
            <td>{{ $payment->booking->customer->name ?? '-' }}</td>
            <td>{{ $payment->method->label() ?? $payment->method }}</td>
            <td>{{ $payment->type->label() ?? $payment->type }}</td>
            <td>{{ $payment->status->label() ?? $payment->status }}</td>
            <td>{{ $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at)->format('Y-m-d H:i') : '-' }}</td>
            <td style="text-align:right">{{ number_format($payment->amount, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
