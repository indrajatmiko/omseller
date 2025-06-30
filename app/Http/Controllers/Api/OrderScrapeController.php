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
        // --- Validasi tidak berubah ---
        $validator = Validator::make($request->all(), [
            'orders' => 'required|array|min:1',
            'orders.*.shopee_order_id' => 'required|string',
            'orders.*.order_sn' => 'sometimes|string|nullable',
            'orders.*.buyer_username' => 'sometimes|string|nullable',
            'orders.*.total_price' => 'sometimes|numeric|nullable',
            'orders.*.payment_method' => 'sometimes|string|nullable',
            'orders.*.order_status' => 'sometimes|string|nullable',
            'orders.*.status_description' => 'sometimes|string|nullable',
            'orders.*.shipping_provider' => 'sometimes|string|nullable',
            'orders.*.tracking_number' => 'sometimes|string|nullable',
            'orders.*.order_detail_url' => 'sometimes|url|nullable',
            'orders.*.address_full' => 'sometimes|string|nullable',
            'orders.*.final_income' => 'sometimes|numeric|nullable',
            'orders.*.items' => 'sometimes|array',
            'orders.*.items.*.product_name' => 'required_with:orders.*.items|string',
            'orders.*.items.*.variant_sku' => 'nullable|string',
            'orders.*.items.*.price' => 'required_with:orders.*.items|numeric',
            'orders.*.items.*.quantity' => 'required_with:orders.*.items|integer',
            'orders.*.items.*.subtotal' => 'required_with:orders.*.items|numeric',
            'orders.*.payment_details' => 'sometimes|array|max:1',
            'orders.*.payment_details.*.product_subtotal' => 'nullable|numeric',
            'orders.*.payment_details.*.shipping_fee_paid_by_buyer' => 'nullable|numeric',
            'orders.*.payment_details.*.shipping_fee_paid_to_logistic' => 'nullable|numeric',
            'orders.*.payment_details.*.shopee_shipping_subsidy' => 'nullable|numeric',
            'orders.*.payment_details.*.admin_fee' => 'nullable|numeric',
            'orders.*.payment_details.*.service_fee' => 'nullable|numeric',
            'orders.*.payment_details.*.coins_spent_by_buyer' => 'nullable|numeric',
            'orders.*.payment_details.*.total_income' => 'nullable|numeric',
            'orders.*.payment_details.*.seller_voucher' => 'nullable|numeric',
            'orders.*.payment_details.*.shipping_fee_subtotal' => 'nullable|numeric',
            'orders.*.payment_details.*.shipping_fee_estimate' => 'nullable|numeric',
            'orders.*.payment_details.*.other_fees' => 'nullable|numeric',
            'orders.*.payment_details.*.shop_voucher' => 'nullable|numeric',
            'orders.*.payment_details.*.ams_commission_fee' => 'nullable|numeric',
            'orders.*.histories' => 'sometimes|array',
            'orders.*.histories.*.status' => 'required_with:orders.*.histories|string',
            'orders.*.histories.*.description' => 'sometimes|string|nullable',
            'orders.*.histories.*.event_time' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        $user = Auth::user();
        $ordersCreated = 0;
        $ordersUpdated = 0;

        DB::beginTransaction();
        try {
            foreach ($validatedData['orders'] as $orderData) {
                // ====================================================================
                // ===             MODIFIKASI UTAMA DIMULAI DARI SINI             ===
                // ====================================================================

                // 1. Cari atau buat instance Order baru (sama seperti sebelumnya)
                $order = Order::withCount('statusHistories')->firstOrNew([
                    'user_id' => $user->id,
                    'shopee_order_id' => $orderData['shopee_order_id'],
                ]);

                $wasRecentlyCreated = !$order->exists;
                
                // 2. Cek apakah status berubah SEBELUM di-fill dengan data baru
                $statusHasChanged = !$wasRecentlyCreated &&
                    isset($orderData['order_status']) && 
                    $order->order_status !== $orderData['order_status'];

                $isOldOrderWithoutHistory = !$wasRecentlyCreated && $order->status_histories_count === 0;

                // 3. Langsung fill data utama. Ini lebih bersih daripada array_merge.
                // Eloquent cukup pintar untuk hanya mengupdate kolom yang ada di $fillable
                $order->fill($orderData);
                $order->scraped_at = now(); // Selalu update timestamp
                $order->save();

                // 4. Logika baru untuk mencatat riwayat status
                if ($wasRecentlyCreated || $statusHasChanged || $isOldOrderWithoutHistory) {
                    if (isset($orderData['order_status'])) {
                        $description = $orderData['status_description'] ?? null;
                        $pickupTime = null;

                        if ($description && str_contains($description, 'Paket dipick up pada')) {
                            preg_match('/(\d{2}\/\d{2}\/\d{4})/', $description, $matches);
                            if (isset($matches[1])) {
                                try {
                                    $pickupTime = Carbon::createFromFormat('d/m/Y', $matches[1])->startOfDay();
                                } catch (\Exception $e) {
                                    Log::warning("Gagal mem-parsing tanggal pickup untuk order {$order->shopee_order_id}: {$matches[1]}");
                                }
                            }
                        }

                        // Buat entri baru di tabel riwayat status
                        $order->statusHistories()->create([
                            'status' => $orderData['order_status'],
                            'description' => $description,
                            'pickup_time' => $pickupTime,
                            'scrape_time' => now(),
                        ]);
                    }
                }

                // Update counter (sama seperti sebelumnya)
                if ($wasRecentlyCreated) {
                    $ordersCreated++;
                } else {
                    $ordersUpdated++;
                }

                // 5. Sinkronisasi Data Relasional (sama seperti sebelumnya)
                if (isset($orderData['items']) && !empty($orderData['items'])) {
                    $order->items()->delete();
                    $order->items()->createMany($orderData['items']);
                }

                if (isset($orderData['payment_details']) && !empty($orderData['payment_details'])) {
                    $order->paymentDetails()->updateOrCreate(['order_id' => $order->id], $orderData['payment_details'][0]);
                }

                if (isset($orderData['histories'])) {
                    $order->histories()->delete();
                    $historiesToCreate = array_map(function ($history) {
                        if (isset($history['event_time'])) {
                            try {
                                $history['event_time'] = Carbon::createFromFormat('d/m/Y H:i', $history['event_time']);
                            } catch (\Exception $e) {
                                $history['event_time'] = now();
                            }
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
                'request' => $request->all()
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