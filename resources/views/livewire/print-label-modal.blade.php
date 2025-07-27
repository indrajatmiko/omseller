<div
    x-data
    @open-new-tab.window="window.open($event.detail.url, '_blank')"
>
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" @click.self="$wire.closeModal()">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-md" @click.away="$wire.closeModal()">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Cetak Label Pengiriman</h3>
                @if($isDropship)
                <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border-l-4 border-red-400 dark:border-red-600">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-red-400 dark:text-red-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.21 3.03-1.742 3.03H4.42c-1.532 0-2.492-1.696-1.742-3.03l5.58-9.92zM10 13a1 1 0 110-2 1 1 0 010 2zm-1-8a1 1 0 00-1 1v3a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-bold text-red-700 dark:text-red-300">
                                Pesanan Dropship
                            </p>
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">
                                Pastikan tidak menyertakan invoice atau materi promosi toko Anda.
                            </p>
                        </div>
                    </div>
                </div>
                @else
                <p class="text-sm text-gray-500 mb-6">Anda bisa mengubah detail pengirim sebelum mencetak.</p>
                @endif
                
                <div class="space-y-4">
                    <div>
                        <x-input-label for="sender_name" value="Nama Pengirim" />
                        <x-text-input wire:model="senderName" id="sender_name" class="block mt-1 w-full" type="text" />
                    </div>
                    <div>
                        <x-input-label for="sender_phone" value="No. Telepon Pengirim" />
                        <x-text-input wire:model="senderPhone" id="sender_phone" class="block mt-1 w-full" type="text" />
                    </div>
                </div>

                <div class="mt-6 flex justify-end space-x-4">
                    <button type="button" @click="$wire.closeModal()" class="px-3 py-1 border border-gray-300">Batal</button>
                    <button type="button" wire:click="generatePrintUrl" class="rounded-lg bg-black dark:bg-white px-4 py-2 text-sm font-semibold text-white dark:text-black hover:bg-gray-800 dark:hover:bg-gray-200 transition-colors">
                        Buka Halaman Cetak
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>