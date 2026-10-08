@extends('reports.layout')

@section('content')
<h1>Laporan Housekeeping</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<div class="summary-box">
    Total Tugas: <strong>{{ $report['summary']['total'] }}</strong> &mdash;
    Menunggu: {{ $report['summary']['pending'] }} |
    Sedang Dikerjakan: {{ $report['summary']['in_progress'] }} |
    Selesai: {{ $report['summary']['completed'] }}
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
        @foreach($report['tasks'] as $task)
        <tr>
            <td>{{ $task->room->room_number ?? '-' }}</td>
            <td>{{ $task->status->label() ?? $task->status }}</td>
            <td>{{ $task->assignedTo->name ?? '-' }}</td>
            <td>{{ $task->completed_at ? \Carbon\Carbon::parse($task->completed_at)->format('Y-m-d H:i') : '-' }}</td>
            <td>{{ $task->notes ?? '-' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endsection

