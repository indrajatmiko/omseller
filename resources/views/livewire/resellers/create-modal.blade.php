<div>
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50" x-data @click.self="$wire.closeModal()">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-2xl" @click.away="$wire.closeModal()">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Tambah Reseller Baru</h3>
                <form wire:submit.prevent="save">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {{-- Kolom Kiri --}}
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="modal_name" value="Nama Reseller" />
                                <x-text-input wire:model="name" id="modal_name" class="block mt-1 w-full" type="text" required />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="modal_phone" value="No. Telepon" />
                                <x-text-input wire:model="phone" id="modal_phone" class="block mt-1 w-full" type="text" />
                                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="modal_discount" value="Diskon Reseller (%)" />
                                <x-select-input wire:model="discount_percentage" id="modal_discount" class="block mt-1 w-full" required>
                                    <option value="0">0%</option>
                                    <option value="20">20%</option>
                                    <option value="25">25%</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('discount_percentage')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Kolom Kanan --}}
                        <div class="space-y-4">
                            <div>
                                <x-input-label for="modal_province" value="Provinsi" />
                                <x-select-input wire:model.live="province_code" id="modal_province" class="block mt-1 w-full" required>
                                    <option value="">-- Pilih --</option>
                                    @foreach($provinces as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('province_code')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="modal_city" value="Kota/Kabupaten" />
                                <x-select-input wire:model.live="city_code" id="modal_city" class="block mt-1 w-full" required>
                                     <option value="">-- Pilih --</option>
                                    @foreach($cities as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('city_code')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="modal_district" value="Kecamatan" />
                                <x-select-input wire:model="district_code" id="modal_district" class="block mt-1 w-full" required>
                                     <option value="">-- Pilih --</option>
                                     @foreach($districts as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('district_code')" class="mt-2" />
                            </div>
                        </div>
                    </div>
                     <div class="mt-4">
                        <x-input-label for="modal_address" value="Alamat Lengkap" />
                        <textarea wire:model="address" id="modal_address" rows="2" class="input-field"></textarea>
                        <x-input-error :messages="$errors->get('address')" class="mt-2" />
                    </div>
                    <div class="mt-6 flex justify-end space-x-4">
                        <button type="button" wire:click="closeModal" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md shadow-sm text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">Batal</button>
                        <button type="submit" class="px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-black dark:bg-gray-200 dark:text-black hover:bg-gray-800 dark:hover:bg-gray-300">Simpan Reseller</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>