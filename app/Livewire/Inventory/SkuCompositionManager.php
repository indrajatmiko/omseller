<?php

namespace App\Livewire\Inventory;

use App\Models\ProductVariant;
use App\Models\SkuComposition;
use Livewire\Component;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class SkuCompositionManager extends Component
{
    public bool $showModal = false;
    public ?string $bundleSku = null;

    public array $components = [];
    public string $searchTerm = '';
    public array $searchResults = [];
    public ?string $selectedComponentSku = null;
    public int $quantity = 1;

    protected $listeners = ['manage-composition' => 'manageComposition'];

    public function manageComposition(string $sku)
    {
        $this->resetForm();
        $this->bundleSku = $sku;
        $this->loadComponents();
        $this->showModal = true;
    }

    public function loadComponents()
    {
        if (!$this->bundleSku) return;

        $this->components = SkuComposition::where('bundle_sku', $this->bundleSku)
            ->where('user_id', auth()->id()) // Menjaga keamanan data
            ->get(['component_sku', 'quantity'])
            ->toArray();
    }

    // ========================================================================
    // FUNGSI INI TELAH DITULIS ULANG SEPENUHNYA UNTUK HASIL YANG UNIK
    // ========================================================================
    public function updatedSearchTerm()
    {
        if (strlen($this->searchTerm) < 2) {
            $this->searchResults = [];
            return;
        }

        // Langkah 1: Ambil semua kandidat varian dari database, termasuk duplikat.
        // Kita tidak lagi menggunakan ->select() atau ->distinct() di sini.
        $variants = ProductVariant::query()
            ->where('sku_type', 'mandiri') // Hanya cari SKU mandiri
            ->where('variant_sku', '!=', $this->bundleSku) // Jangan sertakan SKU bundle itu sendiri
            ->whereNotNull('variant_sku')
            ->where(function ($query) {
                $query->where('variant_sku', 'like', '%' . $this->searchTerm . '%')
                      ->orWhere('variant_name', 'like', '%' . $this->searchTerm . '%');
            })
            ->limit(25) // Ambil hingga 25 kandidat untuk difilter
            ->get();

        // Langkah 2: Gunakan metode koleksi ->unique() untuk menyaring duplikat berdasarkan 'variant_sku'.
        // Ini adalah kunci perbaikannya. Ia akan menyimpan hanya entri pertama untuk setiap SKU.
        $uniqueVariants = $variants->unique('variant_sku');

        // Langkah 3: Format hasil yang sudah unik untuk ditampilkan di dropdown, dan batasi hingga 5 hasil akhir.
        $this->searchResults = $uniqueVariants->map(function ($variant) {
            return [
                'variant_sku' => $variant->variant_sku,
                'variant_name' => $variant->variant_name, // Kita ambil nama varian pertama yang ditemukan
            ];
        })
        ->values() // Re-index array keys dari 0
        ->take(5)    // Batasi hasil akhir yang ditampilkan
        ->toArray();
    }
    // ========================================================================
    // AKHIR DARI FUNGSI YANG DITULIS ULANG
    // ========================================================================

    public function selectComponent(string $sku, string $name)
    {
        $this->selectedComponentSku = $sku;
        $this->searchTerm = "{$sku} - {$name}";
        $this->searchResults = [];
    }

    public function addComponent()
    {
        $this->validate([
            'selectedComponentSku' => 'required',
            'quantity' => 'required|integer|min:1',
        ], [
            'selectedComponentSku.required' => 'Anda harus memilih komponen dari hasil pencarian.',
        ]);

        foreach ($this->components as $component) {
            if ($component['component_sku'] === $this->selectedComponentSku) {
                $this->addError('selectedComponentSku', 'Komponen ini sudah ada dalam daftar.');
                return;
            }
        }
        
        $this->components[] = [
            'component_sku' => $this->selectedComponentSku,
            'quantity' => $this->quantity,
        ];

        $this->reset('selectedComponentSku', 'searchTerm', 'quantity');
        $this->quantity = 1;
    }

    public function removeComponent(string $sku)
    {
        $this->components = array_filter($this->components, function ($component) use ($sku) {
            return $component['component_sku'] !== $sku;
        });
    }

    public function saveComposition()
    {
        if (!$this->bundleSku) return;
        
        $userId = auth()->id();
        
        DB::transaction(function () use ($userId) {
            SkuComposition::where('bundle_sku', $this->bundleSku)
                ->where('user_id', $userId)
                ->delete();
            
            if (!empty($this->components)) {
                $newCompositions = [];
                
                foreach ($this->components as $component) {
                    $newCompositions[] = [
                        'bundle_sku' => $this->bundleSku,
                        'component_sku' => $component['component_sku'],
                        'quantity' => $component['quantity'],
                        'user_id' => $userId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                
                SkuComposition::insert($newCompositions);
            }
        });
        
        Notification::make()
            ->title('Komposisi Disimpan!')
            ->success()
            ->body("Komposisi untuk SKU '{$this->bundleSku}' telah diperbarui.")
            ->send();
            
        $this->showModal = false;
        
        $this->dispatch('compositionSaved', sku: $this->bundleSku);
    }

    public function resetForm()
    {
        $this->reset('bundleSku', 'components', 'searchTerm', 'searchResults', 'selectedComponentSku');
        $this->quantity = 1;
    }

    public function render()
    {
        return view('livewire.inventory.sku-composition-manager');
    }
}