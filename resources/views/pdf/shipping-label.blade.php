<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Label Pengiriman - #{{ $order->id }}</title>
    <style>
        @page {
            size: A6 portrait;
            margin: 0.5cm;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
            padding: 0;
        }
        .container {
            width: 100%;
            height: 100%;
            /* PERUBAHAN 2: Hapus border */
            /* border: 2px solid black; */ 
            /* padding: 5px; */
            box-sizing: border-box;
            position: relative; /* Untuk footer */
        }
        .header, .footer {
            text-align: center;
            font-weight: bold;
        }
        .section {
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed black;
        }
        .section-title {
            /* font-size: 9pt; */
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        .recipient-name {
            /* font-size: 14pt; */
            font-weight: bold;
        }
        .address {
            /* font-size: 12pt; */
        }
        .sender-info {
            /* font-size: 10pt; */
        }
        .order-details {
            /* font-size: 10pt; */
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed black;
        }
        .order-details ul {
            padding-left: 15px;
            margin: 0;
        }
        .order-details li {
            margin-bottom: 2px;
        }
        .barcode-section {
            text-align: center;
            margin-top: 10px;
        }
        .barcode-section img {
            max-width: 100%;
            height: auto;
        }
        .tracking-number {
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 2px; /* Memberi spasi antar karakter agar mudah dibaca */
            margin-top: 5px;
        }
        .items-table {
            width: 100%;
            border-collapse: collapse; /* Menghilangkan spasi antar border sel */
            margin-top: 5px;
        }
        .items-table th, .items-table td {
            padding: 2px 4px;
            text-align: left;
            vertical-align: top;
        }
        .items-table th {
            font-weight: bold;
            border-bottom: 1px solid #ccc;
        }
        .items-table .col-number { width: 5%; }
        .items-table .col-sku { width: 75%; }
        .items-table .col-qty { width: 20%; text-align: center; }

        .footer-note {
             position: absolute;
             bottom: 10px;
             width: 100%;
             text-align: center;
             font-size: 8pt;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            {{ $order->shipping_provider ?: 'REGULER' }}
        </div>
        @if($barcodeImage)
        <div class="barcode-section">
            {{-- Menyematkan gambar base64 langsung ke HTML --}}
            <img src="data:image/png;base64,{{ $barcodeImage }}" alt="Barcode">
            <div class="tracking-number">{{ $order->tracking_number }}</div>
        </div>
        @endif

        <div class="section">
            <div class="section-title">Kepada:</div>
            <div class="recipient-name">{{ $order->customer_name }}</div>
            <div class="address">
                {{ $order->reseller->address ?? 'Alamat tidak tersedia' }}<br>
                Kec. {{ $order->reseller->district->name ?? '' }}, {{ $order->reseller->city->name ?? '' }}<br>
                {{ $order->reseller->province->name ?? '' }}
            </div>
            <div>Telp: {{ $order->reseller->phone ?? 'No. Telp tidak tersedia' }}</div>
        </div>

        <div class="section">
            <div class="section-title">Dari:</div>
            <div class="sender-info">
                <strong>{{ $senderName }}</strong><br>
                {{ $senderPhone }}
            </div>
        </div>

        <div class="order-details">
            <strong>Order ID: #{{ $order->id }}</strong> | Total Berat: {{ $order->items->sum(fn($i) => $i->quantity * ($i->productVariant->weight ?? 0)) }} gr
            <table class="items-table">
                <thead>
                    <tr>
                        <th class="col-number">#</th>
                        <th class="col-sku">SKU</th>
                        <th class="col-qty">Qty</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($order->items as $index => $item)
                        <tr>
                            <td class="col-number">{{ $index + 1 }}.</td>
                            <td class="col-sku">{{ $item->variant_sku }}</td>
                            <td class="col-qty">{{ $item->quantity }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        
        <div class="footer" style="margin-top: 15px;">
            @if($order->tracking_number)
                No. Resi: <strong>{{ $order->tracking_number }}</strong>
            @else
                {{-- COD: <strong>Rp {{ number_format($order->total_price + $order->shipping_cost) }}</strong> --}}
            @endif
        </div>
    </div>
</body>
</html>