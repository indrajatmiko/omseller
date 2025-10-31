<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServiceFee;
use App\Models\ServiceFeeDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarginCalculatorController extends Controller
{
    /**
     * Mengambil daftar biaya admin (kategori produk) berdasarkan tipe penjual.
     */
    public function getAdminFees(Request $request)
    {
        $validated = $request->validate([
            'seller_type' => ['required', Rule::in(['non_star', 'mall'])],
        ]);

        $adminFees = ServiceFee::where('platform', 'shopee')
            ->where('fee_type', 'admin_fee')
            ->where('seller_type', $validated['seller_type'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'value']); // Hanya ambil kolom yang diperlukan

        return response()->json(['data' => $adminFees]);
    }

    /**
     * Mengambil daftar biaya program layanan (cth: Gratis Ongkir Xtra).
     */
    public function getProgramFees()
    {
        $programFees = ServiceFee::where('platform', 'shopee')
            ->where('fee_type', 'program_fee')
            ->where('is_active', true)
            ->get(['id', 'name', 'value', 'max_cap', 'value_type']);

        return response()->json(['data' => $programFees]);
    }

    /**
     * Mengambil rincian detail subkategori dengan paginasi dan pencarian.
     */
    public function getCategoryDetails(Request $request)
    {
        $validated = $request->validate([
            'seller_type' => ['required', Rule::in(['non_star', 'mall'])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $details = ServiceFeeDetail::with('serviceFee:id,name,value') // Eager load relasi dengan kolom spesifik
            ->whereHas('serviceFee', function ($query) use ($validated) {
                $query->where('platform', 'shopee')
                      ->where('seller_type', $validated['seller_type']);
            })
            ->when($request->search, function ($query, $search) {
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('subcategory_name', 'like', "%{$search}%")
                             ->orWhere('description', 'like', "%{$search}%")
                             ->orWhereHas('serviceFee', fn($q) => $q->where('name', 'like', "%{$search}%"));
                });
            })
            ->select('id', 'service_fee_id', 'subcategory_name', 'description')
            ->paginate(20); // Paginasi untuk mobile bisa lebih banyak

        return response()->json($details);
    }

/**
     * Melakukan perhitungan margin berdasarkan input dari aplikasi.
     */
    public function calculate(Request $request)
    {
        $validated = $request->validate([
            'cost_price'          => ['required', 'numeric', 'min:0'],
            'selling_price'       => ['required', 'numeric', 'min:0'],
            'admin_fee_id'        => ['required', 'integer', 'exists:service_fees,id'],
            'program_fees'        => ['nullable', 'array'],
            'program_fees.*'      => ['integer', 'exists:service_fees,id'],
            'affiliate_commission'=> ['required', 'numeric', 'min:0', 'max:100'],
            'ads_cost'            => ['required', 'numeric', 'min:0', 'max:100'],
            'operational_cost'    => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $hargaModal = (float) $validated['cost_price'];
        $hargaJual = (float) $validated['selling_price'];
        $breakdown = [];

        if ($hargaJual == 0) {
            return response()->json(['message' => 'Harga Jual tidak boleh nol.'], 422);
        }

        // 1. Hitung Potongan Admin
        $adminFee = ServiceFee::find($validated['admin_fee_id']);
        $potonganAdmin = $hargaJual * ($adminFee->value / 100);
        $breakdown[] = [
            'label' => sprintf('%s (%.2f%%)', $adminFee->name, $adminFee->value),
            'value' => round($potonganAdmin, 2),
            'value_formatted' => 'Rp ' . number_format($potonganAdmin, 0, ',', '.'),
        ];

        // 2. Hitung Biaya Program Tambahan
        $biayaTambahan = 0;
        if (!empty($validated['program_fees'])) {
            $programs = ServiceFee::find($validated['program_fees']);
            foreach ($programs as $program) {
                $isCapped = false;
                // Bedakan perhitungan berdasarkan value_type
                if ($program->value_type === 'percentage') {
                    $potongan = $hargaJual * ($program->value / 100);
                    if ($program->max_cap && $potongan > $program->max_cap) {
                        $potongan = $program->max_cap;
                        $isCapped = true;
                    }
                    $labelDetails = [sprintf('%.2f%%', $program->value)];
                } else { // fixed
                    $potongan = $program->value;
                    if ($program->max_cap && $potongan > $program->max_cap) {
                        $potongan = $program->max_cap;
                        $isCapped = true;
                    }
                    $labelDetails = ['Rp ' . number_format($program->value, 0, ',', '.')];
                }
                if ($isCapped) {
                    $labelDetails[] = 'Dibatasi';
                }
                $label = sprintf('%s (%s)', $program->name, implode(' & ', $labelDetails));
                $biayaTambahan += $potongan;

                $breakdown[] = [
                    'label' => $label,
                    'value' => round($potongan, 2),
                    'value_formatted' => 'Rp ' . number_format($potongan, 0, ',', '.'),
                ];
            }
        }

        // 3. Hitung Biaya Toko (tidak ada perubahan di sini)
        $potonganAfiliasi = $hargaJual * ($validated['affiliate_commission'] / 100);
        $breakdown[] = [
            'label' => sprintf('Komisi Afiliasi (%.2f%%)', $validated['affiliate_commission']),
            'value' => round($potonganAfiliasi, 2),
            'value_formatted' => 'Rp ' . number_format($potonganAfiliasi, 0, ',', '.'),
        ];
        $potonganIklan = $hargaJual * ($validated['ads_cost'] / 100);
        $breakdown[] = [
            'label' => sprintf('Biaya Iklan (%.2f%%)', $validated['ads_cost']),
            'value' => round($potonganIklan, 2),
            'value_formatted' => 'Rp ' . number_format($potonganIklan, 0, ',', '.'),
        ];
        $potonganOperasional = $hargaJual * ($validated['operational_cost'] / 100);
        $breakdown[] = [
            'label' => sprintf('Biaya Operasional (%.2f%%)', $validated['operational_cost']),
            'value' => round($potonganOperasional, 2),
            'value_formatted' => 'Rp ' . number_format($potonganOperasional, 0, ',', '.'),
        ];
        $biayaToko = $potonganIklan + $potonganOperasional + $potonganAfiliasi;

        // 4. Perhitungan Final
        $keuntunganBersih = $hargaJual - $hargaModal - $potonganAdmin - $biayaTambahan - $biayaToko;
        $margin = ($hargaJual > 0) ? ($keuntunganBersih / $hargaJual) * 100 : 0;

        // 5. Siapkan Respons
        $result = [
            'profit_amount'      => round($keuntunganBersih, 2),
            'margin_percentage'  => round($margin, 2),
            'profit_formatted'   => 'Rp ' . number_format($keuntunganBersih, 0, ',', '.'),
            'margin_formatted'   => number_format($margin, 2, ',', '.') . ' %',
            'breakdown'          => $breakdown,
        ];

        return response()->json(['data' => $result]);
    }
}