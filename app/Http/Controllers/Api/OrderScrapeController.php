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
        // Validasi yang diperbarui dengan kunci baru Anda
        $validator = Validator::make($request->all(), [
            'orders' => 'required|array|min:1',
            'orders.*.shopee_order_id' => 'required|string',
            // ... (validasi order dasar) ...
            'orders.*.order_sn' => 'sometimes|string|nullable',
            
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
            'orders.*.payment_details.*.shop_voucher' => 'nullable|numeric',
            'orders.*.payment_details.*.ams_commission_fee' => 'nullable|numeric', // Validasi baru
            
            'orders.*.histories' => 'sometimes|array',
            'orders.*.histories.*.status' => 'required_with:orders.*.histories|string',
            'orders.*.histories.*.description' => 'sometimes|string|nullable',
            'orders.*.histories.*.event_time' => 'sometimes|string', // Ubah ke string untuk penanganan manual
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
                $order = Order::firstOrNew([
                    'user_id' => $user->id, 'shopee_order_id' => $orderData['shopee_order_id'],
                ]);
                $wasRecentlyCreated = !$order->exists;
                $orderValues = collect($orderData)->except(['items', 'payment_details', 'histories'])->all();
                if (!$wasRecentlyCreated) $orderValues = array_merge($order->getAttributes(), $orderValues);
                $orderValues['scraped_at'] = now();
                $order->fill($orderValues)->save();
                
                if ($wasRecentlyCreated) $ordersCreated++; else $ordersUpdated++;

                // Sinkronisasi Payment Details (tidak ada log di sini)
                if (isset($orderData['payment_details']) && !empty($orderData['payment_details'])) {
                    $order->paymentDetails()->updateOrCreate(['order_id' => $order->id], $orderData['payment_details'][0]);
                }

                // --- LOGGING FOKUS PADA HISTORIES ---
                if (isset($orderData['histories']) && !empty($orderData['histories'])) {
                    Log::info("[HISTORY-DEBUG] Blok histories dimasuki untuk order #{$order->shopee_order_id}");
                    
                    // Hapus riwayat lama agar bisa diganti dengan yang baru
                    $order->histories()->delete();
                    Log::info("[HISTORY-DEBUG] Riwayat lama untuk order #{$order->shopee_order_id} telah dihapus.");
                    
                    $historiesToCreate = [];
                    foreach($orderData['histories'] as $history) {
                        if (isset($history['event_time'])) {
                            try {
                                // Coba parse dengan format yang diharapkan
                                $history['event_time'] = Carbon::createFromFormat('d/m/Y H:i', $history['event_time']);
                                $historiesToCreate[] = $history;
                            } catch (\Exception $e) {
                                // Jika gagal, log error dan coba parse tanpa format (lebih fleksibel)
                                Log::warning("[HISTORY-DEBUG] Gagal parse tanggal dengan format d/m/Y H:i", [
                                    'order_id' => $order->shopee_order_id,
                                    'event_time_string' => $history['event_time'],
                                    'error' => $e->getMessage()
                                ]);
                                try {
                                    $history['event_time'] = Carbon::parse($history['event_time']);
                                    $historiesToCreate[] = $history;
                                } catch (\Exception $e2) {
                                    // Jika masih gagal, log sebagai error fatal dan lewati item ini
                                    Log::error("[HISTORY-DEBUG] Gagal total parse tanggal", [
                                        'order_id' => $order->shopee_order_id,
                                        'event_time_string' => $history['event_time'],
                                        'error' => $e2->getMessage()
                                    ]);
                                }
                            }
                        }
                    }
                    
                    Log::info("[HISTORY-DEBUG] Data histories yang SIAP untuk di-create", ['data' => $historiesToCreate]);
                    
                    if (!empty($historiesToCreate)) {
                        $order->histories()->createMany($historiesToCreate);
                        Log::info("[HISTORY-DEBUG] createMany untuk histories telah dieksekusi.");
                    } else {
                        Log::warning("[HISTORY-DEBUG] Tidak ada data history yang valid untuk dibuat setelah diproses.");
                    }

                } else {
                    Log::info("[HISTORY-DEBUG] Blok histories DILEWATI untuk order #{$order->shopee_order_id} (tidak ada data atau kosong).");
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