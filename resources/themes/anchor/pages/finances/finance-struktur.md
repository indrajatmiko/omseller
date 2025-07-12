Tentu. Dengan senang hati saya akan menyusun dan menyimpan seluruh struktur kode untuk fitur "Pencatatan Pengeluaran" yang telah kita kembangkan bersama.

Ini adalah ringkasan lengkap yang mencakup semua file yang diperlukan, dari database hingga komponen tampilan, dengan struktur yang rapi dan siap untuk diimplementasikan di proyek Laravel Anda.

Struktur Direktori Final

Berikut adalah gambaran letak semua file yang akan kita buat:

Generated code
.
├── app/
│   └── Models/
│       ├── Expense.php
│       └── ExpenseCategory.php
├── database/
│   └── migrations/
│       ├── ..._create_expense_categories_table.php
│       └── ..._create_expenses_table.php
└── resources/
    └── views/
        ├── components/
        │   ├── app/
        │   │   ├── container.blade.php   (BARU)
        │   │   ├── heading.blade.php     (BARU)
        │   │   └── divider.blade.php
        │   ├── input-error.blade.php
        │   ├── input-label.blade.php
        │   ├── primary-button.blade.php
        │   ├── secondary-button.blade.php
        │   ├── select-input.blade.php
        │   ├── textarea-input.blade.php
        │   └── text-input.blade.php
        └── pages/
            └── finances/
                └── expenses.blade.php      (Komponen Utama)

1. Migrasi Database

File-file ini untuk membuat struktur tabel di database Anda.

..._create_expense_categories_table.php
Generated php
<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_expense_categories_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
..._create_expenses_table.php
Generated php
<?php
// database/migrations/xxxx_xx_xx_xxxxxx_create_expenses_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
2. Model Eloquent

File-file ini mendefinisikan model untuk berinteraksi dengan tabel database.

app/Models/ExpenseCategory.php
Generated php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use HasFactory;
    protected $fillable = ['user_id', 'name'];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
app/Models/Expense.php
Generated php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'user_id', 
        'expense_category_id', 
        'amount', 
        'description', 
        'transaction_date'
    ];
    
    protected $casts = [
        'transaction_date' => 'date',
    ];

    public function category() {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function user() {
        return $this->belongsTo(User::class);
    }
}
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
3. Komponen Utama (Halaman)

Ini adalah file inti yang menggabungkan logika (PHP) dan tampilan (Blade).

resources/views/pages/finances/expenses.blade.php
Generated php
<?php

use function Laravel\Folio\{middleware, name};
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Filament\Notifications\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

middleware('auth');
name('finances.expenses');

