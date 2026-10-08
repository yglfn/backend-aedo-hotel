@extends('reports.layout')

@section('content')
<h1>{{ $report['title'] ?? 'Laporan' }}</h1>
<p class="sub">
    Periode: {{ $report['period']['from'] ?? '-' }} s/d {{ $report['period']['to'] ?? '-' }}
</p>

<p style="color:#666; font-size:11px;">Data tersedia. Silakan gunakan format Excel untuk ekspor detail.</p>
@endsection
