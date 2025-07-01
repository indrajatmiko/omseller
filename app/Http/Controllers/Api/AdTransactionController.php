<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AdTransactionController extends Controller
{
    public function store(Request $request)
    {
        // Validasi tidak berubah dari permintaan sebelumnya (tanpa hash)
        $validator = Validator::make($request->all(), [
            'transactions' => 'required|array',
            'transactions.*.date' => 'required|string', // Format: dd/mm/yyyy
            'transactions.*.type' => 'required|string',
            'transactions.*.amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $validated = $validator->validated();
        
        $potentialTransactions = [];
        $datesToProcess = [];
        $today = Carbon::today(); // Dapatkan tanggal hari ini untuk perbandingan

        // 1. Filter data dari scraper: Abaikan data 'hari ini' dan siapkan data lainnya
        foreach ($validated['transactions'] as $tx) {
            try {
                $transactionDate = Carbon::createFromFormat('d/m/Y', $tx['date']);

                // === LOGIKA INTI: HANYA PROSES DATA SEBELUM HARI INI ===
                if ($transactionDate->isSameDay($today)) {
                    continue; // Abaikan transaksi hari ini
                }

                $dateString = $transactionDate->toDateString();
                $datesToProcess[] = $dateString;

                $potentialTransactions[] = [
                    'user_id' => $user->id,
                    'transaction_date' => $dateString,
                    'transaction_type' => $tx['type'],
                    'amount' => $tx['amount'],
                ];

            } catch (\Exception $e) {
                Log::warning('Invalid date format for ad transaction', ['data' => $tx]);
                continue;
            }
        }

        if (empty($potentialTransactions)) {
            return response()->json(['message' => 'Tidak ada data baru (sebelum hari ini) untuk diproses.', 'inserted' => 0], 200);
        }

        $uniqueDates = array_unique($datesToProcess);

        // 2. Ambil semua transaksi yang SUDAH ADA di DB untuk tanggal yang relevan
        $existingTransactions = AdTransaction::where('user_id', $user->id)
            ->whereIn('transaction_date', $uniqueDates)
            ->get();

        // 3. Buat "sidik jari" untuk data yang sudah ada agar perbandingan lebih cepat.
        // Kita hitung jumlah kejadian untuk setiap kombinasi unik, untuk menangani transaksi identik di hari yang sama.
        $existingFingerprints = collect($existingTransactions)->countBy(function ($tx) {
            // Format: 'YYYY-MM-DD|Tipe Transaksi|12345.00'
            return $tx->transaction_date->format('Y-m-d') . '|' . $tx->transaction_type . '|' . number_format($tx->amount, 2, '.', '');
        });

        // 4. Bandingkan data dari scraper dengan data yang ada, dan kumpulkan hanya data yang BARU
        $transactionsToInsert = [];
        $now = now();
        
        foreach ($potentialTransactions as $tx) {
            $fingerprint = $tx['transaction_date'] . '|' . $tx['transaction_type'] . '|' . number_format($tx['amount'], 2, '.', '');

            // Jika sidik jari ada dan hitungannya > 0, berarti ini duplikat.
            if ($existingFingerprints->get($fingerprint, 0) > 0) {
                // Kurangi hitungannya, seolah-olah kita "mencocokkan" satu record.
                $existingFingerprints[$fingerprint]--;
            } else {
                // Jika tidak ada atau hitungannya sudah 0, ini adalah record baru.
                $transactionsToInsert[] = [
                    'user_id' => $tx['user_id'],
                    'transaction_date' => $tx['transaction_date'],
                    'transaction_type' => $tx['transaction_type'],
                    'amount' => $tx['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // 5. Sisipkan hanya data yang benar-benar baru dalam satu kali proses
        if (!empty($transactionsToInsert)) {
            // Gunakan DB::table()->insert() untuk performa bulk insert terbaik
            DB::table('ad_transactions')->insert($transactionsToInsert);
        }

        return response()->json([
            'message' => 'Data transaksi iklan berhasil disinkronkan.',
            'inserted' => count($transactionsToInsert),
        ], 200);
    }

    /**
     * (TIDAK BERUBAH) Method ini tetap berguna untuk keperluan lain di masa depan.
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestTransactionDate()
    {
        $user = Auth::user();

        $latestDate = AdTransaction::where('user_id', $user->id)
            ->max('transaction_date');

        return response()->json([
            'latest_date' => $latestDate
        ]);
    }
}