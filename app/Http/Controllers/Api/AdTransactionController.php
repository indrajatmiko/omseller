<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // Pastikan DB facade di-import
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AdTransactionController extends Controller
{
    public function store(Request $request)
    {
        // Validasi tidak mengharapkan hash dari frontend
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
        $transactionsToUpsert = [];
        $now = now();

        // 1. Tetapkan batas waktu: Hanya proses data dari kemarin dan hari ini.
        $cutoffDate = Carbon::yesterday()->startOfDay();

        // 2. Proses data, filter tanggal, dan buat hash unik di backend.
        // Kita menggunakan $index dari foreach untuk memastikan keunikan.
        foreach ($validated['transactions'] as $index => $tx) {
            try {
                $transactionDate = Carbon::createFromFormat('d/m/Y', $tx['date']);

                // Lewati data yang lebih lama dari kemarin.
                if ($transactionDate->isBefore($cutoffDate)) {
                    continue; 
                }

                // Buat hash unik di sisi server. Indeks sangat penting untuk duplikasi yang sah.
                $hash = md5($user->id . '|' . $tx['date'] . '|' . $tx['type'] . '|' . $tx['amount'] . '|' . $index);
                
                // Siapkan data untuk operasi upsert
                $transactionsToUpsert[] = [
                    'user_id' => $user->id,
                    'transaction_hash' => $hash, // Hash yang dibuat di backend
                    'transaction_date' => $transactionDate->toDateString(),
                    'transaction_type' => $tx['type'],
                    'amount' => $tx['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

            } catch (\Exception $e) {
                Log::warning('Invalid date format for ad transaction', ['data' => $tx]);
                continue;
            }
        }

        if (empty($transactionsToUpsert)) {
            return response()->json(['message' => 'Tidak ada data baru (kemarin & hari ini) untuk diproses.', 'processed' => 0], 200);
        }

        // 3. Gunakan "upsert" yang efisien.
        // Ini akan menyisipkan record baru jika `transaction_hash` tidak ada,
        // atau memperbarui record yang ada jika `transaction_hash` sudah ada.
        $processedCount = AdTransaction::upsert(
            $transactionsToUpsert,
            ['user_id', 'transaction_hash'], // Kolom unik untuk dicek
            ['transaction_date', 'transaction_type', 'amount', 'updated_at'] // Kolom yang diupdate jika sudah ada
        );

        return response()->json([
            'message' => 'Data transaksi iklan berhasil disinkronkan.',
            'processed' => $processedCount, // upsert mengembalikan jumlah record yang diproses (insert+update)
        ], 200);
    }

    /**
     * (TIDAK BERUBAH) Method ini tetap sangat relevan untuk optimasi di frontend.
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestTransactionDate()
    {
        $user = Auth::user();

        $latestDate = AdTransaction::where('user_id', $user->id)
            ->max('transaction_date');

        return response()->json([
            'latest_date' => $latestDate // Akan mengembalikan 'YYYY-MM-DD' atau null
        ]);
    }
}