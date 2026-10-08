@extends('reports.layout')

@section('content')
<h1>Laporan Sumber Reservasi</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<table>
    <thead>
        <tr>
            <th>Sumber</th>
            <th style="text-align:right">Jumlah Reservasi</th>
            <th style="text-align:right">Total Pendapatan (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @php $totalAmount = 0; $totalCount = 0; @endphp
        @foreach($report['data'] as $row)
        @php 
            $totalAmount += $row->total_revenue;
            $totalCount += $row->count;
        @endphp
        <tr>
            <td>
                @php
                    $source = $row->source;
                    if($source == 'walk_in') $source = 'Walk-In';
                    elseif($source == 'phone') $source = 'Telepon';
                    elseif($source == 'traveloka') $source = 'Traveloka';
                    else $source = 'Lainnya';
                @endphp
                {{ $source }}
            </td>
            <td style="text-align:right">{{ $row->count }}</td>
            <td style="text-align:right">{{ number_format($row->total_revenue, 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr style="font-weight:bold; background:#e0e0e0;">
            <td>TOTAL</td>
            <td style="text-align:right">{{ $totalCount }}</td>
            <td style="text-align:right">{{ number_format($totalAmount, 0, ',', '.') }}</td>
        </tr>
    </tfoot>
</table>
@endsection
