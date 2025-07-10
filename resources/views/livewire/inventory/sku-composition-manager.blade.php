<div>
    @if ($showModal)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            {{-- Background overlay --}}
            <div x-data @click="$wire.set('showModal', false)" class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true"></div>

            {{-- Modal panel --}}
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">​</span>
            <div class="inline-block w-full max-w-4xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white dark:bg-gray-800 shadow-xl rounded-2xl">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-medium leading-6 text-gray-900 dark:text-white" id="modal-title">
                            Atur Komposisi untuk SKU: <span class="font-bold">{{ $bundleSku }}</span>
                        </h3>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            Tentukan komponen dan jumlah yang dibutuhkan untuk merakit 1 unit SKU ini.
                        </p>
                    </div>
                    <button @click="$wire.set('showModal', false)" class="text-gray-400 hover:text-gray-500">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-8">
                    {{-- Bagian Kiri: Form Tambah Komponen --}}
                    <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <h4 class="font-semibold text-gray-800 dark:text-gray-200">Tambah Komponen Baru</h4>
                        <div class="mt-4 space-y-4">
                            <div class="relative">
                                <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cari SKU Komponen</label>
                                <input type="text" wire:model.live.debounce.300ms="searchTerm" id="search" class="w-full mt-1 rounded-md" placeholder="Ketik untuk mencari SKU mandiri...">
                                @if(!empty($searchResults))
                                <div class="absolute z-10 w-full mt-1 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg">
                                    <ul>
                                        @foreach($searchResults as $result)
                                        <li wire:click="selectComponent('{{ $result['variant_sku'] }}', '{{ $result['variant_name'] }}')"
                                            class="px-4 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                                            <p class="font-semibold">{{ $result['variant_sku'] }}</p>
                                            <p class="text-xs text-gray-500">{{ $result['variant_name'] }}</p>
                                        </li>
                                        @endforeach
                                    </ul>
                                </div>
                                @endif
                                @error('selectedComponentSku') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label for="quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Jumlah</label>
                                <input type="number" wire:model="quantity" id="quantity" class="w-full mt-1 rounded-md" min="1">
                            </div>
                            <div>
                                <button type="button" wire:click="addComponent" class="w-full px-4 py-2 font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700">
                                    Tambahkan Komponen
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    {{-- Bagian Kanan: Daftar Komponen Saat Ini --}}
                    <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                        <h4 class="font-semibold text-gray-800 dark:text-gray-200">Komponen Saat Ini</h4>
                        <div class="mt-4 flow-root">
                            <ul role="list" class="-my-4 divide-y divide-gray-200 dark:divide-gray-700">
                                @forelse($components as $index => $component)
                                <li class="flex items-center justify-between py-4" wire:key="component-{{ $index }}">
                                    <p class="text-sm text-gray-800 dark:text-gray-200">
                                        <span class="font-bold">{{ $component['component_sku'] }}</span>
                                        <span class="text-gray-500">x {{ $component['quantity'] }}</span>
                                    </p>
                                    <button wire:click="removeComponent('{{ $component['component_sku'] }}')" class="text-red-500 hover:text-red-700 text-sm font-medium">Hapus</button>
                                </li>
                                @empty
                                <li class="py-4 text-center text-sm text-gray-500">
                                    Belum ada komponen.
                                </li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="mt-8 pt-5 border-t border-gray-200 dark:border-gray-700 flex justify-end space-x-3">
                    <button type="button" @click="$wire.set('showModal', false)" class="px-4 py-2 bg-white border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Batal
                    </button>
                    <button type="button" wire:click="saveComposition" class="px-4 py-2 text-white bg-black rounded-md shadow-sm text-sm font-medium hover:bg-gray-800">
                        Simpan Komposisi
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>