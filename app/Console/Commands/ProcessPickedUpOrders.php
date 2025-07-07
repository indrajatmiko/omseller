<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessPickedUpOrders extends Command
{
    protected $signature = 'inventory:process-picked-up-orders';
    protected $description = 'Process orders that have been picked up to deduct stock from inventory.';

    public function handle()
    {
        $this->info('Starting to process picked-up orders...');

        // Ambil semua order yang sudah di-pickup tapi stoknya belum dikurangi.
        $ordersToProcess = Order::where('is_stock_deducted', false)
            ->whereHas('statusHistories', function ($query) {
                $query->whereNotNull('pickup_time');
            })
            ->with('items') // Eager load items untuk efisiensi
            ->get();

        if ($ordersToProcess->isEmpty()) {
            $this->info('No new picked-up orders to process.');
            return 0;
        }

        $this->info("Found {$ordersToProcess->count()} orders to process.");

        foreach ($ordersToProcess as $order) {
            try {
                DB::transaction(function () use ($order) {
                    foreach ($order->items as $item) {
                        // Cari variant berdasarkan SKU dan pastikan milik user yang sama
                        $variant = ProductVariant::where('variant_sku', $item->variant_sku)
                                                 ->whereHas('product', fn($q) => $q->where('user_id', $order->user_id))
                                                 ->first();

                        if ($variant) {
                            $quantityToDeduct = $item->quantity;
                            
                            // 1. Kurangi stok gudang
                            $variant->decrement('warehouse_stock', $quantityToDeduct);
                            
                            // 2. Buat catatan di buku besar (ledger)
                            StockMovement::create([
                                'user_id' => $order->user_id,
                                'product_variant_id' => $variant->id,
                                'order_id' => $order->id,
                                'type' => 'sale',
                                'quantity' => -$quantityToDeduct, // Gunakan nilai negatif untuk penjualan
                                'notes' => 'Pengurangan otomatis dari Order SN: ' . $order->order_sn,
                            ]);
                        } else {
                            Log::warning("SKU not found for order item.", ['order_sn' => $order->order_sn, 'sku' => $item->variant_sku]);
                        }
                    }
                    // 3. Tandai order ini sudah diproses agar tidak dieksekusi lagi
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