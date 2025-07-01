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
        $validator = Validator::make($request->all(), [
            'transactions' => 'required|array',
            'transactions.*.hash' => 'required|string|max:64',
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
        $now = now();

        foreach ($validated['transactions'] as $tx) {
            try {
                // Konversi tanggal dan siapkan data untuk disisipkan
                $transactionsToInsert[] = [
                    'user_id' => $user->id,
                    'transaction_hash' => $tx['hash'],
                    'transaction_date' => Carbon::createFromFormat('d/m/Y', $tx['date'])->toDateString(),
                    'transaction_type' => $tx['type'],
                    'amount' => $tx['amount'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } catch (\Exception $e) {
                // Abaikan format tanggal yang salah, log jika perlu
                Log::warning('Invalid date format for ad transaction', ['data' => $tx]);
                continue;
            }
        }

        if (empty($transactionsToInsert)) {
            return response()->json(['message' => 'Tidak ada data valid untuk diproses.', 'inserted' => 0], 200);
        }

        // Gunakan "upsert" untuk efisiensi maksimal.
        // Ini akan menyisipkan record baru jika `transaction_hash` tidak ada,
        // atau mengabaikannya jika sudah ada.
        $insertedCount = AdTransaction::upsert(
            $transactionsToInsert,
            ['transaction_hash'], // Kolom unik untuk dicek
            ['transaction_date', 'transaction_type', 'amount', 'updated_at'] // Kolom yang diupdate jika sudah ada (sebenarnya kita tidak mengharapkan update)
        );

        return response()->json([
            'message' => 'Data transaksi iklan berhasil disinkronkan.',
            'inserted' => $insertedCount,
        ], 200);
    }

    /**
     * (BARU) Mengambil tanggal transaksi terakhir yang tercatat untuk user.
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLatestTransactionDate()
    {
        $user = Auth::user();

        $latestDate = AdTransaction::where('user_id', $user->id)
            ->max('transaction_date'); // Mengambil nilai maksimum (terbaru) dari kolom transaction_date

        return response()->json([
            'latest_date' => $latestDate // Akan mengembalikan 'YYYY-MM-DD' atau null
        ]);
    }
}