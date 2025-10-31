<?php

namespace Database\Seeders;

use App\Models\ServiceFee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ServiceFeeSeeder extends Seeder
{
    public function run(): void
    {
        // // Kosongkan tabel terlebih dahulu untuk menghindari duplikasi
        // DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        // DB::table('service_fees')->truncate();
        // DB::table('service_fee_details')->truncate();
        // DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // // 1. Tambahkan Biaya Program Layanan (tidak punya detail)
        // ServiceFee::create([
        // 'platform' => 'shopee', 'seller_type' => 'all', 'fee_type' => 'program_fee', 'name' => 'Gratis Ongkir Xtra',
        // 'description' => 'Program Gratis Ongkir Xtra', 'value' => 4.00, 'value_type' => 'percentage', 'max_cap' => 10000, 'is_active' => true
        // ]);
        // ServiceFee::create([
        // 'platform' => 'shopee', 'seller_type' => 'all', 'fee_type' => 'program_fee', 'name' => 'Promo Xtra',
        // 'description' => 'Program Cashback & Promo Xtra', 'value' => 1.40, 'value_type' => 'percentage', 'max_cap' => 10000, 'is_active' => true
        // ]);

        // // 2. Proses file JSON untuk Penjual Non-Star & Star
        // $this->processJsonFile('kategori_produk.json', 'non_star');

        // // 3. Proses file JSON untuk Penjual Mall
        // $this->processJsonFile('kategori_produk_mall.json', 'mall');

        // --- DATA TIKTOK (BARU) ---
        $this->seedTiktokProgramFees();
        $this->seedTiktokCategories();
    }

    private function processJsonFile(string $fileName, string $sellerType): void
    {
        // Definisikan persentase biaya berdasarkan tipe penjual dan kategori
        $feesMapping = [
        'non_star' => [
        'A' => 8.0, 'B' => 7.5, 'C' => 5.75, 'D' => 4.25, 'E' => 2.5
        ],
        'mall' => [
        'A' => 6.5, 'B' => 5.5, 'C' => 4.5, 'D' => 3.5, 'E' => 2.5, 'F' => 1.8, 'G' => 1.2
        ],
        ];

        $path = database_path('data/' . $fileName);
        if (!File::exists($path)) {
        $this->command->error("File not found: " . $path);
        return;
        }

        $categories = json_decode(File::get($path), true);

        foreach ($categories as $group) {
        $mainCategoryLetter = $group['main_category'];
        $feeValue = $feesMapping[$sellerType][$mainCategoryLetter] ?? 0;

        // Buat entri induk di tabel service_fees
        $serviceFee = ServiceFee::create([
        'platform' => 'shopee',
        'seller_type' => $sellerType,
        'fee_type' => 'admin_fee',
        'name' => 'Kategori ' . $mainCategoryLetter,
        'description' => null, // Deskripsi akan ada di tabel detail
        'value' => $feeValue,
        'value_type' => 'percentage',
        'is_active' => true,
        ]);

        // Buat entri anak untuk setiap subkategori
        foreach ($group['subcategories'] as $sub) {
        $serviceFee->details()->create([
        'subcategory_name' => $sub['name'],
        'description' => $sub['description'],
        ]);
        }
        }
    }

    /**
     * BARU: Menambahkan biaya program khusus untuk TikTok.
     */
    private function seedTiktokProgramFees(): void
    {
        ServiceFee::create([
            'platform' => 'tiktok', 'seller_type' => 'all', 'fee_type' => 'program_fee', 'name' => 'Produk Pre-order',
            'description' => 'Biaya tambahan untuk produk Pre-order', 'value' => 3.00, 'value_type' => 'percentage', 'max_cap' => 10000, 'is_active' => true
        ]);
        ServiceFee::create([
            'platform' => 'tiktok', 'seller_type' => 'all', 'fee_type' => 'program_fee', 'name' => 'Layanan Mall',
            'description' => 'Biaya layanan tambahan untuk penjual di TikTok Mall', 'value' => 1.80, 'value_type' => 'percentage', 'max_cap' => 50000, 'is_active' => true
        ]);
    }

/**
     * BARU: Menambahkan semua kategori dan subkategori TikTok ke database.
     */
    private function seedTiktokCategories(): void
    {
        // Data ini diekstrak dan distrukturkan dari file kalkulator-margin-tiktok.blade.php
        $tiktokCategories = [
            'non_star' => [
                'Elektronik' => [
                    ['value' => 4.25, 'sub' => 'Komponen Desktop & Laptop', 'desc' => 'Sound Cards, Power Supply Units, Monitors, Fans & Heatsinks, UPS & Stabilizers, RAM, Processors, PC Cases, Optical Drives, Motherboards, Graphics Cards, TV Tuner & Video Capture Cards'],
                    ['value' => 5.75, 'sub' => 'Aksesoris Komputer', 'desc' => 'Laptop Stands & Trays, USB Hubs & Card Readers, Webcams'],
                    ['value' => 4.25, 'sub' => 'Penyimpanan Data', 'desc' => 'Flash Drives & OTG Cables, Hard Disk Enclosures & Docking Stations, SSD, Network Attached Storage (NAS), Micro SD Cards, Hard Drives, Compact Discs'],
                    ['value' => 5.75, 'sub' => 'Software', 'desc' => 'Software'],
                    ['value' => 5.75, 'sub' => 'Komponen Jaringan', 'desc' => 'Modems & Wireless Routers, Wireless Adapters & Network Cards, Network Cables & Connectors, Network Switches & PoE, Powerline Adapters, Repeaters, Print Servers, KVM Switches'],
                    ['value' => 4.25, 'sub' => 'Peralatan Kantor', 'desc' => 'Label Printers, Money Counters, Laminators, Office Equipment Parts, Advertisement Printing Equipment, 3D Printing Supplies'],
                    ['value' => 10.00, 'sub' => 'Alat Tulis & Kebutuhan Kantor', 'desc' => 'Art Supplies, Accounting Supplies, Calendars & Accessories, Desk Organizers & Accessories, Envelopes & Postal Supplies, Gifts & Wrapping, Identification Badges & Supplies, Labels, Index Dividers & Stamps, Notebooks & Paper, Office Cutting Supplies, Office Filing Products, Office Measuring Supplies, Safes, School & Educational Supplies, Tape, Adhesives & Fasteners, Writing & Correction Tools'],
                    ['value' => 4.25, 'sub' => 'Printer & Scanner', 'desc' => 'Ink & Toner Cartridges, Paper Shredders, Printers & Scanners, Barcode Scanners, Access Control & Attendance Devices, Typewriters, Smart Retail Equipment'],
                    ['value' => 4.25, 'sub' => 'Peralatan Dapur', 'desc' => 'Juicers & Blenders, Electric Hot Pots, Fryers, Kitchen Appliance Parts, Rice & Pressure Cookers, Countertop Ovens, Mixers, Coffee Machines & Accessories, Toasters, Food Processors, Water Coolers & Dispensers, Vacuum Sealers, Induction Hobs, Electric Kettles, Electric Grills, Electric Steamers, Specialty Kitchen Appliances, Ice Makers, Bread Makers, Microwaves, Electric & Gas Stoves, Water Filters, Soda Makers, Food Waste Disposers'],
                    ['value' => 5.75, 'sub' => 'Peralatan Rumah Tangga', 'desc' => 'Vacuum Cleaners & Sweeping Robots, Fans, Irons, Humidifiers'],
                    ['value' => 7.50, 'sub' => 'Pembasmi Hama Elektronik', 'desc' => 'Electronic Mosquito Killers'],
                    ['value' => 4.25, 'sub' => 'Peralatan Rumah Tangga Lainnya', 'desc' => 'Hand Dryers, Home Sterilizers'],
                ],
                'Fashion' => [
                    ['value' => 10.00, 'sub' => 'Aksesoris Pakaian', 'desc' => 'Belts, Hats, Gloves, Collar Clips & Brooches, Face Covering Masks & Accessories, Scarves & Shawls, Ties & Bow ties, Fashion Accessory Sets'],
                    ['value' => 10.00, 'sub' => 'Rambut Palsu & Ekstensi', 'desc' => 'Hair Extensions & Wigs'],
                    ['value' => 10.00, 'sub' => 'Tas & Koper', 'desc' => 'Women\'s Bags, Men\'s Bags, Luggage & Travel Bags, Functional Bags, Bag Accessories'],
                    ['value' => 10.00, 'sub' => 'Pakaian & Pakaian Dalam Pria', 'desc' => 'Men\'s Tops, Men\'s Bottoms, Men\'s Special Clothing, Men\'s Underwear, Men\'s Sleepwear & Loungewear, Men\'s Suits & Overalls'],
                    ['value' => 10.00, 'sub' => 'Fashion Muslim', 'desc' => 'Fashion Hijabs, Women\'s Islamic Clothing Shirts & Blouses, Clothing Sets, Dresses, Gamis, Abayas, Tunics, Skirts, Outerwear, Culottes & Palazzo Pants, Kaftans, Jumpsuits, Family Clothing Sets, Robes, Couples\' Clothing Sets, Leggings, Turtlenecks & Inners, Kids\' Islamic Clothing, Islamic Accessories, Prayer Attire & Equipment'],
                    ['value' => 10.00, 'sub' => 'Aksesoris Fashion Pre-Owned', 'desc' => 'Pre-Owned Fashion Accessories'],
                    ['value' => 10.00, 'sub' => 'Peralatan Olahraga Bola & Outdoor', 'desc' => 'Ball Sports Equipment, Water Sports Equipment, Winter Sports Equipment, Fitness Equipment, Camping & Hiking Equipment'],
                    ['value' => 10.00, 'sub' => 'Peralatan Olahraga Rekreasi', 'desc' => 'Aerobics, Airsoft, Archery, Ballet & Dance, Boxing & Martial Arts, Cheerleading, Climbing, Darts, Disc Sports, E-sports, Fencing, Fishing, Gymnastics, Horse Riding, Hunting, Indoor Recreation, Judo, Karate, Nunchucks, Paintball, Racing, Roller Skating, Running, Skydiving, Skateboarding, Taekwondo, Track & Field, Triathlon, Wrestling, Yoga & Pilates'],
                    ['value' => 10.00, 'sub' => 'Aksesoris Olahraga & Outdoor', 'desc' => 'Sports Bags, Sports Water Bottles, Sports Eyewear, Stopwatches & Timers, Sports Gloves, Sports & Outdoor Hats, Pedometers, Sports Socks, Sports Sleeves & Support, Protective Gear, Sports Tapes, Face Covers & Mask, Life Jackets & Vests, Sports Wristbands, Swimming Caps, Sports Headbands, Shoe Bags, Trophies, Medals & Awards, Hand Chalk, Coach & Referee Gear'],
                    ['value' => 10.00, 'sub' => 'Pakaian & Sepatu Olahraga', 'desc' => 'Sports & Outdoor Clothing, Sports Footwear'],
                    ['value' => 10.00, 'sub' => 'Pakaian Renang & Selancar', 'desc' => 'Swimwear, Surfwear & Wetsuits'],
                    ['value' => 10.00, 'sub' => 'Pakaian & Pakaian Dalam Wanita', 'desc' => 'Women\'s Tops, Women\'s Bottoms, Women\'s Dresses, Women\'s Special Clothing'],
                ],
                'FMCG' => [
                    ['value' => 10.00, 'sub' => 'Mainan Bayi', 'desc' => 'Baby Toys, Baby Sound Toys, Baby Pretend Play'],
                    ['value' => 10.00, 'sub' => 'Aksesoris Fashion Bayi', 'desc' => 'Baby Hats & Caps, Bibs & Burp Cloths, Baby Bags, Gift Sets, Baby Earmuffs, Baby Costume Jewelry, Baby Hair Accessories, Baby Gloves, Sunglasses, Baby Scarves, Baby Face Masks'],
                    ['value' => 10.00, 'sub' => 'Perawatan Mandi & Tubuh', 'desc' => 'Body Creams & Lotions, Body Care Kits, Body Wash & Soap, Body Scrubs & Peels, Hair Removal Cream, Wax & Shave, Sunscreen & Sun Care, Deodorants & Antiperspirants, Body & Massage Oil, Body Masks, Breast Care, Body Shaping Cream, Talcum Powder'],
                    ['value' => 10.00, 'sub' => 'Perawatan Mata & Telinga', 'desc' => 'Contact Lens, Lens Solutions & Eyedrops, Earwax Removal Products, Contact Lens Conditioning Kits, Colored Contact Lens, Sleep Masks, Reading Glasses, Ear Plugs'],
                    ['value' => 10.00, 'sub' => 'Perawatan Tangan, Kaki & Kuku', 'desc' => 'Hand, Foot & Nail Care'],
                    ['value' => 10.00, 'sub' => 'Makeup', 'desc' => 'Makeup'],
                    ['value' => 10.00, 'sub' => 'Perawatan Pria', 'desc' => 'Men\'s Care'],
                    ['value' => 10.00, 'sub' => 'Perawatan Hidung, Mulut & Kewanitaan', 'desc' => 'Nasal & Oral Care, Feminine Care'],
                    ['value' => 10.00, 'sub' => 'Parfum', 'desc' => 'Unisex Perfume, Perfume Sets, Women\'s Perfume, Men\'s Perfume, Perfume'],
                    ['value' => 10.00, 'sub' => 'Alat Perawatan Diri', 'desc' => 'Personal Care Appliances'],
                    ['value' => 7.50, 'sub' => 'Suplemen Makanan', 'desc' => 'Beauty Supplement, Wellness Supplements, Fitness Supplements, Weight Management'],
                ],
                'Lifestyle' => [
                    ['value' => 10.00, 'sub' => 'Suku Cadang Mobil', 'desc' => 'Auto Replacement Parts, Wheels, Rims & Accessories'],
                    ['value' => 10.00, 'sub' => 'Suku Cadang Motor', 'desc' => 'Motorcycle Parts, Lighting, Mirrors & Accessories, Shocks, Struts & Suspension, Sparkplug'],
                    ['value' => 10.00, 'sub' => 'Rambut Palsu & Ekstensi (Fashion)', 'desc' => 'Fashion Accessories Hair Extensions & Wigs'],
                    ['value' => 10.00, 'sub' => 'Tenaga Surya & Angin', 'desc' => 'Home Improvement Solar & Wind Power'],
                    ['value' => 10.00, 'sub' => 'Penyimpanan Rumah', 'desc' => 'Home Supplies Home Organizers'],
                    ['value' => 10.00, 'sub' => 'Peralatan Dapur', 'desc' => 'Kitchenware, Barbecue Utensils, Cooking Utensils'],
                ],
                'Lainnya' => [
                    ['value' => 7.50, 'sub' => 'Produk Virtual', 'desc' => 'Physical Voucher'],
                    ['value' => 10.00, 'sub' => 'Aksesoris Pernikahan', 'desc' => 'Wedding Accessories'],
                ]
            ],
            'mall' => [
                'Elektronik' => [
                    ['value' => 2.50, 'sub' => 'Komponen Desktop & Laptop', 'desc' => 'Sound Cards, Power Supply Units, Monitors, Fans & Heatsinks, UPS & Stabilizers, RAM, Processors, PC Cases, Optical Drives, Motherboards, Graphics Cards, TV Tuner & Video Capture Cards'],
                    ['value' => 4.00, 'sub' => 'Peralatan Dapur', 'desc' => 'Juicers & Blenders, Electric Hot Pots, Fryers, Kitchen Appliance Parts, Rice & Pressure Cookers, Countertop Ovens, Mixers, Coffee Machines & Accessories, Toasters, Food Processors, Water Coolers & Dispensers, Vacuum Sealers, Induction Hobs, Electric Kettles, Electric Grills, Electric Steamers, Specialty Kitchen Appliances, Ice Makers, Bread Makers, Microwaves, Electric & Gas Stoves, Water Filters, Soda Makers, Food Waste Disposers'],
                ],
                'Fashion' => [
                    ['value' => 8.50, 'sub' => 'Fashion Muslim', 'desc' => 'Fashion Hijabs, Women\'s Islamic Clothing Shirts & Blouses, Clothing Sets, Dresses, Gamis, Abayas, Tunics, Skirts, Outerwear, Culottes & Palazzo Pants, Kaftans, Jumpsuits, Family Clothing Sets, Robes, Couples\' Clothing Sets, Leggings, Turtlenecks & Inners, Kids\' Islamic Clothing, Islamic Accessories, Prayer Attire & Equipment'],
                    ['value' => 8.50, 'sub' => 'Pakaian & Sepatu Olahraga', 'desc' => 'Sports & Outdoor Clothing, Sports Footwear'],
                    ['value' => 8.50, 'sub' => 'Pakaian Renang & Selancar', 'desc' => 'Swimwear, Surfwear & Wetsuits'],
                ],
                'FMCG' => [
                    ['value' => 4.00, 'sub' => 'Suplemen Makanan', 'desc' => 'Beauty Supplement, Wellness Supplements, Fitness Supplements, Weight Management'],
                    ['value' => 8.50, 'sub' => 'Makeup', 'desc' => 'Makeup'],
                    ['value' => 8.50, 'sub' => 'Perawatan Kulit (Skincare)', 'desc' => 'Skin Care Kits, Serums & Essences, Moisturizers & Mists, Facial Sunscreen & Sun Care, Facial Cleansers, Face Masks, Acne Treatments, Toners, Lip Treatments, Face Scrubs & Peels, Eye Treatments'],
                ],
                'Lifestyle' => [
                    ['value' => 8.50, 'sub' => 'Suku Cadang Mobil', 'desc' => 'Auto Replacement Parts, Windshield Wipers & Washers, Wheels, Rims & Accessories, Shocks, Struts & Suspension'],
                    ['value' => 8.50, 'sub' => 'Suku Cadang Motor', 'desc' => 'Motorcycle Parts, Lighting, Mirrors & Accessories, Shocks, Struts & Suspension, Sparkplug'],
                    ['value' => 8.50, 'sub' => 'Rambut Palsu & Ekstensi (Fashion)', 'desc' => 'Fashion Accessories Hair Extensions & Wigs'],
                    ['value' => 8.50, 'sub' => 'Tenaga Surya & Angin', 'desc' => 'Home Improvement Solar & Wind Power'],
                    ['value' => 8.50, 'sub' => 'Penyimpanan Rumah', 'desc' => 'Home Supplies Home Organizers'],
                ],
                'Lainnya' => [
                    ['value' => 1.00, 'sub' => 'Produk Virtual', 'desc' => 'Physical Voucher'],
                    ['value' => 8.50, 'sub' => 'Aksesoris Pernikahan', 'desc' => 'Wedding Accessories'],
                ]
            ]
        ];

        foreach ($tiktokCategories as $sellerType => $groups) {
            foreach ($groups as $groupName => $details) {
                // Tentukan nilai komisi untuk grup ini.
                // Kita asumsikan semua item dalam satu grup memiliki komisi yang sama.
                $feeValue = $details[0]['value']; 
                $description = null;

                // Handle kasus spesial untuk komisi 10% yang mendapat diskon menjadi 8%
                if ($feeValue == 10.00) {
                    $feeValue = 8.00; // Simpan nilai efektif yang sudah didiskon
                    $description = 'Tarif Komisi Marketplace 10% mendapatkan diskon 20%, sehingga tarif efektif menjadi 8%.';
                }

                // Buat entri induk di tabel service_fees
                $serviceFee = ServiceFee::create([
                    'platform' => 'tiktok',
                    'seller_type' => $sellerType,
                    'fee_type' => 'admin_fee',
                    'name' => $groupName,
                    'description' => $description,
                    'value' => $feeValue,
                    'value_type' => 'percentage',
                    'is_active' => true,
                ]);

                // Buat entri anak di service_fee_details untuk setiap subkategori
                foreach ($details as $detail) {
                    $serviceFee->details()->create([
                        'subcategory_name' => $detail['sub'],
                        'description' => $detail['desc'],
                    ]);
                }
            }
        }
    }

}