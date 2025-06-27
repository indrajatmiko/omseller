<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class OrderScrapeController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'orders' => 'required|array|min:1',
            'orders.*.shopee_order_id' => 'required|string',
            // Validasi untuk payment_details sebagai objek, bukan array
            'orders.*.payment_details' => 'sometimes|array', // 'array' di Laravel juga memvalidasi objek asosiatif
            'orders.*.payment_details.product_subtotal' => 'nullable|numeric',
            'orders.*.payment_details.shipping_fee_paid_by_buyer' => 'nullable|numeric',
            'orders.*.payment_details.admin_fee' => 'nullable|numeric',
            // Tambahkan validasi lain untuk kunci yang ada di objek payment_details
            'orders.*.payment_details.service_fee' => 'nullable|numeric',
            'orders.*.payment_details.total_income' => 'nullable|numeric',
            
            'orders.*.histories' => 'sometimes|array',
            'orders.*.histories.*.status' => 'required_with:orders.*.histories|string',
            'orders.*.histories.*.event_time' => 'sometimes|date_format:d/m/Y H:i',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $ordersCreated = 0;
        $ordersUpdated = 0;

        DB::beginTransaction();
        try {
            foreach ($request->orders as $orderData) {
                // --- PERBAIKAN UTAMA DI SINI ---
                
                // 1. Cari dulu Order yang ada berdasarkan atribut unik.
                $order = Order::firstOrNew([
                    'user_id' => $user->id,
                    'shopee_order_id' => $orderData['shopee_order_id'],
                ]);
                
                // 2. Cek apakah order ini baru atau sudah ada.
                $wasRecentlyCreated = !$order->exists;
                
                // 3. Kumpulkan semua data yang mungkin untuk di-update atau di-create.
                $orderValues = [
                    'order_sn'           => $orderData['order_sn'] ?? $order->order_sn,
                    'buyer_username'     => $orderData['buyer_username'] ?? $order->buyer_username,
                    'total_price'        => $orderData['total_price'] ?? $order->total_price,
                    'payment_method'     => $orderData['payment_method'] ?? $order->payment_method,
                    'order_status'       => $orderData['order_status'] ?? $order->order_status,
                    'status_description' => $orderData['status_description'] ?? $order->status_description,
                    'shipping_provider'  => $orderData['shipping_provider'] ?? $order->shipping_provider,
                    'tracking_number'    => $orderData['tracking_number'] ?? $order->tracking_number,
                    'order_detail_url'   => $orderData['order_detail_url'] ?? $order->order_detail_url,
                    'address_full'       => $orderData['address_full'] ?? $order->address_full,
                    'final_income'       => $orderData['final_income'] ?? $order->final_income,
                    'scraped_at'         => now(),
                ];

                // 4. Isi model dengan nilai baru dan simpan.
                $order->fill($orderValues);
                $order->save();
                
                // ------------------------------------

                if ($wasRecentlyCreated) {
                    $ordersCreated++;
                } else {
                    $ordersUpdated++;
                }

                // Sinkronisasi Data Relasional
                
                if (isset($orderData['items'])) {
                    $order->items()->delete();
                    $order->items()->createMany($orderData['items']);
                }

                if (isset($orderData['payment_details']) && !empty($orderData['payment_details'])) {
                    // Ambil objek pertama dari array
                    $paymentData = $orderData['payment_details'][0];
                    
                    // Gunakan updateOrCreate untuk menyederhanakan.
                    $order->paymentDetails()->updateOrCreate(
                        ['order_id' => $order->id], // Kunci untuk mencari
                        $paymentData // Data untuk diisi (sekarang objek datar)
                    );
                }

                if (isset($orderData['histories'])) {
                    $order->histories()->delete();
                    $historiesToCreate = array_map(function ($history) {
                        if (isset($history['event_time'])) {
                            $history['event_time'] = Carbon::createFromFormat('d/m/Y H:i', $history['event_time']);
                        }
                        return $history;
                    }, $orderData['histories']);
                    $order->histories()->createMany($historiesToCreate);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Data pesanan berhasil disinkronkan.',
                'created' => $ordersCreated,
                'updated' => $ordersUpdated,
            ], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Gagal menyimpan data pesanan untuk user ' . $user->id, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'request' => $request->all() // Log request untuk debugging
            ]);
            return response()->json(['message' => 'Kesalahan internal server: ' . $e->getMessage()], 500);
        }
    }

    public function getPendingDetails(Request $request)
    {
        $user = Auth::user();

        $pendingOrders = Order::where('user_id', $user->id)
            // Menggunakan 'whereDoesntHave' untuk menemukan pesanan yang TIDAK memiliki relasi 'paymentDetails'
            ->whereDoesntHave('paymentDetails')
            // Hanya ambil kolom yang kita butuhkan untuk mengurangi ukuran payload
            ->select('shopee_order_id', 'order_detail_url')
            ->orderBy('created_at', 'asc') // Proses dari yang paling lama
            ->limit(100) // Batasi jumlah untuk menghindari timeout (bisa disesuaikan)
            ->get();
            
        // Ubah format agar cocok dengan apa yang diharapkan oleh loader.js
        $formattedOrders = $pendingOrders->map(function ($order) {
            return [
                'shopee_order_id' => $order->shopee_order_id,
                'url' => $order->order_detail_url,
            ];
        });

        return response()->json($formattedOrders);
    }

}