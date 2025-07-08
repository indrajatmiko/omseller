<?php
use function Laravel\Folio\{middleware, name};
use App\Models\StockTake;
use Livewire\Volt\Component;
use Livewire\WithPagination;

middleware('auth');
name('inventory.stock-takes.index');

new class extends Component {
    use WithPagination;

    public function startNewStockTake()
    {
        $newStockTake = StockTake::create([
            'user_id' => auth()->id(),
            'check_date' => now(),
            'status' => 'in_progress',
        ]);

        // PENTING: Meneruskan parameter sebagai array asosiatif untuk menghindari bug Folio/Volt.
        // Key 'stockTake' harus cocok dengan nama file dinamis [stockTake].blade.php
        return redirect()->route('inventory.stock-takes.show', ['stockTake' => $newStockTake->id]);
    }

    public function with(): array
    {
        return [
            'stockTakes' => StockTake::where('user_id', auth()->id())
                ->latest('check_date')
                ->paginate(10)
        ];
    }
}; ?>

<x-layouts.app>
    @volt('inventory-stock-takes-index')
        <div>
            <x-app.container>
                <div class="md:flex md:items-center md:justify-between">
                    <div class="min-w-0 flex-1">
                        <x-app.heading 
                            title="Riwayat Stock Opname"
                            description="Lihat semua sesi pengecekan stok yang pernah dilakukan atau mulai sesi baru."
                            :border="false"
                        />
                    </div>
                    <div class="mt-4 flex md:mt-0 md:ml-4">
                        <button wire:click="startNewStockTake" class="w-full md:w-auto flex-shrink-0 rounded-lg bg-black dark:bg-white px-4 py-2 text-sm font-semibold text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors">
                            Mulai Stock Opname Baru
                        </button>
                    </div>
                </div>

                <hr class="my-6 dark:border-gray-700">

                <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Tanggal</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Catatan</th>
                                <th scope="col" class="relative px-6 py-3"><span class="sr-only">Lihat</span></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($stockTakes as $stockTake)
                                <tr wire:key="st-{{ $stockTake->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        {{ $stockTake->check_date->translatedFormat('d F Y, H:i') }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span @class([
                                            'px-2 inline-flex text-xs leading-5 font-semibold rounded-full',
                                            'bg-green-100 text-green-800' => $stockTake->status === 'completed',
                                            'bg-yellow-100 text-yellow-800' => $stockTake->status === 'in_progress',
                                        ])>
                                            {{ Str::ucfirst($stockTake->status) }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        {{ Str::limit($stockTake->notes, 50) }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        {{-- PENTING: Sama seperti redirect, gunakan array asosiatif --}}
                                        <a href="{{ route('inventory.stock-takes.show', ['stockTake' => $stockTake->id]) }}" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200">
                                            Lihat / Lanjutkan
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-6 py-12 text-center text-gray-500">Belum ada riwayat stock opname.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                
                @if ($stockTakes->hasPages())
                    <div class="mt-6">{{ $stockTakes->links() }}</div>
                @endif
            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>