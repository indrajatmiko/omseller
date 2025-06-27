<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderHistory;
use App\Models\OrderPaymentDetail;
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
            // Validasi untuk data relasional (opsional, karena bisa dikirim terpisah)
            'orders.*.payment_details' => 'sometimes|array',
            'orders.*.payment_details.*.label' => 'required_with:orders.*.payment_details|string',
            'orders.*.payment_details.*.amount' => 'required_with:orders.*.payment_details|numeric',
            'orders.*.histories' => 'sometimes|array',
            'orders.*.histories.*.status' => 'required_with:orders.*.histories|string',
            'orders.*.histories.*.event_time' => 'required_with:orders.*.histories|date_format:d/m/Y H:i',
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
                // Atribut unik untuk menemukan pesanan
                $uniqueAttributes = [
                    'user_id' => $user->id,
                    'shopee_order_id' => $orderData['shopee_order_id'],
                ];
                
                // Kumpulkan data untuk tabel 'orders'
                $orderValues = array_filter([
                    'order_sn'           => $orderData['order_sn'] ?? null,
                    'buyer_username'     => $orderData['buyer_username'] ?? null,
                    'total_price'        => $orderData['total_price'] ?? null,
                    'payment_method'     => $orderData['payment_method'] ?? null,
                    'order_status'       => $orderData['order_status'] ?? null,
                    'status_description' => $orderData['status_description'] ?? null,
                    'shipping_provider'  => $orderData['shipping_provider'] ?? null,
                    'tracking_number'    => $orderData['tracking_number'] ?? null,
                    'order_detail_url'   => $orderData['order_detail_url'] ?? null,
                    'address_full'       => $orderData['address_full'] ?? null,
                    'final_income'       => $orderData['final_income'] ?? null,
                    'scraped_at'         => now(),
                ]);

                $order = Order::updateOrCreate($uniqueAttributes, $orderValues);

                if ($order->wasRecentlyCreated) {
                    $ordersCreated++;
                } else {
                    $ordersUpdated++;
                }

                // Sinkronisasi Data Relasional (Hapus yang lama, buat yang baru)
                
                // 1. Order Items
                if (isset($orderData['items'])) {
                    $order->items()->delete(); // Hapus item lama
                    $order->items()->createMany($orderData['items']);
                }

                // 2. Payment Details
                if (isset($orderData['payment_details'])) {
                    $order->paymentDetails()->delete(); // Hapus detail pembayaran lama
                    $order->paymentDetails()->createMany($orderData['payment_details']);
                }

                // 3. Order History
                if (isset($orderData['histories'])) {
                    $order->histories()->delete(); // Hapus riwayat lama
                    // Format tanggal sebelum createMany
                    $historiesToCreate = array_map(function ($history) {
                        $history['event_time'] = Carbon::createFromFormat('d/m/Y H:i', $history['event_time']);
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
            ]);
            return response()->json(['message' => 'Kesalahan internal server: ' . $e->getMessage()], 500);
        }
    }
}