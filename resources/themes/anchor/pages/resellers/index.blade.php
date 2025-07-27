<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Reseller;
use App\Models\User;
use App\Models\Indonesia\City;
use App\Models\Indonesia\District;
use App\Models\Indonesia\Province;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Filament\Notifications\Notification;

middleware('auth');
name('resellers.index');

new class extends Component {
    use WithPagination;

    public ?Reseller $editing = null;
    public string $name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public float $discount_percentage = 0;

    // Untuk dependent dropdown
    public $provinces;
    public $cities = [];
    public $districts = [];
    public $province_code = null;
    public $city_code = null;
    public $district_code = null;
    public string $is_dropship = '0';
    public string $dropship_name = '';

    protected function rules() {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'province_code' => 'required|exists:indonesia_provinces,code',
            'city_code'     => 'required|exists:indonesia_cities,code',
            'district_code' => 'required|exists:indonesia_districts,code',
            'address' => 'nullable|string',
            'discount_percentage' => 'required|numeric|in:0,10,20,25',
            'is_dropship' => 'required|in:0,1',
            'dropship_name' => 'required_if:is_dropship,1|nullable|string|max:255',
        ];
    }

    public function mount(): void
    {
        $this->provinces = Province::pluck('name', 'code');
        $this->resetForm();
    }
    
    public function updatedProvinceCode($value): void
    {
        if ($value) {
            $this->cities = City::where('province_code', $value)->pluck('name', 'code');
        } else {
            $this->cities = [];
        }
        $this->city_code = null;
        $this->districts = [];
        $this->district_code = null;
    }

    public function updatedCityCode($value): void
    {
        if ($value) {
            $this->districts = District::where('city_code', $value)->orderBy('name')->pluck('name', 'code');
        } else {
            $this->districts = [];
        }
        $this->district_code = null;
    }

    public function edit(Reseller $reseller): void
    {
        $this->editing = $reseller;
        $this->name = $reseller->name;
        $this->phone = $reseller->phone;
        $this->email = $reseller->email;
        $this->address = $reseller->address;
        $this->discount_percentage = $reseller->discount_percentage;
        $this->is_dropship = $reseller->is_dropship ? '1' : '0'; 
        $this->dropship_name = $reseller->dropship_name;
        
        $this->province_code = $reseller->province_code;
        // Pengecekan null
        $this->cities = $this->province_code ? City::where('province_code', $this->province_code)->pluck('name', 'code') : [];
        
        $this->city_code = $reseller->city_code;
        // Pengecekan null
        $this->districts = $this->city_code ? District::where('city_code', $this->city_code)->pluck('name', 'code') : [];
        
        $this->district_code = $reseller->district_code;
    }
    
    public function updatedIsDropship($value)
    {
        if ($value === '0') {
            $this->dropship_name = '';
        }
    }

    public function save(): void
    {
        if ($this->is_dropship === '0') {
            $this->dropship_name = '';
        }
        $validated = $this->validate();
        $validated['user_id'] = auth()->id();

        if ($this->editing) {
            $this->editing->update($validated);
            Notification::make()->title('Update Berhasil')->success()->body("Data reseller '{$this->name}' berhasil diperbarui.")->send();
        } else {
            Reseller::create($validated);
            Notification::make()->title('Simpan Berhasil')->success()->body("Reseller baru '{$this->name}' berhasil ditambahkan.")->send();
        }

        $this->resetForm();
    }

    public function delete(Reseller $reseller): void
    {
        // Pengecekan jika reseller sudah punya order, agar tidak bisa dihapus
        if ($reseller->orders()->exists()) {
            Notification::make()->title('Gagal Hapus')->danger()->body("Reseller tidak bisa dihapus karena sudah memiliki riwayat pesanan.")->send();
            return;
        }
        
        $resellerName = $reseller->name;
        $reseller->delete();
        Notification::make()->title('Hapus Berhasil')->success()->body("Reseller '{$resellerName}' telah dihapus.")->send();
    }
    
    public function cancelEditing(): void
    {
        $this->resetForm();
    }
    
    private function resetForm(): void
    {
        $this->editing = null;
        $this->resetExcept('provinces');
        $this->is_dropship = '0';
        $this->cities = [];
        $this->districts = [];
    }
    
    public function with(): array
    {
        return [
            'resellers' => Reseller::where('user_id', auth()->id())
                ->with('province', 'city', 'district')
                ->latest()
                ->paginate(10),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('resellers')
        <x-app.container>
            <x-app.heading 
                title="Manajemen Reseller"
                description="Kelola data reseller Anda di sini."
            >
                <button wire:click="edit(new App\Models\Reseller)" class="btn-primary">
                    Tambah Reseller Baru
                </button>
            </x-app.heading>

            @if($editing)
            <div class="mt-6 p-6 bg-white dark:bg-gray-800 rounded-lg shadow" wire:key="editor">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
                    {{ $editing->exists ? 'Edit Reseller: ' . $editing->name : 'Tambah Reseller Baru' }}
                </h3>
                <form wire:submit.prevent="save">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        {{-- Kolom Kiri --}}
                        <div class="space-y-4">
                             <div>
                                <x-input-label for="name" value="Nama Reseller" />
                                <x-text-input wire:model="name" id="name" class="block mt-1 w-full" type="text" required />
                                <x-input-error :messages="$errors->get('name')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="phone" value="No. Telepon" />
                                <x-text-input wire:model="phone" id="phone" class="block mt-1 w-full" type="text" />
                                <x-input-error :messages="$errors->get('phone')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="email" value="Email" />
                                <x-text-input wire:model="email" id="email" class="block mt-1 w-full" type="email" />
                                <x-input-error :messages="$errors->get('email')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="discount_percentage" value="Diskon Reseller (%)" />
                                <x-select-input wire:model="discount_percentage" id="discount_percentage" class="block mt-1 w-full" required>
                                    <option value="0">0% (Tidak ada diskon)</option>
                                    <option value="10">10%</option>
                                    <option value="20">20%</option>
                                    <option value="25">25%</option>
                                </x-select-input>
                                <x-input-error :messages="$errors->get('discount_percentage')" class="mt-2" />
                            </div>
                        </div>

                        {{-- Kolom Kanan --}}
                        <div class="space-y-4">
                             <div>
                                <x-input-label for="province_code" value="Provinsi" />
                                <x-select-input wire:model.live="province_code" id="province_code" class="block mt-1 w-full" required>
                                    <option value="">-- Pilih Provinsi --</option>
                                    @foreach($provinces as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('province_code')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="city_code" value="Kota/Kabupaten" />
                                <x-select-input wire:model.live="city_code" id="city_code" class="block mt-1 w-full" required>
                                     <option value="">-- Pilih Kota/Kabupaten --</option>
                                    @foreach($cities as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('city_code')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="district_code" value="Kecamatan" />
                                <x-select-input wire:model="district_code" id="district_code" class="block mt-1 w-full" required>
                                     <option value="">-- Pilih Kecamatan --</option>
                                     @foreach($districts as $code => $name)
                                        <option value="{{ $code }}">{{ $name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('district_code')" class="mt-2" />
                            </div>
                             <div>
                                <x-input-label for="address" value="Alamat Lengkap (Jalan, No. Rumah, RT/RW)" />
                                <textarea wire:model="address" id="address" rows="3" class="input-field"></textarea>
                                <x-input-error :messages="$errors->get('address')" class="mt-2" />
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-4">
                        <button type="button" wire:click="cancelEditing" class="btn-secondary">Batal</button>
                        <button type="submit" class="btn-primary">Simpan Reseller</button>
                    </div>
                </form>
            </div>
            @endif

            {{-- Tabel Data Reseller --}}
            <div class="mt-8 flow-root">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="th-cell">Nama</th>
                                <th class="th-cell">Kontak</th>
                                <th class="th-cell">Alamat</th>
                                <th class="th-cell text-center">Diskon</th>
                                <th class="th-cell"></th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse($resellers as $reseller)
                            <tr wire:key="{{ $reseller->id }}">
                                <td class="td-cell font-medium text-gray-900 dark:text-white">{{ $reseller->name }}</td>
                                <td class="td-cell">
                                    <div class="text-sm text-gray-900 dark:text-gray-200">{{ $reseller->phone }}</div>
                                    <div class="text-sm text-gray-500">{{ $reseller->email }}</div>
                                </td>
                                <td class="td-cell text-sm text-gray-500">
                                    {{ $reseller->address }}, {{ $reseller->district->name ?? '' }}, {{ $reseller->city->name ?? '' }}, {{ $reseller->province->name ?? '' }}
                                </td>
                                <td class="td-cell text-center text-sm font-semibold">{{ $reseller->discount_percentage }}%</td>
                                <td class="td-cell text-right text-sm font-medium space-x-2">
                                    <button wire:click="edit({{ $reseller->id }})" class="text-indigo-600 hover:text-indigo-900">Edit</button>
                                    <button wire:click="delete({{ $reseller->id }})" wire:confirm="Anda yakin ingin menghapus reseller ini?" class="text-red-600 hover:text-red-900">Hapus</button>
                                </td>
                            </tr>
                            @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                    Belum ada data reseller.
                                </td>
                            </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                 @if($resellers->hasPages())
                    <div class="mt-4">{{ $resellers->links() }}</div>
                @endif
            </div>

        </x-app.container>
    @endvolt
</x-layouts.app>