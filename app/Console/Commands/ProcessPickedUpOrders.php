<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessPickedUpOrders extends Command
{
    protected $signature = 'inventory:process-picked-up-orders';
    protected $description = 'Process orders picked up TODAY to deduct stock from inventory.';

    public function handle()
    {
        $this->info('Starting to process orders picked up today...');

        $today = Carbon::today();

        // Kueri ini tetap sama, karena kita masih perlu menandai pesanan sebagai selesai diproses.
        $ordersToProcess = Order::where('is_stock_deducted', false)
            ->whereHas('statusHistories', function ($query) use ($today) {
                $query->whereNotNull('pickup_time')
                      ->whereDate('pickup_time', $today);
            })
            ->with('items.productVariant.product') // Eager load relasi yang lebih dalam
            ->get();

        if ($ordersToProcess->isEmpty()) {
            $this->info('No new orders picked up today to process.');
            return 0;
        }

        $this->info("Found {$ordersToProcess->count()} orders to process for today.");

        foreach ($ordersToProcess as $order) {
            try {
                // Kita tetap menggunakan transaksi untuk memastikan semua item dalam satu pesanan berhasil atau gagal bersama.
                DB::transaction(function () use ($order) {
                    foreach ($order->items as $item) {
                        // Kita sudah eager load varian, jadi tidak perlu query lagi di dalam loop.
                        // Ini juga lebih efisien.
                        $variant = $item->productVariant;

                        if ($variant && $variant->product->user_id === $order->user_id) {
                            $quantityToDeduct = $item->quantity;
                            
                            // ======================================================
                            // PERBAIKAN UTAMA: Menerapkan Pola Idempoten
                            // ======================================================
                            $movement = StockMovement::firstOrCreate(
                                // 1. Kunci Unik: Cari movement berdasarkan kombinasi ini.
                                [
                                    'order_id' => $order->id,
                                    'product_variant_id' => $variant->id,
                                    'type' => 'sale',
                                ],
                                // 2. Nilai: Hanya akan digunakan jika record baru dibuat.
                                [
                                    'user_id' => $order->user_id,
                                    'quantity' => -$quantityToDeduct,
                                    'notes' => 'Pengurangan otomatis dari Order SN: ' . $order->order_sn,
                                ]
                            );

                            // 3. Aksi Kondisional: Hanya kurangi stok jika movement BARU dibuat.
                            if ($movement->wasRecentlyCreated) {
                                $variant->decrement('warehouse_stock', $quantityToDeduct);
                                $this->line("   - SKU {$variant->variant_sku}: Stock deducted by {$quantityToDeduct}.");
                            } else {
                                $this->line("   - SKU {$variant->variant_sku}: Stock already deducted for this order. Skipping.");
                            }
                            // ======================================================
                            // AKHIR DARI PERBAIKAN
                            // ======================================================

                        } else {
                            Log::warning("SKU not found or user mismatch for order item.", ['order_sn' => $order->order_sn, 'sku' => $item->variant_sku]);
                        }
                    }
                    
                    // Menandai pesanan selesai diproses tetap penting
                    $order->update(['is_stock_deducted' => true]);
                    $this->info("Successfully processed Order SN: {$order->order_sn}");
                });
            } catch (\Exception $e) {
                Log::error('Failed to process order for stock deduction.', ['order_sn' => $order->order_sn, 'error' => $e->getMessage()]);
                $this->error("Failed to process Order SN: {$order->order_sn}. Check logs for details.");
            }
        }

        $this->info('Finished processing orders for today.');
        return 0;
    }
}