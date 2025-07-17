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

        $ordersToProcess = Order::where('is_stock_deducted', false)
            ->whereHas('statusHistories', function ($query) use ($today) {
                $query->whereNotNull('pickup_time')
                      ->whereDate('pickup_time', $today);
            })
            ->with('items.productVariant.product')
            ->get();

        if ($ordersToProcess->isEmpty()) {
            $this->info('No new orders picked up today to process.');
            return 0;
        }

        $this->info("Found {$ordersToProcess->count()} orders to process for today.");

        foreach ($ordersToProcess as $order) {
            try {
                DB::transaction(function () use ($order) {
                    foreach ($order->items as $item) {
                        $variant = $item->productVariant;

                        // Pastikan varian ada, memiliki SKU, dan user-nya cocok
                        if ($variant && $variant->variant_sku && $variant->product->user_id === $order->user_id) {
                            $quantityToDeduct = $item->quantity;
                            
                            $movement = StockMovement::firstOrCreate(
                                [
                                    'order_id' => $order->id,
                                    'product_variant_id' => $variant->id,
                                    'type' => 'sale',
                                ],
                                [
                                    'user_id' => $order->user_id,
                                    'quantity' => -$quantityToDeduct,
                                    'notes' => 'Pengurangan otomatis dari Order SN: ' . $order->order_sn,
                                ]
                            );

                            // ======================================================
                            // PERUBAHAN UTAMA: Kurangi stok untuk SEMUA varian dengan SKU yang sama
                            // ======================================================
                            if ($movement->wasRecentlyCreated) {
                                $targetSku = $variant->variant_sku;

                                // 1. Cari semua ProductVariant yang memiliki SKU yang sama.
                                // 2. Gunakan 'decrement' pada query builder untuk mengurangi stok 
                                //    semua baris yang cocok dalam satu query database yang efisien.
                                $affectedRows = ProductVariant::where('variant_sku', $targetSku)
                                    ->decrement('warehouse_stock', $quantityToDeduct);

                                if ($affectedRows > 0) {
                                    $this->line("   - SKU {$targetSku}: Stock deducted by {$quantityToDeduct} across {$affectedRows} variant(s).");
                                } else {
                                    // Ini bisa terjadi jika SKU dari order item tidak ditemukan lagi di tabel varian
                                    $this->warn("   - SKU {$targetSku}: Tried to deduct stock, but no matching variants found.");
                                    Log::warning("No variants found to deduct stock for SKU.", ['sku' => $targetSku, 'order_sn' => $order->order_sn]);
                                }
                                
                            } else {
                                $this->line("   - SKU {$variant->variant_sku}: Stock already deducted for this order. Skipping.");
                            }
                            // ======================================================
                            // AKHIR DARI PERUBAHAN
                            // ======================================================

                        } else {
                            $sku = $variant->variant_sku ?? $item->variant_sku;
                            Log::warning("SKU not found, user mismatch, or SKU is empty for order item.", ['order_sn' => $order->order_sn, 'sku' => $sku]);
                        }
                    }
                    
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