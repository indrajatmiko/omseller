<?php
use function Laravel\Folio\{middleware, name};
use App\Models\PurchaseOrder;
use Livewire\Volt\Component;
use Livewire\WithPagination;

middleware('auth');
name('purchases.index');

new class extends Component {
    use WithPagination;

    public function with(): array
    {
        $purchaseOrders = PurchaseOrder::where('user_id', auth()->id())
            ->latest()
            ->paginate(15);
        
        return [
            'purchaseOrders' => $purchaseOrders,
        ];
    }
};
?>

<x-layouts.app>
    @volt('purchases-index')
        <div>
            <x-app.container>
                <div class="flex justify-between items-center">
                    <x-app.heading 
                        title="Daftar Pembelian (Purchase Orders)"
                        description="Kelola semua pesanan pembelian ke pemasok."
                        :border="false" />
                    <a href="{{ route('purchases.create') }}" wire:navigate class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">
                        Buat PO Baru
                    </a>
                </div>
                <hr class="my-6 dark:border-gray-700">

                <div class="space-y-4">
                    @forelse($purchaseOrders as $po)
                        {{-- PERUBAHAN: Link sekarang akan menghasilkan URL /purchases/{id} dengan benar --}}
                        <a href="{{ route('purchases.show', ['purchaseOrder' => $po]) }}" wire:navigate class="block bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-4 hover:border-indigo-500 transition-colors">
                            <div class="flex flex-col sm:flex-row justify-between gap-4">
                                <div>
                                    <p class="font-bold text-indigo-600 dark:text-indigo-400">{{ $po->po_number }}</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">Pemasok: {{ $po->supplier ?? 'N/A' }}</p>
                                    <p class="text-xs text-gray-400">Dibuat: {{ $po->created_at?->format('d M Y') ?? 'N/A' }}</p>
                                </div>
                                <div class="text-left sm:text-right">
                                    <p class="text-lg font-semibold text-gray-800 dark:text-white">Rp {{ number_format($po->total_amount, 0, ',', '.') }}</p>
                                    <x-filament::badge :color="match($po->status) {
                                        'draft' => 'gray',
                                        'ordered' => 'warning',
                                        'received' => 'success',
                                        'cancelled' => 'danger',
                                        default => 'gray'
                                    }" class="mt-1">
                                        {{ str_replace('_', ' ', $po->status) }}
                                    </x-filament::badge>
                                </div>
                            </div>
                        </a>
                    @empty
                        <x-empty-state description="Belum ada Purchase Order yang dibuat." />
                    @endforelse
                </div>
                
                @if($purchaseOrders->hasPages())
                    <div class="mt-6">
                        {{ $purchaseOrders->links() }}
                    </div>
                @endif
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>