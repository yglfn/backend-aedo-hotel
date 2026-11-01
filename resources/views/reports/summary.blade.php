@extends('reports.layout')

@section('content')
<h1>Ringkasan Laporan</h1>
<p class="sub">
    Periode: {{ $report['period']['from'] }} s/d {{ $report['period']['to'] }}
    ({{ $report['days'] }} hari) &mdash; pembanding: {{ $report['previous_period']['from'] }} s/d {{ $report['previous_period']['to'] }}
</p>

@php
    $revenue = $report['revenue'];
    $occupancy = $report['occupancy'];
    $reservations = $report['reservations'];
    $housekeeping = $report['housekeeping'];
    $shifts = $report['shifts'];
    $rooms = $report['rooms'];
    $highlights = $report['highlights'];
    $pct = fn ($value) => is_null($value) ? '-' : ($value > 0 ? '+' : '') . $value . '%';
    $points = fn ($value) => ($value > 0 ? '+' : '') . $value . ' poin';
@endphp

<div class="summary-box">
    Pendapatan: <strong>Rp {{ number_format($revenue['total_revenue'], 0, ',', '.') }}</strong>
    dari {{ $revenue['total_transactions'] }} transaksi terverifikasi
    ({{ $pct($revenue['change_percent']) }} vs periode sebelumnya) <br>
    <span class="label">
        Rata-rata harian: Rp {{ number_format($revenue['average_per_day'], 0, ',', '.') }}
        &nbsp;•&nbsp; Okupansi: {{ $occupancy['occupancy_rate'] }}% ({{ $points($occupancy['change_points']) }})
        &nbsp;•&nbsp; Reservasi: {{ $reservations['total'] }} ({{ $pct($reservations['change_percent']) }})
        &nbsp;•&nbsp; Menunggu verifikasi: {{ $revenue['pending_count'] }} transaksi
    </span>
</div>

<h1 style="font-size:13px; margin-top:18px;">Perbandingan Ukuran Utama</h1>
<table>
    <thead>
        <tr>
            <th>Ukuran</th>
            <th style="text-align:right">Periode ini</th>
            <th style="text-align:right">Periode sebelumnya</th>
            <th style="text-align:right">Perubahan</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Pendapatan terverifikasi (Rp)</td>
            <td style="text-align:right">{{ number_format($revenue['total_revenue'], 0, ',', '.') }}</td>
            <td style="text-align:right">{{ number_format($revenue['previous_revenue'], 0, ',', '.') }}</td>
            <td style="text-align:right">{{ $pct($revenue['change_percent']) }}</td>
        </tr>
        <tr>
            <td>Reservasi</td>
            <td style="text-align:right">{{ $reservations['total'] }}</td>
            <td style="text-align:right">{{ $reservations['previous_total'] }}</td>
            <td style="text-align:right">{{ $pct($reservations['change_percent']) }}</td>
        </tr>
        <tr>
            <td>Okupansi (%)</td>
            <td style="text-align:right">{{ $occupancy['occupancy_rate'] }}</td>
            <td style="text-align:right">{{ $occupancy['previous_rate'] }}</td>
            <td style="text-align:right">{{ $points($occupancy['change_points']) }}</td>
        </tr>
    </tbody>
</table>

<h1 style="font-size:13px; margin-top:18px;">Metode Pembayaran &amp; Sumber Reservasi</h1>
<table>
    <thead>
        <tr>
            <th>Metode bayar</th>
            <th style="text-align:right">Transaksi</th>
            <th style="text-align:right">Nominal (Rp)</th>
            <th style="text-align:right">Porsi</th>
            <th>Sumber reservasi</th>
            <th style="text-align:right">Jumlah</th>
            <th style="text-align:right">Nilai (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @php
            $methods = $report['payment_mix']->values();
            $sources = $report['booking_sources']->values();
            $rowCount = max($methods->count(), $sources->count());
        @endphp
        @for ($i = 0; $i < $rowCount; $i++)
        <tr>
            @php $method = $methods[$i] ?? null; $source = $sources[$i] ?? null; @endphp
            <td>{{ $method->label ?? '-' }}</td>
            <td style="text-align:right">{{ $method->count ?? '-' }}</td>
            <td style="text-align:right">{{ $method ? number_format($method->total, 0, ',', '.') : '-' }}</td>
            <td style="text-align:right">{{ $method ? $method->share_percent . '%' : '-' }}</td>
            <td>{{ $source->label ?? '-' }}</td>
            <td style="text-align:right">{{ $source->count ?? '-' }}</td>
            <td style="text-align:right">{{ $source ? number_format($source->total_revenue, 0, ',', '.') : '-' }}</td>
        </tr>
        @endfor
    </tbody>
</table>

<h1 style="font-size:13px; margin-top:18px;">Reservasi per Status</h1>
<table>
    <thead>
        <tr>
            <th>Status</th>
            <th style="text-align:right">Jumlah</th>
            <th style="text-align:right">Nilai (Rp)</th>
        </tr>
    </thead>
    <tbody>
        @foreach($reservations['by_status'] as $status => $row)
        <tr>
            <td>{{ \App\Enums\BookingStatus::tryFrom($status)?->label() ?? $status }}</td>
            <td style="text-align:right">{{ $row['count'] }}</td>
            <td style="text-align:right">{{ number_format($row['value'], 0, ',', '.') }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<h1 style="font-size:13px; margin-top:18px;">Operasional</h1>
<table>
    <thead>
        <tr>
            <th>Housekeeping</th>
            <th style="text-align:right">Jumlah</th>
            <th>Kamar saat ini</th>
            <th style="text-align:right">Jumlah</th>
            <th>Shift &amp; kas</th>
            <th style="text-align:right">Nilai</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Menunggu</td>
            <td style="text-align:right">{{ $housekeeping['pending'] }}</td>
            <td>Tersedia</td>
            <td style="text-align:right">{{ $rooms['available'] }}</td>
            <td>Jumlah shift</td>
            <td style="text-align:right">{{ $shifts['total_shifts'] }}</td>
        </tr>
        <tr>
            <td>Sedang dikerjakan</td>
            <td style="text-align:right">{{ $housekeeping['in_progress'] }}</td>
            <td>Dipesan</td>
            <td style="text-align:right">{{ $rooms['booked'] }}</td>
            <td>Transaksi tercatat</td>
            <td style="text-align:right">{{ $shifts['total_transactions'] }}</td>
        </tr>
        <tr>
            <td>Selesai</td>
            <td style="text-align:right">{{ $housekeeping['completed'] }}</td>
            <td>Terisi</td>
            <td style="text-align:right">{{ $rooms['occupied'] }}</td>
            <td>Kas terverifikasi</td>
            <td style="text-align:right">Rp {{ number_format($shifts['total_collected'], 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td>Tingkat penyelesaian</td>
            <td style="text-align:right">{{ $housekeeping['completion_rate'] }}%</td>
            <td>Dibersihkan</td>
            <td style="text-align:right">{{ $rooms['cleaning'] }}</td>
            <td>Rata-rata per shift</td>
            <td style="text-align:right">Rp {{ number_format($shifts['average_collected'], 0, ',', '.') }}</td>
        </tr>
        @if(!is_null($housekeeping['average_hours']))
        <tr>
            <td>Rata-rata durasi</td>
            <td style="text-align:right">{{ $housekeeping['average_hours'] }} jam</td>
            <td>Perbaikan</td>
            <td style="text-align:right">{{ $rooms['maintenance'] }}</td>
            <td>Refund terverifikasi</td>
            <td style="text-align:right">Rp {{ number_format($revenue['refund_total'], 0, ',', '.') }}</td>
        </tr>
        @endif
    </tbody>
</table>

<h1 style="font-size:13px; margin-top:18px;">Tren Pendapatan Harian</h1>
<table>
    <thead>
        <tr>
            <th>Tanggal</th>
            <th style="text-align:right">Pendapatan (Rp)</th>
            <th style="text-align:right">Transaksi</th>
        </tr>
    </thead>
    <tbody>
        @forelse($report['revenue_trend'] as $day)
        <tr>
            <td>{{ $day->date }}</td>
            <td style="text-align:right">{{ number_format($day->revenue, 0, ',', '.') }}</td>
            <td style="text-align:right">{{ $day->transactions }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="3" style="text-align:center; color:#777;">Belum ada pembayaran terverifikasi pada periode ini.</td>
        </tr>
        @endforelse
    </tbody>
</table>

@if($highlights['best_day'] || $highlights['best_room'] || $highlights['top_source'])
<h1 style="font-size:13px; margin-top:18px;">Sorotan</h1>
<table>
    <tbody>
        @if($highlights['best_day'])
        <tr>
            <td>Pendapatan tertinggi</td>
            <td>{{ $highlights['best_day']['date'] }}</td>
            <td style="text-align:right">Rp {{ number_format($highlights['best_day']['revenue'], 0, ',', '.') }}</td>
        </tr>
        @endif
        @if($highlights['best_room'])
        <tr>
            <td>Kamar paling banyak dipakai</td>
            <td>Kamar {{ $highlights['best_room']['room_number'] }}</td>
            <td style="text-align:right">{{ $highlights['best_room']['occupied_nights'] }} malam</td>
        </tr>
        @endif
        @if($highlights['top_source'])
        <tr>
            <td>Sumber reservasi terbanyak</td>
            <td>{{ \App\Enums\BookingSource::tryFrom($highlights['top_source']['source'])?->label() ?? $highlights['top_source']['source'] }}</td>
            <td style="text-align:right">{{ $highlights['top_source']['count'] }} reservasi</td>
        </tr>
        @endif
    </tbody>
</table>
@endif
@endsection
