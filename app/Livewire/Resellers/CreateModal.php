<?php

namespace App\Livewire\Resellers;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\Reseller;
use App\Models\Indonesia\City;
use App\Models\Indonesia\District;
use App\Models\Indonesia\Province;
use Filament\Notifications\Notification;

class CreateModal extends Component
{
    public bool $showModal = false;
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
    
    protected function rules() {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
        'province_code' => 'required|exists:indonesia_provinces,code',
        'city_code'     => 'required|exists:indonesia_cities,code',
        'district_code' => 'required|exists:indonesia_districts,code',
            'address' => 'nullable|string',
            'discount_percentage' => 'required|numeric|in:0,20,25',
        ];
    }
    
    #[On('open-reseller-modal')]
    public function openModal()
    {
        $this->resetForm();
        $this->provinces = Province::pluck('name', 'code');
        $this->showModal = true;
    }

    public function closeModal()
    {
        $this->showModal = false;
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
            $this->districts = District::where('city_code', $value)->pluck('name', 'code');
        } else {
            $this->districts = [];
        }
        $this->district_code = null;
    }
    
    public function save(): void
    {
        $validated = $this->validate();
        $validated['user_id'] = auth()->id();

        $newReseller = Reseller::create($validated);

        Notification::make()->title('Simpan Berhasil')->success()->body("Reseller baru '{$this->name}' berhasil ditambahkan.")->send();
        
        // Kirim event bahwa reseller baru telah dibuat, beserta datanya
        $this->dispatch('reseller-created', reseller: $newReseller->id);
        $this->closeModal();
    }
    
    private function resetForm(): void
    {
        $this->resetExcept('showModal');
        $this->cities = [];
        $this->districts = [];
    }

    public function render()
    {
        return view('livewire.resellers.create-modal');
    }
}