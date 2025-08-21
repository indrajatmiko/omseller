<?php

namespace App\Services;

class AddressParsingService
{
    /**
     * Memisahkan string alamat menjadi komponen geografis.
     * Mengambil 5 segmen terakhir yang dipisahkan oleh koma.
     *
     * @param string|null $fullAddress
     * @return array|null
     */
    public function parse(?string $fullAddress): ?array
    {
        if (empty($fullAddress)) {
            return null;
        }

        // 1. Pecah string berdasarkan koma
        $parts = explode(',', $fullAddress);

        // 2. Periksa apakah jumlah bagian cukup (minimal 5)
        if (count($parts) < 5) {
            // Jika tidak cukup, format alamat tidak sesuai, kembalikan null
            return null;
        }

        // 3. Ambil 5 elemen terakhir dari array
        $locationParts = array_slice($parts, -5);

        // 4. Bersihkan setiap bagian dari spasi berlebih
        $cleanedParts = array_map('trim', $locationParts);

        // 5. Kembalikan dalam bentuk array asosiatif yang rapi
        return [
            'city'         => $cleanedParts[0],
            'district'     => $cleanedParts[1],
            'province'     => $cleanedParts[2],
            'country_code' => $cleanedParts[3],
            'zip_code'     => $cleanedParts[4],
        ];
    }
}