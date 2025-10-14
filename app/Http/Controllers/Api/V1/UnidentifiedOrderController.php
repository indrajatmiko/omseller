<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBuyerProfileRequest;
use App\Models\BuyerProfile;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class UnidentifiedOrderController extends Controller
{
    /**
     * Menampilkan daftar pesanan yang belum teridentifikasi.
     * Logika ini mereplikasi method 'with()' dari komponen Livewire Anda.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search', '');
        $perPage = $request->query('per_page', 50);

        // 1. Ambil semua profil pembeli yang sudah dikenal
        $knownProfiles = BuyerProfile::where('user_id', auth()->id())
            ->get(['buyer_username', 'address_identifier'])
            ->keyBy(fn($profile) => $profile->buyer_username . '|' . $profile->address_identifier);

        // 2. Ambil semua order yang relevan
        $allMatchingOrders = Order::query()
            ->where('user_id', auth()->id())
            ->where('order_status', '!=', 'Dibatalkan')
            ->where('address_full', '!=', '')
            ->when($search, function ($query, $search) {
                $query->where('order_sn', 'like', '%' . $search . '%');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        // 3. Filter order di sisi PHP untuk menyembunyikan yang sudah dikenal
        $unidentifiedOrders = $allMatchingOrders->filter(function ($order) use ($knownProfiles) {
            $identifierKey = $order->buyer_username . '|' . sha1(trim($order->address_full));
            return !$knownProfiles->has($identifierKey);
        });
        
        // 4. Buat paginasi secara manual
        $page = Paginator::resolveCurrentPage('page');
        $paginatedOrders = new LengthAwarePaginator(
            $unidentifiedOrders->forPage($page, $perPage)->values(), // ->values() untuk re-index array
            $unidentifiedOrders->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        
        return response()->json($paginatedOrders);
    }

    /**
     * Menyimpan nama pembeli dan membuat/memperbarui profil.
     * Logika ini mereplikasi method 'saveBuyerName()' dari Livewire.
     */
    public function storeProfile(StoreBuyerProfileRequest $request, Order $order): JsonResponse
    {
        // Pastikan order milik user yang sedang login
        if ($order->user_id !== auth()->id()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $nameToSave = trim($request->validated('name'));

        // Buat atau perbarui profil pembeli
        $profile = BuyerProfile::updateOrCreate(
            [
                'user_id' => auth()->id(),
                'buyer_username' => $order->buyer_username,
                'address_identifier' => sha1(trim($order->address_full))
            ],
            ['buyer_real_name' => $nameToSave]
        );
        
        // Perbarui juga nama di pesanan itu sendiri jika berbeda
        if ($order->buyer_name !== $nameToSave) {
            $order->update(['buyer_name' => $nameToSave]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Buyer profile created/updated successfully.',
            'data' => $profile,
        ], 201); // 201 Created
    }
}