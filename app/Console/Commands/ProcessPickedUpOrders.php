<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon; // <-- Tambahkan ini

class ProcessPickedUpOrders extends Command
{
    protected $signature = 'inventory:process-picked-up-orders';
    protected $description = 'Process recent orders that have been picked up to deduct stock from inventory.';

    public function handle()
    {
        $this->info('Starting to process picked-up orders...');

        // BATASAN WAKTU: Hanya proses pesanan dengan pickup_time dalam 7 hari terakhir.
        // Ini adalah jaring pengaman agar tidak memproses semua pesanan lama saat dijalankan pertama kali.
        $cutOffDate = Carbon::now()->subDays(7)->startOfDay();

        $ordersToProcess = Order::where('is_stock_deducted', false)
            ->whereHas('statusHistories', function ($query) use ($cutOffDate) {
                $query->whereNotNull('pickup_time')
                      ->where('pickup_time', '>=', $cutOffDate); // <-- PERBAIKAN LOGIKA UTAMA
            })
            ->with('items')
            ->get();

        if ($ordersToProcess->isEmpty()) {
            $this->info('No new picked-up orders to process within the last 7 days.');
            return 0;
        }

        $this->info("Found {$ordersToProcess->count()} orders to process.");

        foreach ($ordersToProcess as $order) {
            try {
                DB::transaction(function () use ($order) {
                    foreach ($order->items as $item) {
                        $variant = ProductVariant::where('variant_sku', $item->variant_sku)
                                                 ->whereHas('product', fn($q) => $q->where('user_id', $order->user_id))
                                                 ->first();

                        if ($variant) {
                            $quantityToDeduct = $item->quantity;
                            
                            $variant->decrement('warehouse_stock', $quantityToDeduct);
                            
                            StockMovement::create([
                                'user_id' => $order->user_id,
                                'product_variant_id' => $variant->id,
                                'order_id' => $order->id,
                                'type' => 'sale',
                                'quantity' => -$quantityToDeduct,
                                'notes' => 'Pengurangan otomatis dari Order SN: ' . $order->order_sn,
                            ]);
                        } else {
                            Log::warning("SKU not found for order item.", ['order_sn' => $order->order_sn, 'sku' => $item->variant_sku]);
                        }
                    }
                    $order->update(['is_stock_deducted' => true]);
                    $this->line("Successfully processed Order SN: {$order->order_sn}");
                });
            } catch (\Exception $e) {
                Log::error('Failed to process order for stock deduction.', ['order_sn' => $order->order_sn, 'error' => $e->getMessage()]);
                $this->error("Failed to process Order SN: {$order->order_sn}. Check logs for details.");
            }
        }

        $this->info('Finished processing orders.');
        return 0;
    }
}