@extends('reports.layout')

@section('content')
<h1>Laporan Metode Pembayaran</h1>
<p class="sub">Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}</p>

<table>
    <thead>
        <tr>
            <th>Metode Pembayaran</th>
            <th style="text-align:right">Jumlah Transaksi</th>
            <th style="text-align:right">Total (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @php $totalAmount = 0; $totalCount = 0; @endphp
        @foreach($report['data'] as $row)
        @php
        $totalAmount += $row->total;
        $totalCount += $row->count;
        @endphp
        <tr>
            <td>
                @php
                $methodName = $row->method;
                if($methodName == 'cash') $methodName = 'Tunai';
                elseif($methodName == 'transfer') $methodName = 'Transfer Bank';
                elseif($methodName == 'payment_gateway') $methodName = 'Payment Gateway';
                @endphp
                {{ $methodName }}
            </td>
            <td style="text-align:right">{{ $row->count }}</td>
            <td style="text-align:right">{{ number_format($row->total, 0, ',', '.') }}</td>
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