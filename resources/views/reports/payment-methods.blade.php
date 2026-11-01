@extends('reports.layout')

@section('content')
<h1>Laporan Metode Pembayaran</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<table>
    <thead>
        <tr>
            <th>Metode Pembayaran</th>
            <th style="text-align:right">Jumlah Transaksi</th>
            <th style="text-align:right">Porsi Transaksi</th>
            <th style="text-align:right">Total (Rp)</th>
            <th style="text-align:right">Porsi Nominal</th>
            <th style="text-align:right">Rata-rata / Transaksi (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['data'] as $row)
        <tr>
            <td>{{ $row->label ?? $row->method }}</td>
            <td style="text-align:right">{{ $row->count }}</td>
            <td style="text-align:right">{{ $row->count_percent }}%</td>
            <td style="text-align:right">{{ number_format($row->total, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ $row->share_percent }}%</td>
            <td style="text-align:right">{{ number_format($row->average, 0, ',', '.') }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="6" style="text-align:center; color:#777;">Belum ada pembayaran terverifikasi pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr style="font-weight:bold; background:#e0e0e0;">
            <td>TOTAL</td>
            <td style="text-align:right">{{ $report['total_count'] }}</td>
            <td style="text-align:right">100%</td>
            <td style="text-align:right">{{ number_format($report['total_amount'], 0, ',', '.') }}</td>
            <td style="text-align:right">100%</td>
            <td style="text-align:right">{{ number_format($report['total_count'] > 0 ? $report['total_amount'] / $report['total_count'] : 0, 0, ',', '.') }}</td>
        </tr>
    </tfoot>
</table>
@endsection