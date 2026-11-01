@extends('reports.layout')

@section('content')
<h1>Laporan Pendapatan</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Pendapatan: <strong>Rp {{ number_format($report['total_revenue'], 0, ',', '.') }}</strong> ({{ $report['total_transactions'] }} Transaksi) <br>
    <span class="label">Rincian:</span> Tunai: Rp {{ number_format($report['cash_revenue'], 0, ',', '.') }} |
    Transfer: Rp {{ number_format($report['transfer_revenue'], 0, ',', '.') }} |
    QRIS: Rp {{ number_format($report['qris_revenue'], 0, ',', '.') }} <br>
    <span class="label">Rata-rata:</span> Rp {{ number_format($report['average_per_day'], 0, ',', '.') }} / hari ({{ $report['days'] }} hari)
    &nbsp;•&nbsp; Rp {{ number_format($report['average_per_txn'], 0, ',', '.') }} / transaksi
    @if($report['best_day'])
        &nbsp;•&nbsp; Harian tertinggi: Rp {{ number_format($report['best_day']['revenue'], 0, ',', '.') }} ({{ $report['best_day']['date'] }})
    @endif
    @if($report['refund_total'] > 0)
        <br><span class="label">Refund terverifikasi pada periode:</span> Rp {{ number_format($report['refund_total'], 0, ',', '.') }}
    @endif
    @if($report['pending_count'] > 0)
        <br><span class="label">Menunggu verifikasi:</span> {{ $report['pending_count'] }} transaksi (Rp {{ number_format($report['pending_amount'], 0, ',', '.') }})
    @endif
    <br>
    <span class="label">
        Periode sebelumnya ({{ $report['comparison']['period']['from'] }} s/d {{ $report['comparison']['period']['to'] }}):
        Rp {{ number_format($report['comparison']['total_revenue'], 0, ',', '.') }}
        @if(!is_null($report['comparison']['change_percent']))
            &nbsp;({{ $report['comparison']['change_percent'] > 0 ? '+' : '' }}{{ $report['comparison']['change_percent'] }}%)
        @endif
    </span>
</div>

<table>
    <thead>
        <tr>
            <th>Tanggal</th>
            <th style="text-align:right">Pendapatan (Rp)</th>
            <th style="text-align:right">Transaksi</th>
            <th style="text-align:right">Rata-rata / Transaksi (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($report['daily'] as $row)
        <tr>
            <td>{{ $row->date }}</td>
            <td style="text-align:right">{{ number_format($row->revenue, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ $row->transactions }}</td>
            <td style="text-align:right">{{ number_format($row->transactions > 0 ? $row->revenue / $row->transactions : 0, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
