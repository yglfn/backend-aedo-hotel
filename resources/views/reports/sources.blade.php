@extends('reports.layout')

@section('content')
<h1>Laporan Sumber Reservasi</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<table>
    <thead>
        <tr>
            <th>Sumber</th>
            <th style="text-align:right">Jumlah Reservasi</th>
            <th style="text-align:right">Porsi Reservasi</th>
            <th style="text-align:right">Total Pendapatan (Rp)</th>
            <th style="text-align:right">Rata-rata / Reservasi (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['data'] as $row)
        <tr>
            <td>{{ $row->label ?? $row->source }}</td>
            <td style="text-align:right">{{ $row->count }}</td>
            <td style="text-align:right">{{ $row->share_percent }}%</td>
            <td style="text-align:right">{{ number_format($row->total_revenue, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ number_format($row->average_value, 0, ',', '.') }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="5" style="text-align:center; color:#777;">Tidak ada reservasi pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr style="font-weight:bold; background:#e0e0e0;">
            <td>TOTAL</td>
            <td style="text-align:right">{{ $report['total_count'] }}</td>
            <td style="text-align:right">100%</td>
            <td style="text-align:right">{{ number_format($report['total_revenue'], 0, ',', '.') }}</td>
            <td style="text-align:right">{{ number_format($report['total_count'] > 0 ? $report['total_revenue'] / $report['total_count'] : 0, 0, ',', '.') }}</td>
        </tr>
    </tfoot>
</table>
@endsection
