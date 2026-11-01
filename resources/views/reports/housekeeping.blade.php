@extends('reports.layout')

@section('content')
<h1>Laporan Housekeeping</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Tugas: <strong>{{ $report['summary']['total'] }}</strong> &mdash;
    Menunggu: {{ $report['summary']['pending'] }} |
    Sedang Dikerjakan: {{ $report['summary']['in_progress'] }} |
    Selesai: {{ $report['summary']['completed'] }} |
    Belum Ditugaskan: {{ $report['summary']['unassigned'] }} <br>
    <span class="label">
        Tingkat penyelesaian: {{ $report['summary']['completion_rate'] }}%
        @if(!is_null($report['summary']['average_hours']))
            &nbsp;•&nbsp; Rata-rata pengerjaan: {{ $report['summary']['average_hours'] }} jam
        @endif
    </span>
</div>

<table>
    <thead>
        <tr>
            <th>Kamar</th>
            <th>Status</th>
            <th>Ditugaskan Ke</th>
            <th>Tgl Selesai</th>
            <th>Catatan</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['tasks'] as $task)
        <tr>
            <td>{{ $task->room->room_number ?? '-' }}</td>
            <td>{{ $task->status->label() ?? $task->status }}</td>
            <td>{{ $task->assignedTo->name ?? '-' }}</td>
            <td>{{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('Y-m-d H:i') : '-' }}</td>
            <td>{{ $task->notes ?? '-' }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="5" style="text-align:center; color:#777;">Tidak ada tugas housekeeping pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
</table>

<h1 style="margin-top:24px; font-size:13px;">Rekap per Petugas</h1>
<table>
    <thead>
        <tr>
            <th>Petugas</th>
            <th style="text-align:right">Total Tugas</th>
            <th style="text-align:right">Selesai</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['by_staff'] as $staff)
        <tr>
            <td>{{ $staff['staff'] }}</td>
            <td style="text-align:right">{{ $staff['total'] }}</td>
            <td style="text-align:right">{{ $staff['completed'] }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="3" style="text-align:center; color:#777;">Belum ada tugas yang bisa direkap.</td>
        </tr>
        @endforelse
    </tbody>
</table>
@endsection

