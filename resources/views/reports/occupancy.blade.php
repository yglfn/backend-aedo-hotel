@extends('reports.layout')

@section('content')
<h1>Laporan Okupansi Kamar</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Tingkat Okupansi: <strong>{{ $report['occupancy_rate'] }}%</strong> &mdash; 
    Malam Terisi: {{ $report['occupied_nights'] }} | 
    Total Kamar-Malam: {{ $report['total_room_nights'] }} | 
    Total Kamar: {{ $report['total_rooms'] }} ({{ $report['total_nights'] }} malam) <br>
    <span class="label">
        Perbandingan periode sebelumnya
        ({{ $report['comparison']['period']['from'] }} s/d {{ $report['comparison']['period']['to'] }}):
        {{ $report['comparison']['occupancy_rate'] }}%
        (&nbsp;{{ $report['comparison']['change_points'] > 0 ? '+' : '' }}{{ $report['comparison']['change_points'] }} poin)
        &nbsp;•&nbsp; Kamar tidak pernah terisi: {{ $report['idle_rooms'] }}
        @if($report['busiest_room'])
            &nbsp;•&nbsp; Kamar terlaris: {{ $report['busiest_room']['room_number'] }} ({{ $report['busiest_room']['occupied_nights'] }} malam)
        @endif
    </span>
</div>

<table>
    <thead>
        <tr>
            <th>No. Kamar</th>
            <th>Tipe Kamar</th>
            <th>Lantai</th>
            <th style="text-align:right">Malam Terisi</th>
            <th style="text-align:right">% Okupansi Kamar</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['per_room'] as $row)
        <tr>
            <td>{{ $row->room_number }}</td>
            <td>{{ $row->room_type ?? '-' }}</td>
            <td>{{ $row->floor ?? '-' }}</td>
            <td style="text-align:right">{{ $row->occupied_nights }}</td>
            <td style="text-align:right">{{ $row->room_rate }}%</td>
        </tr>
        @empty
        <tr>
            <td colspan="5" style="text-align:center; color:#777;">Tidak ada kamar terisi pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
</table>
@endsection

