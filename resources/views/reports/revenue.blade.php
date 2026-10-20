@extends('reports.layout')

@section('content')
<h1>Laporan Pendapatan</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Pendapatan: <strong>Rp {{ number_format($report['total_revenue'], 0, ',', '.') }}</strong> ({{ $report['total_transactions'] }} Transaksi) <br>
    <span class="label">Rincian:</span> Tunai: Rp {{ number_format($report['cash_revenue'], 0, ',', '.') }} | 
    Transfer: Rp {{ number_format($report['transfer_revenue'], 0, ',', '.') }}
</div>

<table>
    <thead>
        <tr>
            <th>Tanggal</th>
            <th style="text-align:right">Pendapatan (Rp)</th>
            <th style="text-align:right">Transaksi</th>
        </tr>
    </thead>
    <tbody>
        @foreach($report['daily'] as $row)
        <tr>
            <td>{{ $row->date }}</td>
            <td style="text-align:right">{{ number_format($row->revenue, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ $row->transactions }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
