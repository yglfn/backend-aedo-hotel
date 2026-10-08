@extends('reports.layout')

@section('content')
<h1>Laporan Shift Pegawai</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<table>
    <thead>
        <tr>
            <th>Pegawai</th>
            <th>Mulai</th>
            <th>Selesai</th>
            <th>Status</th>
            <th style="text-align:right">Total Transaksi</th>
            <th style="text-align:right">Total Dikumpulkan (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($report['shifts'] as $shift)
        <tr>
            <td>{{ $shift->pegawai }}</td>
            <td>{{ \Carbon\Carbon::parse($shift->started_at)->format('Y-m-d H:i') }}</td>
            <td>{{ $shift->ended_at ? \Carbon\Carbon::parse($shift->ended_at)->format('Y-m-d H:i') : '-' }}</td>
            <td>
                @if($shift->status == 'open')
                    Berjalan
                @else
                    Selesai
                @endif
            </td>
            <td style="text-align:right">{{ $shift->total_transactions }}</td>
            <td style="text-align:right">{{ number_format($shift->total_collected, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection
