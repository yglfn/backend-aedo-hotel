<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        p.sub { color: #555; margin: 0 0 16px 0; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #0E7C61; color: #fff; padding: 7px 8px; text-align: left; font-size: 10px; }
        td { padding: 6px 8px; border-bottom: 1px solid #e0e0e0; font-size: 10px; }
        tr:nth-child(even) td { background: #f4f8f6; }
        .summary-box { background: #EDF7F2; border-left: 4px solid #0E7C61; padding: 10px 14px; margin-bottom: 16px; border-radius: 3px; }
        .summary-box strong { font-size: 22px; }
        .label { color: #555; font-size: 10px; }
    </style>
</head>
<body>
    @yield('content')
    <p style="margin-top:20px; color:#aaa; font-size:9px;">
        Dicetak: {{ now()->setTimezone('Asia/Jakarta')->format('d M Y H:i') }} WIB &mdash; Aedo Hotel Syariah
    </p>
</body>
</html>
