<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateCustomerRequest;
use App\Http\Requests\Api\V1\StoreManualOrderRequest;
use App\Models\BuyerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class CustomerController extends Controller
{
    /**
     * Update nama pelanggan.
     */
    public function update(UpdateCustomerRequest $request, BuyerProfile $buyerProfile): JsonResponse
    {
        // Pastikan user hanya bisa mengupdate customer miliknya
        if ($buyerProfile->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $buyerProfile->update([
            'buyer_real_name' => $request->validated('name'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Customer name updated successfully.',
            'data' => $buyerProfile->fresh(),
        ]);
    }

    /**
     * Menambahkan pesanan manual untuk pelanggan.
     */
    public function storeManualOrder(StoreManualOrderRequest $request, BuyerProfile $buyerProfile): JsonResponse
    {
        if ($buyerProfile->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $validated = $request->validated();
        
        // Mulai transaksi database
        DB::beginTransaction();
        try {
            // 1. Hitung total harga di server untuk keamanan
            $totalPrice = collect($validated['items'])->sum(function ($item) {
                return $item['quantity'] * $item['price'];
            });

            // 2. Buat pesanan utama
            $order = $buyerProfile->orders()->create([
                'user_id' => auth()->id(),
                'order_date' => $validated['order_date'],
                'buyer_username' => $buyerProfile->buyer_username, // Ambil dari profil
                'total_price' => $totalPrice,
                'payment_method' => $validated['payment_method'] ?? 'Manual Input',
                'order_status' => 'COMPLETED', // Asumsi pesanan manual langsung selesai
                'address_full' => 'Manual order - no address', // Atau ambil dari order terakhir jika perlu
                'is_stock_deducted' => false, // Asumsi tidak mengelola stok
            ]);

            // 3. Siapkan data item pesanan
            $orderItems = collect($validated['items'])->map(function ($item) {
                return [
                    'product_name' => $item['product_name'],
                    'variant_sku' => $item['variant_sku'] ?? null,
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ];
            });

            // 4. Buat item pesanan
            $order->items()->createMany($orderItems);

            // Jika semua berhasil, commit transaksi
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Manual order created successfully.',
                'data' => $order->load('items'), // Muat relasi items untuk respons
            ], 201); // 201 Created

        } catch (Throwable $e) {
            // Jika ada error, rollback semua perubahan
            DB::rollBack();

            // Log error untuk debug
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create manual order. Please try again.',
            ], 500); // 500 Internal Server Error
        }
    }
}