<?php
use function Laravel\Folio\{middleware, name};
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

middleware('auth');
name('purchases.create');

new class extends Component {
    public ?int $selectedCategoryId = null;
    public array $items = [];
    public ?string $supplier = null;
    public ?string $notes = null;

    public function updatedSelectedCategoryId($value): void
    {
        $this->items = [];
        if (empty($value)) return;

        $skus = ProductVariant::query()
            ->whereHas('product', function($q) use ($value) {
                $q->where('user_id', auth()->id())->where('product_category_id', $value);
            })
            ->where(fn($q) => $q->where('variant_sku', '!=', '')->whereNotNull('variant_sku'))
            ->select('id', 'variant_sku', 'variant_name', 'cost_price')
            ->distinct('variant_sku')
            ->orderBy('variant_sku')
            ->get();
        
        foreach ($skus as $sku) {
            $this->items[$sku->variant_sku] = [
                'product_variant_id' => $sku->id,
                'variant_name' => $sku->variant_name,
                'cost_price' => $sku->cost_price,
                'quantity' => '',
                'include' => false,
            ];
        }
    }

    public function totalAmount(): float
    {
        return collect($this->items)->filter(fn($item) => $item['include'] && is_numeric($item['quantity']))
            ->sum(fn($item) => $item['quantity'] * $item['cost_price']);
    }

    public function save(): void
    {
        $this->validate([
            'supplier' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'items.*.quantity' => 'nullable|integer|min:0',
            'items.*.cost_price' => 'nullable|numeric|min:0',
        ]);

        $itemsToSave = collect($this->items)->filter(fn($item) => $item['include'] && !empty($item['quantity']) && $item['quantity'] > 0);

        if ($itemsToSave->isEmpty()) {
            Notification::make()->title('Tidak Ada Item')->danger()->body('Pilih setidaknya satu item dan isi jumlahnya.')->send();
            return;
        }

        $purchaseOrder = DB::transaction(function () use ($itemsToSave) {
            $po = PurchaseOrder::create([
                'user_id' => auth()->id(),
                'po_number' => 'PO-' . now()->format('Ymd-His'),
                'supplier' => $this->supplier,
                'notes' => $this->notes,
                'status' => 'draft',
                'total_amount' => $this->totalAmount(),
            ]);

            foreach ($itemsToSave as $sku => $itemData) {
                $po->items()->create([
                    'product_variant_id' => $itemData['product_variant_id'],
                    'quantity' => $itemData['quantity'],
                    'cost_price' => $itemData['cost_price'],
                    'subtotal' => $itemData['quantity'] * $itemData['cost_price'],
                ]);
            }
            return $po;
        });

        Notification::make()->title('Purchase Order Dibuat')->success()->body("PO #{$purchaseOrder->po_number} berhasil dibuat.")->send();
        
        // PERUBAHAN: Redirect menggunakan nama parameter yang benar, dan mengirim seluruh objek.
        $this->redirectRoute('purchases.show', ['purchaseOrder' => $purchaseOrder]);
    }

    public function with(): array
    {
        return [
            'categories' => ProductCategory::where('user_id', auth()->id())->orderBy('name')->get(),
            'totalAmount' => $this->totalAmount(),
        ];
    }
};
?>

<x-layouts.app>
    @volt('purchases-create')
        <div>
            <x-app.container>
                <x-app.heading title="Buat Purchase Order Baru" description="Pilih kategori untuk memulai pesanan pembelian." />
                
                <form wire:submit="save" class="mt-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <x-input-label for="category" value="1. Pilih Kategori Produk" />
                            <x-select-input id="category" wire:model.live="selectedCategoryId" class="mt-1 block w-full">
                                <option value="">-- Pilih Kategori --</option>
                                @foreach($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </x-select-input>
                        </div>
                        <div>
                            <x-input-label for="supplier" value="Nama Pemasok (Opsional)" />
                            <x-text-input id="supplier" wire:model="supplier" class="mt-1 block w-full" />
                        </div>
                    </div>

                    @if($selectedCategoryId)
                        <div class="space-y-4">
                            <p class="font-semibold">2. Pilih Item dan Isi Jumlah Pembelian</p>

                            <div class="hidden md:flex items-center gap-4 px-3 text-xs text-gray-500 font-medium">
                                <div class="w-6"></div> 
                                <div class="flex-1">PRODUK</div> 
                                <div class="flex items-center gap-3">
                                    <div class="w-24 text-center">JUMLAH</div>
                                    <div class="w-32 text-center">HARGA BELI (RP)</div>
                                </div>
                            </div>
                            
                            <div class="space-y-2">
                                @foreach($items as $sku => $item)
                                    <div wire:key="item-{{ $sku }}" class="p-3 rounded-lg border flex flex-col md:flex-row items-start md:items-center gap-4 @if($item['include']) bg-indigo-50 dark:bg-indigo-900/20 border-indigo-300 dark:border-indigo-800 @else bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700 @endif">
                                        <input type="checkbox" wire:model.live="items.{{$sku}}.include" class="rounded mt-1 md:mt-0">
                                        
                                        <div class="flex-1">
                                            <p class="font-bold font-mono">{{ $sku }}</p>
                                            <p class="text-xs text-gray-500">{{ $item['variant_name'] }}</p>
                                        </div>

                                        <div class="flex items-center gap-3 w-full md:w-auto">
                                            <div class="flex-1 md:flex-initial">
                                                <label class="text-xs md:hidden">Jumlah</label>
                                                <x-text-input type="number" wire:model.live.debounce="items.{{$sku}}.quantity" class="w-full md:w-24 text-center" placeholder="0" />
                                            </div>
                                            <div class="flex-1 md:flex-initial">
                                                <label class="text-xs md:hidden">Harga Beli (Rp)</label>
                                                <x-text-input type="number" wire:model.live.debounce="items.{{$sku}}.cost_price" class="w-full md:w-32 text-center" placeholder="0" />
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <x-input-label for="notes" value="Catatan (Opsional)" />
                            <x-textarea-input id="notes" wire:model="notes" class="mt-1 block w-full" />
                        </div>
                        
                        <div class="text-right">
                            <p class="text-sm text-gray-500">Total Pembelian</p>
                            <p class="text-2xl font-bold">Rp {{ number_format($totalAmount, 0, ',', '.') }}</p>
                        </div>

                        <div class="pt-5 border-t dark:border-gray-700 flex justify-end">
                            <x-primary-button>Buat Purchase Order</x-primary-button>
                        </div>
                    @endif
                </form>
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>