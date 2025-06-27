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
        // --- LOG 1: Log seluruh request yang masuk ---
        Log::info('OrderScrapeController: Request diterima', ['request_data' => $request->all()]);

        $validator = Validator::make($request->all(), [
            'orders' => 'required|array|min:1',
            'orders.*.shopee_order_id' => 'required|string',
            'orders.*.payment_details' => 'sometimes|array|max:1',
            'orders.*.payment_details.*.product_subtotal' => 'nullable|numeric',
            'orders.*.payment_details.*.shipping_fee_paid_by_buyer' => 'nullable|numeric',
            'orders.*.payment_details.*.shipping_fee_paid_to_logistic' => 'nullable|numeric',
            'orders.*.payment_details.*.shopee_shipping_subsidy' => 'nullable|numeric',
            'orders.*.payment_details.*.admin_fee' => 'nullable|numeric',
            'orders.*.payment_details.*.service_fee' => 'nullable|numeric',
            'orders.*.payment_details.*.coins_spent_by_buyer' => 'nullable|numeric',
            'orders.*.payment_details.*.seller_voucher' => 'nullable|numeric',
            'orders.*.payment_details.*.total_income' => 'nullable|numeric',
            'orders.*.histories' => 'sometimes|array',
            'orders.*.histories.*.status' => 'required_with:orders.*.histories|string',
            'orders.*.histories.*.event_time' => 'sometimes|date_format:d/m/Y H:i',
        ]);

        if ($validator->fails()) {
            // --- LOG 2: Log jika validasi gagal ---
            Log::error('OrderScrapeController: Validasi gagal', ['errors' => $validator->errors()->toArray()]);
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();
        // --- LOG 3: Log data SETELAH validasi ---
        Log::info('OrderScrapeController: Data setelah validasi', ['validated_data' => $validatedData]);

        $user = Auth::user();
        $ordersCreated = 0;
        $ordersUpdated = 0;

        DB::beginTransaction();
        try {
            foreach ($validatedData['orders'] as $index => $orderData) {
                // --- LOG 4: Log data untuk setiap pesanan yang diproses ---
                Log::info("OrderScrapeController: Memproses order index #{$index}", ['order_data' => $orderData]);

                $order = Order::firstOrNew([
                    'user_id' => $user->id,
                    'shopee_order_id' => $orderData['shopee_order_id'],
                ]);

                $wasRecentlyCreated = !$order->exists;
                
                $orderValues = collect($orderData)->except(['items', 'payment_details', 'histories'])->all();
                
                if (!$wasRecentlyCreated) {
                    $orderValues = array_merge($order->getAttributes(), $orderValues);
                }
                $orderValues['scraped_at'] = now();

                $order->fill($orderValues);
                $order->save();
                
                if ($wasRecentlyCreated) $ordersCreated++; else $ordersUpdated++;

                // Sinkronisasi Payment Details
                if (isset($orderData['payment_details']) && !empty($orderData['payment_details'])) {
                    // --- LOG 5: Log SEBELUM mencoba menyimpan payment_details ---
                    Log::info("OrderScrapeController: Blok payment_details dimasuki untuk order #{$order->shopee_order_id}.");
                    
                    $paymentData = $orderData['payment_details'][0];
                    Log::info("OrderScrapeController: Data yang akan disimpan ke payment_details", ['payment_data' => $paymentData]);

                    $order->paymentDetails()->updateOrCreate(
                        ['order_id' => $order->id],
                        $paymentData
                    );
                    Log::info("OrderScrapeController: updateOrCreate untuk payment_details selesai.");

                } else {
                    // --- LOG 6: Log JIKA blok payment_details DILEWATI ---
                    Log::warning("OrderScrapeController: Blok payment_details DILEWATI untuk order #{$order->shopee_order_id}.", [
                        'isset' => isset($orderData['payment_details']),
                        'is_empty' => isset($orderData['payment_details']) ? empty($orderData['payment_details']) : 'N/A'
                    ]);
                }

                // Sinkronisasi Histories (contoh logging)
                if (isset($orderData['histories']) && !empty($orderData['histories'])) {
                    Log::info("OrderScrapeController: Blok histories dimasuki untuk order #{$order->shopee_order_id}.");
                    // ... (logika histories) ...
                }
            }

            DB::commit();
            Log::info('OrderScrapeController: Transaksi berhasil di-commit.');

            return response()->json([
                'message' => 'Data pesanan berhasil disinkronkan.',
                'created' => $ordersCreated,
                'updated' => $ordersUpdated,
            ], 200);

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Gagal menyimpan data pesanan untuk user ' . $user->id, [
                'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
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