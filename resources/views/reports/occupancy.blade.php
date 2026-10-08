@extends('reports.layout')

@section('content')
<h1>Laporan Okupansi Kamar</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Tingkat Okupansi: <strong>{{ $report['occupancy_rate'] }}%</strong> &mdash; 
    Malam Terisi: {{ $report['occupied_nights'] }} | 
    Total Kamar-Malam: {{ $report['total_room_nights'] }} | 
    Total Kamar: {{ $report['total_rooms'] }}
</div>

<table>
    <thead>
        <tr>
            <th>No. Kamar</th>
            <th>Malam Terisi</th>
        </tr>
    </thead>
    <tbody>
        @foreach($report['per_room'] as $row)
        <tr>
            <td>{{ $row->room_number }}</td>
            <td>{{ $row->occupied_nights }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection

