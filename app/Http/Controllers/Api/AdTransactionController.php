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
        // 1. Validasi Diperbarui: transaction_hash tidak lagi diperlukan
        $validator = Validator::make($request->all(), [
            'transactions' => 'required|array',
            // 'transactions.*.hash' => 'required|string|max:64', // Dihapus
            'transactions.*.date' => 'required|string', // Format: dd/mm/yyyy
            'transactions.*.type' => 'required|string',
            'transactions.*.amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Data tidak valid.', 'errors' => $validator->errors()], 422);
        }

        $user = Auth::user();
        $validated = $validator->validated();
        
        $transactionsToInsert = [];
        $datesToProcess = []; // Array untuk menampung tanggal unik dari data yang masuk
        $now = now();

        // 2. Memproses dan Mengumpulkan Data
        // Loop ini mengumpulkan data yang akan disisipkan dan tanggal unik yang akan diproses.
        foreach ($validated['transactions'] as $tx) {
            try {
                $transactionDate = Carbon::createFromFormat('d/m/Y', $tx['date']);

                // Kumpulkan tanggal dalam format Y-m-d untuk query penghapusan
                $datesToProcess[] = $transactionDate->toDateString();

                // Siapkan data untuk disisipkan (tanpa hash)
                $transactionsToInsert[] = [
                    'user_id' => $user->id,
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

        if (empty($transactionsToInsert)) {
            return response()->json(['message' => 'Tidak ada data valid untuk diproses.', 'inserted' => 0], 200);
        }
        
        // Dapatkan tanggal unik untuk menghindari proses yang berlebihan
        $uniqueDates = array_unique($datesToProcess);

        // 3. Strategi "Delete-then-Insert" dalam satu Transaksi Atomik
        try {
            DB::transaction(function () use ($user, $uniqueDates, $transactionsToInsert) {
                // HAPUS semua transaksi milik user ini PADA TANGGAL yang dikirimkan.
                AdTransaction::where('user_id', $user->id)
                    ->whereIn('transaction_date', $uniqueDates)
                    ->delete();
                
                // SISIPKAN semua data baru yang sudah bersih.
                // Menggunakan DB::table()->insert() lebih performan untuk bulk insert.
                DB::table('ad_transactions')->insert($transactionsToInsert);
            });
        } catch (\Throwable $e) {
            Log::error('Gagal sinkronisasi transaksi iklan untuk user ' . $user->id, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['message' => 'Kesalahan database saat sinkronisasi: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'message' => 'Data transaksi iklan berhasil disinkronkan.',
            'inserted' => count($transactionsToInsert),
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