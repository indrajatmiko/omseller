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
            $this->districts = District::where('city_code', $value)->orderBy('name')->pluck('name', 'code');
        } else {
            $this->districts = [];
        }
        $this->district_code = null;
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

        $newReseller = Reseller::create($validated);

        Notification::make()->title('Simpan Berhasil')->success()->body("Reseller baru '{$this->name}' berhasil ditambahkan.")->send();
        
        // Kirim event bahwa reseller baru telah dibuat, beserta datanya
        $this->dispatch('reseller-created', reseller: $newReseller->id);
        $this->closeModal();
    }
    
    private function resetForm(): void
    {
        $this->resetExcept('showModal');
        $this->is_dropship = '0';
        $this->cities = [];
        $this->districts = [];
    }

    public function render()
    {
        return view('livewire.resellers.create-modal');
    }
}