new class extends Component {
    use WithPagination;

    // Properti untuk form input pengeluaran
    public string $expense_category_id = '';
    public string $amount = '';
    public string $description = '';
    public string $transaction_date;

    // Properti untuk form kategori baru
    public string $newCategoryName = '';
    
    // Properti untuk kontrol UI
    public bool $showForm = false;
    
    // Properti untuk filter bulan
    public ?string $selectedPeriod = null;

    public function mount(): void
    {
        $this->transaction_date = now()->format('Y-m-d');
    }

    public function selectPeriod(?string $period): void
    {
        $this->selectedPeriod = $period;
        $this->resetPage();
    }

    public function toggleForm(): void
    {
        $this->showForm = !$this->showForm;
    }
    
    public function saveExpense(): void
    {
        $validated = $this->validate([
            'expense_category_id' => ['required', 'exists:expense_categories,id,user_id,' . auth()->id()],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
            'transaction_date' => ['required', 'date'],
        ]);
        
        Expense::create(array_merge($validated, ['user_id' => auth()->id()]));
        
        Notification::make()->title('Pengeluaran Dicatat')->success()->send();
            
        $this->reset(['expense_category_id', 'amount', 'description']);
        $this->transaction_date = now()->format('Y-m-d');
        $this->showForm = false;
    }

    public function saveNewCategory(): void
    {
        $validated = $this->validate([
            'newCategoryName' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'name')->where('user_id', auth()->id())]
        ]);
        $newCategory = ExpenseCategory::create(['user_id' => auth()->id(), 'name' => $validated['newCategoryName']]);
        $this->reset('newCategoryName');
        Notification::make()->title('Kategori Dibuat')->success()->send();
        $this->expense_category_id = $newCategory->id;
    }
    
    public function getCategories()
    {
        return ExpenseCategory::where('user_id', auth()->id())->orderBy('name')->get();
    }

    public function with(): array
    {
        $availablePeriods = Expense::where('user_id', auth()->id())
            ->select(DB::raw("DATE_FORMAT(transaction_date, '%Y-%m') as period_key"), DB::raw("DATE_FORMAT(transaction_date, '%b %Y') as period_label"))
            ->distinct()
            ->orderBy('period_key', 'desc')
            ->get();

        $expensesQuery = Expense::where('user_id', auth()->id())
            ->with('category')
            ->when($this->selectedPeriod, function ($query) {
                $year = substr($this->selectedPeriod, 0, 4);
                $month = substr($this->selectedPeriod, 5, 2);
                $query->whereYear('transaction_date', $year)->whereMonth('transaction_date', $month);
            })
            ->latest('transaction_date')
            ->latest('id');

        return [
            'categories' => $this->getCategories(),
            'availablePeriods' => $availablePeriods,
            'expenses' => $expensesQuery->paginate(15),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('finances-expenses')
        <x-app.container>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <x-app.heading 
                    title="Pencatatan Pengeluaran"
                    description="Catat semua pengeluaran toko untuk melacak kesehatan finansial bisnis Anda."
                />
                <div class="mt-4 sm:mt-0 flex-shrink-0">
                    <x-primary-button type="button" wire:click="toggleForm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 -ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $showForm ? 'M6 18L18 6M6 6l12 12' : 'M12 4v16m8-8H4' }}" />
                        </svg>
                        <span>{{ $showForm ? 'Tutup Form' : 'Tambah Pengeluaran' }}</span>
                    </x-primary-button>
                </div>
            </div>

            @if($showForm)
                <div class="mt-6">
                    <form wire:submit="saveExpense">
                        <div class="bg-white dark:bg-gray-800/50 shadow-sm rounded-lg p-6 space-y-4">
                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 sm:gap-6">
                                <div>
                                    <x-input-label for="transaction_date" value="Tanggal" />
                                    <x-text-input id="transaction_date" wire:model="transaction_date" type="date" class="mt-1 block w-full" />
                                    <x-input-error :messages="$errors->get('transaction_date')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="amount" value="Jumlah (Rp)" />
                                    <x-text-input id="amount" wire:model="amount" type="number" step="100" class="mt-1 block w-full" placeholder="Contoh: 50000" />
                                    <x-input-error :messages="$errors->get('amount')" class="mt-2" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="category" value="Kategori Pengeluaran" />
                                <x-select-input id="category" wire:model.live="expense_category_id" class="mt-1 block w-full">
                                    <option value="" disabled>Pilih Kategori...</option>
                                    @foreach($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                                    @endforeach
                                </x-select-input>
                                <x-input-error :messages="$errors->get('expense_category_id')" class="mt-2" />
                                <div class="mt-2">
                                    <div class="flex items-center space-x-2">
                                        <x-text-input id="new-category" wire:model="newCategoryName" class="block w-full text-sm" type="text" placeholder="Atau buat kategori baru..." wire:keydown.enter.prevent="saveNewCategory" />
                                        <x-secondary-button type="button" wire:click="saveNewCategory" wire:loading.attr="disabled" wire:target="saveNewCategory">
                                            Tambah
                                        </x-secondary-button>
                                    </div>
                                    <x-input-error :messages="$errors->get('newCategoryName')" class="mt-2" />
                                </div>
                            </div>
                            <div>
                                <x-input-label for="description" value="Deskripsi (Opsional)" />
                                <x-textarea-input id="description" wire:model="description" class="mt-1 block w-full" rows="3" placeholder="Contoh: Pembelian bubble wrap dan lakban." />
                            </div>
                            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                                <x-primary-button type="submit">Simpan</x-primary-button>
                                <x-secondary-button type="button" wire:click="toggleForm" class="ml-2">Batal</x-secondary-button>
                            </div>
                        </div>
                    </form>
                </div>
            @endif

            <x-app.divider />

            <div>
                <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Riwayat Pengeluaran</h3>
                
                @if($availablePeriods->isNotEmpty())
                <div class="mt-4 flex items-center flex-wrap gap-2">
                    <button type="button" wire:click="selectPeriod(null)" 
                            class="px-3 py-1 text-sm font-medium rounded-full transition {{ is_null($selectedPeriod) ? 'bg-indigo-600 text-white shadow' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600' }}">
                        Semua
                    </button>
                    @foreach($availablePeriods as $period)
                        <button type="button" wire:click="selectPeriod('{{ $period->period_key }}')"
                                class="px-3 py-1 text-sm font-medium rounded-full transition {{ $selectedPeriod == $period->period_key ? 'bg-indigo-600 text-white shadow' : 'bg-white dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-600' }}">
                            {{ $period->period_label }}
                        </button>
                    @endforeach
                </div>
                @endif

                <div class="mt-6 flow-root">
                    <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                            <div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6">Tanggal</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Kategori & Deskripsi</th>
                                            <th scope="col" class="relative py-3.5 pl-3 pr-4 text-right text-sm font-semibold text-gray-900 dark:text-white sm:pr-6">Jumlah</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                        @forelse($expenses as $expense)
                                            <tr wire:key="expense-{{ $expense->id }}">
                                                <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm text-gray-500 dark:text-gray-400 sm:pl-6">{{ $expense->transaction_date->format('d M Y') }}</td>
                                                <td class="px-3 py-4 text-sm text-gray-500">
                                                    <div class="font-medium text-gray-900 dark:text-white">{{ $expense->category->name }}</div>
                                                    @if($expense->description)
                                                        <div class="text-gray-500 dark:text-gray-400 truncate max-w-xs" title="{{ $expense->description }}">{{ $expense->description }}</div>
                                                    @endif
                                                </td>
                                                <td class="whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium text-gray-900 dark:text-white sm:pr-6">Rp {{ number_format($expense->amount, 0, ',', '.') }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="text-center py-12 px-6 text-gray-500">
                                                    Tidak ada data pengeluaran{{ $selectedPeriod ? ' untuk periode ini' : '' }}.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                @if($expenses->hasPages())
                    <div class="mt-6">
                        {{ $expenses->links() }}
                    </div>
                @endif
            </div>

        </x-app.container>
    @endvolt
</x-layouts.app>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
PHP
IGNORE_WHEN_COPYING_END
4. Komponen Blade Pendukung

Ini adalah komponen-komponen kecil yang dapat digunakan kembali, yang membuat kode utama lebih bersih.

resources/views/components/app/container.blade.php
Generated blade
<div {{ $attributes->merge(['class' => 'max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8']) }}>
    {{ $slot }}
</div>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/app/heading.blade.php
Generated blade
@props(['title', 'description', 'border' => false])

<div>
    <div>
        <h2 class="text-2xl font-bold leading-7 text-gray-900 dark:text-white sm:text-3xl sm:truncate">{{ $title }}</h2>
        @if($description)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>
    @if($border)
        <div class="mt-6 border-t border-gray-200 dark:border-gray-700"></div>
    @endif
</div>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/app/divider.blade.php
Generated blade
<div class="my-8 border-t border-gray-200 dark:border-gray-700"></div>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/input-label.blade.php
Generated blade
@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700 dark:text-gray-300']) }}>
    {{ $value ?? $slot }}
</label>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/text-input.blade.php
Generated blade
@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge([
    'class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm'
]) !!}>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/select-input.blade.php
Generated blade
<select {!! $attributes->merge([
    'class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm'
]) !!}>
    {{ $slot }}
</select>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/textarea-input.blade.php
Generated blade
<textarea {!! $attributes->merge([
    'class' => 'border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm'
]) !!}>{{ $slot }}</textarea>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/input-error.blade.php
Generated blade
@props(['messages'])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'text-sm text-red-600 dark:text-red-400 space-y-1']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/primary-button.blade.php
Generated blade
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END
resources/views/components/secondary-button.blade.php
Generated blade
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-500 rounded-md font-semibold text-xs text-gray-700 dark:text-gray-300 uppercase tracking-widest shadow-sm hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
IGNORE_WHEN_COPYING_START
content_copy
download
Use code with caution.
Blade
IGNORE_WHEN_COPYING_END