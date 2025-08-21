<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BuyerProfile;
use App\Models\LocationData;
use App\Services\AddressParsingService;
use Illuminate\Support\Facades\Log;

class ProcessCustomerLocations extends Command
{
    protected $signature = 'location:process';
    protected $description = 'Mengekstrak dan menyimpan data lokasi dari alamat pelanggan yang belum diproses';

    public function handle(AddressParsingService $addressParser): int
    {
        $this->info('Memulai pemrosesan data lokasi pelanggan...');
        
        // Query untuk profil yang belum diproses
        $query = BuyerProfile::whereNull('location_processed_at');
        
        $totalProfiles = $query->count();
        if ($totalProfiles === 0) {
            $this->info('Tidak ada pelanggan baru untuk diproses. Selesai.');
            return self::SUCCESS;
        }
        
        $progressBar = $this->output->createProgressBar($totalProfiles);
        $processedCount = 0;
        
        // Gunakan chunkById untuk efisiensi memori
        $query->chunkById(200, function ($profiles) use ($addressParser, $progressBar, &$processedCount) {
            foreach ($profiles as $profile) {
                // Ambil alamat dari order terakhir pelanggan
                $latestOrder = $profile->orders()->latest()->first();

                if ($latestOrder && !empty($latestOrder->address_full)) {
                    $parsedData = $addressParser->parse($latestOrder->address_full);
                    
                    if ($parsedData) {
                        // Simpan atau perbarui data lokasi
                        LocationData::updateOrCreate(
                            ['buyer_profile_id' => $profile->id],
                            $parsedData
                        );
                        $processedCount++;
                    } else {
                        Log::warning("Gagal memproses alamat untuk BuyerProfile ID: {$profile->id}. Alamat: {$latestOrder->address_full}");
                    }
                }
                
                // Tandai profil sebagai sudah diproses, bahkan jika gagal, agar tidak diulang terus
                $profile->location_processed_at = now();
                $profile->save();

                $progressBar->advance();
            }
        });
        
        $progressBar->finish();
        $this->info("\nSelesai. {$processedCount} dari {$totalProfiles} lokasi pelanggan berhasil diproses.");

        return self::SUCCESS;
    }
}