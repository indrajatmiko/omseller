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

        // Ambil tanggal hari ini.
        $today = Carbon::today();

        // Query untuk mencari Order yang memenuhi SEMUA kondisi berikut:
        // 1. Stoknya belum dikurangi (is_stock_deducted = false).
        // 2. Memiliki riwayat status (statusHistories).
        // 3. Di dalam riwayat status tersebut, ada 'pickup_time' yang tidak null.
        // 4. DAN 'pickup_time' tersebut berada pada tanggal hari ini (whereDate).
        $ordersToProcess = Order::where('is_stock_deducted', false)
            ->whereHas('statusHistories', function ($query) use ($today) {
                $query->whereNotNull('pickup_time')
                      ->whereDate('pickup_time', $today); // <-- PERBAIKAN UTAMA: Filter berdasarkan tanggal hari ini
            })
            ->with('items')
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

        $this->info('Finished processing orders for today.');
        return 0;
    }
}