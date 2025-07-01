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
        // Validasi tidak berubah
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
        $today = Carbon::today();

        // 1. Filter data dari scraper (tidak berubah)
        foreach ($validated['transactions'] as $tx) {
            try {
                $transactionDate = Carbon::createFromFormat('d/m/Y', $tx['date']);
                if ($transactionDate->isSameDay($today)) {
                    continue;
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

        // 2. Ambil data yang sudah ada (tidak berubah)
        $existingTransactions = AdTransaction::where('user_id', $user->id)
            ->whereIn('transaction_date', $uniqueDates)
            ->get();

        // 3. Buat "sidik jari" dan konversi ke array biasa <<< INI PERUBAHANNYA
        $existingFingerprints = collect($existingTransactions)->countBy(function ($tx) {
            return $tx->transaction_date->format('Y-m-d') . '|' . $tx->transaction_type . '|' . number_format($tx->amount, 2, '.', '');
        })->all(); // <-- TAMBAHKAN .all() DI SINI

        // 4. Bandingkan data (logika ini sekarang akan bekerja dengan benar)
        $transactionsToInsert = [];
        $now = now();
        
        foreach ($potentialTransactions as $tx) {
            $fingerprint = $tx['transaction_date'] . '|' . $tx['transaction_type'] . '|' . number_format($tx['amount'], 2, '.', '');

            // Sekarang kita memeriksa array, bukan collection. isset() lebih cepat.
            if (isset($existingFingerprints[$fingerprint]) && $existingFingerprints[$fingerprint] > 0) {
                // Operasi ini sekarang aman karena $existingFingerprints adalah array biasa.
                $existingFingerprints[$fingerprint]--;
            } else {
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

        // 5. Sisipkan data baru (tidak berubah)
        if (!empty($transactionsToInsert)) {
            DB::table('ad_transactions')->insert($transactionsToInsert);
        }

        return response()->json([
            'message' => 'Data transaksi iklan berhasil disinkronkan.',
            'inserted' => count($transactionsToInsert),
        ], 200);
    }

    /**
     * (TIDAK BERUBAH)
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