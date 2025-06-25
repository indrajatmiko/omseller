<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
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
            'orders.*.order_sn' => 'required|string',
            'orders.*.total_price' => 'required|numeric',
            'orders.*.order_detail_url' => 'required|url',
            'orders.*.items' => 'required|array|min:1',
            'orders.*.items.*.product_name' => 'required|string',
            'orders.*.items.*.quantity' => 'required|integer',
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
                $order = Order::updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'shopee_order_id' => $orderData['shopee_order_id'],
                    ],
                    [
                        'order_sn' => $orderData['order_sn'],
                        'buyer_username' => $orderData['buyer_username'] ?? null,
                        'total_price' => $orderData['total_price'],
                        'payment_method' => $orderData['payment_method'] ?? null,
                        'order_status' => $orderData['order_status'] ?? null,
                        'status_description' => $orderData['status_description'] ?? null,
                        'shipping_provider' => $orderData['shipping_provider'] ?? null,
                        'tracking_number' => $orderData['tracking_number'] ?? null,
                        'order_detail_url' => $orderData['order_detail_url'],
                        'scraped_at' => now(),
                    ]
                );

                if ($order->wasRecentlyCreated) {
                    $ordersCreated++;
                } else {
                    $ordersUpdated++;
                    // Hapus item lama untuk memastikan data sinkron
                    $order->items()->delete();
                }

                // Tambahkan item baru
                $itemsToCreate = [];
                foreach ($orderData['items'] as $itemData) {
                    $itemsToCreate[] = [
                        'product_name' => $itemData['product_name'],
                        'variant_description' => $itemData['variant_description'] ?? null,
                        'quantity' => $itemData['quantity'],
                        'image_url' => $itemData['image_url'] ?? null,
                    ];
                }
                $order->items()->createMany($itemsToCreate);
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