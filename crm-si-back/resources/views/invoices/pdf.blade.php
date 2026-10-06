<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;color:#202a35;font-size:12px;margin:36px}.top{border-bottom:3px solid #155e75;padding-bottom:18px;margin-bottom:26px}.muted{color:#64748b}.grid{width:100%;margin:20px 0}.grid td{vertical-align:top;width:50%;padding:8px 0}table.lines{width:100%;border-collapse:collapse;margin-top:28px}table.lines th,table.lines td{padding:12px;border-bottom:1px solid #d9e1e8;text-align:left}table.lines th:last-child,table.lines td:last-child{text-align:right}.total{text-align:right;font-size:20px;font-weight:bold;margin-top:20px}.note{margin-top:38px;padding:14px;background:#f1f5f9;white-space:pre-wrap}.foot{margin-top:24px;color:#64748b;font-size:10px}
</style></head>
<body>
<div class="top"><h1>Solicitud de cobro</h1><div>{{ $issuer['name'] }}</div></div>
<table class="grid"><tr><td><strong>Para</strong><br>{{ $customer['name'] }}<br>{{ $customer['email'] }}<br>{{ $customer['phone'] }}</td><td><strong>Número</strong> {{ $invoice->number }}<br><strong>Emitido</strong> {{ $invoice->issued_on?->format('d/m/Y') }}<br><strong>Vence</strong> {{ $invoice->due_on?->format('d/m/Y') }}</td></tr></table>
<table class="lines"><thead><tr><th>Concepto</th><th>Importe</th></tr></thead><tbody><tr><td>{{ $invoice->concept }}</td><td>$ {{ number_format($invoice->amount_cents / 100, 2, ',', '.') }} ARS</td></tr></tbody></table>
<div class="total">Total: $ {{ number_format($invoice->amount_cents / 100, 2, ',', '.') }} ARS</div>
@if($issuer['instructions'])<div class="note"><strong>Instrucciones de pago</strong><br>{{ $issuer['instructions'] }}</div>@endif
<div class="foot">Este documento es una solicitud de cobro y no es una factura fiscal.</div>
</body></html>
