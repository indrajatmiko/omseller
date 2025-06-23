<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ProductScrapeController extends Controller
{
    public function getStats()
    {
        $user = Auth::user();
        $count = Product::where('user_id', $user->id)->count();
        return response()->json(['dbCount' => $count]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'scrapedData' => 'required|array',
            'scrapedData.*.shopee_product_id' => 'required|numeric',
            'scrapedData.*.product_name' => 'required|string',
            'scrapedData.*.variants' => 'sometimes|array',
            'scrapedData.*.variants.*.shopee_variant_id' => 'required|numeric',
            // ... validasi lain jika perlu
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $productsCreated = 0;
        $productsUpdated = 0;
        $variantsCreated = 0;
        $variantsUpdated = 0;

        foreach ($request->scrapedData as $productData) {
            try {
                DB::transaction(function () use ($user, $productData, &$productsCreated, &$productsUpdated, &$variantsCreated, &$variantsUpdated) {
                    
                    // Langkah 1: Update atau buat produk induk
                    $product = Product::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'shopee_product_id' => $productData['shopee_product_id'],
                        ],
                        [
                            'product_name' => $productData['product_name'],
                            'parent_sku' => $productData['parent_sku'],
                            'image_url' => $productData['image_url'],
                            'total_sales' => $productData['total_sales'] ?? 0,
                            'total_stock' => $productData['total_stock'] ?? 0,
                        ]
                    );

                    if ($product->wasRecentlyCreated) {
                        $productsCreated++;
                    } else {
                        $productsUpdated++;
                    }

                    // Langkah 2: Sinkronisasi Varian dengan Cerdas
                    $scrapedVariantIds = [];
                    if (!empty($productData['variants'])) {
                        foreach ($productData['variants'] as $variantData) {
                            $scrapedVariantIds[] = $variantData['shopee_variant_id'];

                            $variant = $product->variants()->updateOrCreate(
                                [
                                    // Kunci unik untuk mencari varian
                                    'shopee_variant_id' => $variantData['shopee_variant_id'],
                                ],
                                [
                                    // Data yang akan diperbarui atau dibuat
                                    'variant_name' => $variantData['variant_name'],
                                    'variant_sku' => $variantData['variant_sku'],
                                    'price' => $variantData['price'],
                                    'promo_price' => $variantData['promo_price'],
                                    'stock' => $variantData['stock'],
                                ]
                            );

                             if ($variant->wasRecentlyCreated) {
                                $variantsCreated++;
                            } else {
                                $variantsUpdated++;
                            }
                        }
                    }

                    // Langkah 3: Hapus varian lama yang tidak ada lagi di data scrape
                    // Ini menangani kasus jika sebuah varian dihapus dari Shopee
                    $product->variants()->whereNotIn('shopee_variant_id', $scrapedVariantIds)->delete();

                });
            } catch (Throwable $e) {
                Log::error('Failed to store product scrape data for user ' . $user->id, [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
                return response()->json(['message' => 'Kesalahan internal server: ' . $e->getMessage()], 500);
            }
        }

        return response()->json([
            'message' => 'Data produk berhasil disinkronkan.',
            'products_created' => $productsCreated,
            'products_updated' => $productsUpdated,
            'variants_created' => $variantsCreated,
            'variants_updated' => $variantsUpdated,
        ], 200);
    }
}