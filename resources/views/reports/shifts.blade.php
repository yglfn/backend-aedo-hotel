@extends('reports.layout')

@section('content')
<h1>Laporan Shift Pegawai</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Shift: <strong>{{ $report['summary']['total_shifts'] }}</strong>
    ({{ $report['summary']['closed_shifts'] }} selesai, {{ $report['summary']['open_shifts'] }} berjalan) &mdash;
    Kas Terverifikasi: Rp {{ number_format($report['summary']['total_collected'], 0, ',', '.') }} <br>
    <span class="label">
        {{ $report['summary']['total_transactions'] }} transaksi tercatat
        &nbsp;•&nbsp; Rata-rata Rp {{ number_format($report['summary']['average_collected'], 0, ',', '.') }} per shift
    </span>
</div>

<table>
    <thead>
        <tr>
            <th>Pegawai</th>
            <th>Mulai</th>
            <th>Selesai</th>
            <th>Status</th>
            <th style="text-align:right">Durasi (Jam)</th>
            <th style="text-align:right">Total Transaksi</th>
            <th style="text-align:right">Total Dikumpulkan (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['shifts'] as $shift)
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
            <td style="text-align:right">{{ $shift->duration_hours ?? '-' }}</td>
            <td style="text-align:right">{{ $shift->total_transactions }}</td>
            <td style="text-align:right">{{ number_format($shift->total_collected, 0, ',', '.') }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="7" style="text-align:center; color:#777;">Tidak ada shift pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
</table>
@endsection
