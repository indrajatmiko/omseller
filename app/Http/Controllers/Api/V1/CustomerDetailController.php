<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuyerProfile;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class CustomerDetailController extends Controller
{
    /**
     * Mencari pelanggan atau menampilkan pelanggan terbaru.
     */
    public function search(Request $request): JsonResponse
    {
        $search = $request->query('q', '');
        $user = $request->user();
        $profilesData = collect();

        if (strlen($search) >= 3) {
            // Logika pencarian
            $searchTerm = '%' . $search . '%';
            $foundProfiles = BuyerProfile::where('user_id', $user->id)
                ->where(fn($q) => $q->where('buyer_real_name', 'like', $searchTerm)->orWhere('buyer_username', 'like', $searchTerm))
                ->get();

            $foundIdentifiers = $foundProfiles->map(fn($p) => $p->buyer_username . '|' . $p->address_identifier)->all();
            
            $foundOrders = Order::where('user_id', $user->id)
                ->where(fn($q) => $q->where('buyer_username', 'like', $searchTerm)->orWhere('order_sn', 'like', $searchTerm)->orWhere('address_full', 'like', $searchTerm))
                ->whereNotIn(DB::raw("CONCAT(buyer_username, '|', sha1(trim(address_full)))"), $foundIdentifiers)
                ->latest()->get()->unique(fn($o) => $o->buyer_username . '|' . sha1(trim($o->address_full)));

            $formattedProfiles = $foundProfiles->map(fn($p) => $this->formatProfileForList($p, $p->orders()->latest()->first()));
            $formattedNewBuyers = $foundOrders->map(fn($o) => $this->formatProfileForList(null, $o));

            $profilesData = collect([])->merge($formattedProfiles)->merge($formattedNewBuyers)->sortByDesc('last_order_date');
        } else {
            // Logika pelanggan terbaru
            $recentOrders = Order::where('user_id', $user->id)->where('created_at', '>=', now()->subDays(2))->latest()->get();
            $latestOrderByBuyer = $recentOrders->unique(fn($o) => $o->buyer_username . '|' . sha1(trim($o->address_full)));
            
            if ($latestOrderByBuyer->isNotEmpty()) {
                $buyerIdentifiers = $latestOrderByBuyer->map(fn($o) => ['username' => $o->buyer_username, 'address_hash' => sha1(trim($o->address_full))]);
                $existingProfiles = BuyerProfile::where('user_id', $user->id)
                    ->where(function ($q) use ($buyerIdentifiers) {
                        foreach ($buyerIdentifiers as $id) {
                            $q->orWhere(fn($sub) => $sub->where('buyer_username', $id['username'])->where('address_identifier', $id['address_hash']));
                        }
                    })->get()->keyBy(fn($p) => $p->buyer_username . '|' . $p->address_identifier);

                $profilesData = $latestOrderByBuyer->map(fn($o) => $this->formatProfileForList($existingProfiles->get($o->buyer_username . '|' . sha1(trim($o->address_full))), $o));
            }
        }
        
        return response()->json(['data' => $profilesData->values()]);
    }

    /**
     * Menampilkan detail lengkap seorang pelanggan.
     */
    public function show(string $identifier): JsonResponse
    {
        list($buyerUsername, $addressIdentifier) = explode('|', $identifier, 2);

        $profile = BuyerProfile::where('user_id', auth()->id())
            ->where('buyer_username', $buyerUsername)
            ->where('address_identifier', $addressIdentifier)
            ->first();

        $orders = Order::where('user_id', auth()->id())
            ->where('buyer_username', $buyerUsername)
            ->where(DB::raw('sha1(trim(address_full))'), $addressIdentifier)
            ->with('items')->get();
            
        if ($orders->isEmpty()) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $orderIds = $orders->pluck('id');
        $lastOrder = $orders->sortByDesc('created_at')->first();

        $topProducts = OrderItem::whereIn('order_id', $orderIds)
            ->select('product_name', 'variant_sku', DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('product_name', 'variant_sku')->orderByDesc('total_quantity')->limit(10)->get();

        $details = [
            'display_name' => $profile->buyer_real_name ?? $buyerUsername,
            'username' => $buyerUsername,
            'is_provisional' => is_null($profile),
            'address_full' => $lastOrder->address_full,
            'stats' => [
                'total_spend' => $orders->sum('total_price'),
                'total_orders' => $orderIds->count(),
                'total_items' => $orders->pluck('items')->flatten()->sum('quantity'),
                'days_since_last_order' => Carbon::parse($lastOrder->created_at)->diffInDays(now()),
            ],
            'info' => [
                'payment_methods' => $orders->pluck('payment_method')->filter()->unique()->values(),
                'shipping_providers' => $orders->pluck('shipping_provider')->filter()->unique()->values(),
            ],
            'last_order_details' => [
                'date' => $lastOrder->created_at,
                'total_price' => $lastOrder->total_price,
                'items' => $lastOrder->items->map(fn($item) => ['product_name' => $item->product_name, 'quantity' => $item->quantity]),
            ],
            'top_products' => $topProducts,
        ];

        return response()->json(['data' => $details]);
    }
    
    /** Helper untuk format data list */
    private function formatProfileForList(?BuyerProfile $profile, ?Order $order): object
    {
        $key = $order->buyer_username . '|' . sha1(trim($order->address_full));
        return (object) [
            'identifier' => $key,
            'display_name' => $profile->buyer_real_name ?? $order->buyer_username,
            'username' => $profile ? $profile->buyer_username : null,
            'is_provisional' => is_null($profile),
            'address_preview' => Str::words($order->address_full, 8, '...'),
            'last_order_date' => $order->created_at,
        ];
    }
}