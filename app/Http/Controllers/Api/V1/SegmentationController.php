<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BuyerProfile;
use App\Services\CustomerSegmentationService; // Import service
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

class SegmentationController extends Controller
{
    protected $segmentationService;

    public function __construct(CustomerSegmentationService $segmentationService)
    {
        $this->segmentationService = $segmentationService;
    }

    /**
     * Endpoint untuk statistik dasbor.
     */
    public function dashboardStats(): JsonResponse
    {
        $allSegments = $this->segmentationService->getSegmentedData();
        if ($allSegments->isEmpty()) {
            return response()->json([
                'total_customers' => 0,
                'average_spend' => 0,
                'average_cycle' => 0,
                'segment_distribution' => [],
            ]);
        }
        
        $totalCustomers = $allSegments->count();
        $averageSpend = $allSegments->avg('total_spend');
        $averageCycle = $allSegments->whereNotNull('purchase_cycle_days')->avg('purchase_cycle_days');
        $segmentDistribution = $allSegments->groupBy('segment_label')
            ->map(fn($group) => round(($group->count() / $totalCustomers) * 100, 1))
            ->sortDesc();

        return response()->json([
            'total_customers' => $totalCustomers,
            'average_spend' => round($averageSpend, 2),
            'average_cycle' => round($averageCycle),
            'segment_distribution' => $segmentDistribution,
        ]);
    }

    /**
     * Endpoint untuk daftar pelanggan tersegmentasi.
     */
    public function index(Request $request): JsonResponse
    {
        $allSegments = $this->segmentationService->getSegmentedData();

        // Filter
        $filterSegment = $request->query('filter_segment', 'all');
        $filtered = $filterSegment !== 'all' 
            ? $allSegments->filter(fn($s) => $s->segment_label === $filterSegment)
            : $allSegments;

        // Sort
        $sortBy = $request->query('sort_by', 'total_spend');
        $sortDir = $request->query('sort_dir', 'desc');
        $sorted = $sortBy === 'last_order'
            ? $filtered->sortBy('last_order_raw', SORT_REGULAR, $sortDir === 'desc')
            : $filtered->sortBy($sortBy, SORT_REGULAR, $sortDir === 'desc');

        // Paginate
        $page = $request->query('page', 1);
        $perPage = $request->query('per_page', 50);
        $paginated = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json($paginated);
    }

    /**
     * Endpoint untuk detail spesifik pelanggan.
     */
    public function show(BuyerProfile $buyerProfile): JsonResponse
    {
        // Otorisasi: Pastikan profil milik user yang login
        if ($buyerProfile->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Ambil data detail dari service
        $allSegments = $this->segmentationService->getSegmentedData();
        $customerData = $allSegments->firstWhere('id', $buyerProfile->id);

        if (!$customerData) {
            return response()->json(['message' => 'Customer not found in segmentation data.'], 404);
        }

        // Ambil data tambahan (riwayat pesanan & produk favorit)
        $customerOrders = $buyerProfile->orders()->with('items')->latest()->limit(10)->get();
        
        $frequentItems = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', auth()->id())
            ->where('orders.buyer_username', $buyerProfile->buyer_username)
            ->where(DB::raw('sha1(trim(orders.address_full))'), $buyerProfile->address_identifier)
            ->select('order_items.product_name', 'order_items.variant_sku', DB::raw('SUM(order_items.quantity) as total_quantity'))
            ->groupBy('order_items.product_name', 'order_items.variant_sku')
            ->orderByDesc('total_quantity')->limit(5)->get();

        return response()->json([
            'customer' => $customerData,
            'recent_orders' => $customerOrders,
            'frequent_items' => $frequentItems,
        ]);
    }
}