<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Gate;
use Milon\Barcode\DNS1D;

class PdfController extends Controller
{
    public function shippingLabel(Order $order, Request $request)
    {
        if (Gate::denies('view', $order)) {
            abort(403);
        }
        
        // --- PERBAIKAN ---
        // "Refresh" model untuk memastikan semua kolom termuat dari database.
        // Ini adalah langkah pengamanan jika route model binding memberikan data yang tidak lengkap.
        $order->refresh();

        $senderName = $request->query('sender_name', 'Indah');
        $senderPhone = $request->query('sender_phone', '085716285073');
        
        // Eager load relasi yang dibutuhkan untuk label.
        $order->load('items.productVariant', 'reseller.province', 'reseller.city', 'reseller.district');

        $barcodeImage = null;
        // Gunakan trim() untuk memastikan tidak ada spasi kosong yang tidak terlihat
        if ($order->tracking_number && trim($order->tracking_number) !== '') {
            $generator = new DNS1D();
            $barcodeImage = $generator->getBarcodePNG($order->tracking_number, 'C128', 2, 50, [0,0,0], true);
        }

        $pdf = Pdf::loadView('pdf.shipping-label', [
            'order' => $order,
            'senderName' => $senderName,
            'senderPhone' => $senderPhone,
            'barcodeImage' => $barcodeImage,
        ]);
        
        $pdf->setPaper('a6', 'portrait');
        return $pdf->stream('label-pengiriman-'.$order->id.'.pdf');
    }
}