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

    // Properti Form Tambah Baru
    public string $expense_category_id = '';
    public string $amount = '';
    public string $description = '';
    public string $transaction_date;
    public string $newCategoryName = '';
    public bool $showForm = false;
    
    // Properti Filter
    public $selectedYear;
    public $selectedMonth;

    // --- PERUBAHAN BARU: Properti untuk Edit & Delete ---
    public ?int $editingExpenseId = null;
    public array $editingExpenseData = [];
    public ?int $deletingExpenseId = null;

    public function mount(): void
    {
        $this->transaction_date = now()->format('Y-m-d');
        $this->selectedYear = now()->year;
        $this->selectedMonth = now()->month;
    }

    // Hooks untuk reset paginasi saat filter berubah
    public function updatedSelectedYear(): void { $this->resetPage(); }
    public function updatedSelectedMonth(): void { $this->resetPage(); }
    
    public function resetFilter(): void
    {
        $this->selectedYear = null;
        $this->selectedMonth = null;
        $this->resetPage();
    }

    public function toggleForm(): void { $this->showForm = !$this->showForm; }
    
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

    // --- PERUBAHAN BARU: Metode untuk Edit ---
    public function startEditing(int $expenseId): void
    {
        $this->editingExpenseId = $expenseId;
        $expense = Expense::where('user_id', auth()->id())->findOrFail($expenseId);
        
        $this->editingExpenseData = [
            'transaction_date' => $expense->transaction_date->format('Y-m-d'),
            'expense_category_id' => $expense->expense_category_id,
            'amount' => $expense->amount,
            'description' => $expense->description,
        ];
    }

    public function cancelEditing(): void
    {
        $this->reset('editingExpenseId', 'editingExpenseData');
    }

    public function updateExpense(): void
    {
        $validated = validator($this->editingExpenseData, [
            'expense_category_id' => ['required', 'exists:expense_categories,id,user_id,' . auth()->id()],
            'amount' => ['required', 'numeric', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
            'transaction_date' => ['required', 'date'],
        ])->validate();

        $expense = Expense::where('user_id', auth()->id())->findOrFail($this->editingExpenseId);
        $expense->update($validated);

        $this->cancelEditing();
        Notification::make()->title('Pengeluaran Diperbarui')->success()->send();
    }

    // --- PERUBAHAN BARU: Metode untuk Delete ---
    public function confirmDelete(int $expenseId): void
    {
        $this->deletingExpenseId = $expenseId;
    }
    
    public function cancelDelete(): void
    {
        $this->reset('deletingExpenseId');
    }

    public function deleteExpense(): void
    {
        $expense = Expense::where('user_id', auth()->id())->findOrFail($this->deletingExpenseId);
        $expense->delete();
        
        $this->cancelDelete();
        Notification::make()->title('Pengeluaran Dihapus')->success()->send();
    }
    
    public function getCategories()
    {
        return ExpenseCategory::where('user_id', auth()->id())->orderBy('name')->get();
    }

    public function with(): array
    {
        $availableYears = Expense::where('user_id', auth()->id())
            ->select(DB::raw('YEAR(transaction_date) as year'))
            ->distinct()->orderBy('year', 'desc')->get()->pluck('year');
        
        $availableMonths = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\Carbon::create(null, $m)->isoFormat('MMMM')]);

        $expensesQuery = Expense::where('user_id', auth()->id())->with('category')
            ->when($this->selectedYear, fn ($q) => $q->whereYear('transaction_date', $this->selectedYear))
            ->when($this->selectedMonth, fn ($q) => $q->whereMonth('transaction_date', $this->selectedMonth))
            ->latest('transaction_date')->latest('id');

        return [
            'categories' => $this->getCategories(),
            'availableYears' => $availableYears,
            'availableMonths' => $availableMonths,
            'expenses' => $expensesQuery->paginate(15),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('finances-expenses')
        <x-app.container>
            {{-- Header dan Tombol Tambah --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <x-app.heading title="Pencatatan Pengeluaran" description="Catat semua pengeluaran toko untuk melacak kesehatan finansial bisnis Anda." />
                <div class="mt-4 sm:mt-0 flex-shrink-0">
                    <x-primary-button type="button" wire:click="toggleForm">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 -ml-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $showForm ? 'M6 18L18 6M6 6l12 12' : 'M12 4v16m8-8H4' }}" />
                        </svg>
                        <span>{{ $showForm ? 'Tutup Form' : 'Tambah Pengeluaran' }}</span>
                    </x-primary-button>
                </div>
            </div>

            {{-- Form Tambah --}}
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

            {{-- Riwayat Pengeluaran --}}
            <div>
                <h3 class="font-semibold text-lg text-gray-900 dark:text-white">Riwayat Pengeluaran</h3>
                
                {{-- Filter --}}
                @if($availableYears->isNotEmpty())
                <div class="mt-4 flex items-center flex-wrap gap-x-4 gap-y-2">
                    <div class="flex items-center gap-2">
                        <x-select-input wire:model.live="selectedYear" class="text-sm"><option value="">Semua Tahun</option> @foreach($availableYears as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach</x-select-input>
                        <x-select-input wire:model.live="selectedMonth" class="text-sm"><option value="">Semua Bulan</option> @foreach($availableMonths as $num => $name)<option value="{{ $num }}">{{ $name }}</option>@endforeach</x-select-input>
                    </div>
                </div>
                @endif

                {{-- Tabel Riwayat --}}
                <div class="mt-6 flow-root">
                    <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="inline-block min-w-full py-2 align-middle sm:px-6 lg:px-8">
                            <div class="overflow-hidden shadow-sm ring-1 ring-black ring-opacity-5 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-300 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="py-3.5 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 dark:text-white sm:pl-6 w-16">Tanggal</th>
                                            <th scope="col" class="px-3 py-3.5 text-left text-sm font-semibold text-gray-900 dark:text-white">Pengeluaran</th>
                                            <th scope="col" class="px-3 py-3.5 text-right text-sm font-semibold text-gray-900 dark:text-white">Jumlah</th>
                                            <th scope="col" class="relative py-3.5 pl-3 pr-4 sm:pr-6"><span class="sr-only">Aksi</span></th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-800 bg-white dark:bg-gray-900">
                                        @forelse($expenses as $expense)
                                            @if($editingExpenseId === $expense->id)
                                                <tr wire:key="editing-{{ $expense->id }}">
                                                    <td colspan="4" class="p-4 bg-gray-50 dark:bg-gray-800/50">
                                                        <div class="space-y-4">
                                                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                                                <x-text-input type="date" wire:model="editingExpenseData.transaction_date" class="w-full" />
                                                                <x-text-input type="number" wire:model="editingExpenseData.amount" class="w-full" placeholder="Jumlah"/>
                                                                <x-select-input wire:model="editingExpenseData.expense_category_id" class="w-full sm:col-span-1">
                                                                    @foreach($categories as $category) <option value="{{ $category->id }}">{{ $category->name }}</option> @endforeach
                                                                </x-select-input>
                                                            </div>
                                                            <x-textarea-input wire:model="editingExpenseData.description" class="w-full" rows="2" placeholder="Deskripsi (Opsional)"/>
                                                            <div class="flex items-center justify-end space-x-2">
                                                                <x-input-error :messages="$errors->get('editingExpenseData.*')" />
                                                                <x-secondary-button wire:click="cancelEditing">Batal</x-secondary-button>
                                                                <x-primary-button wire:click="updateExpense">Simpan</x-primary-button>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                            @else
                                                <tr wire:key="display-{{ $expense->id }}">
                                                    {{-- Baris 1: Informasi Utama --}}
                                                    <td class="whitespace-nowrap py-4 pl-4 pr-3 text-sm sm:pl-6">
                                                        <div class="font-mono text-lg text-gray-900 dark:text-white">{{ $expense->transaction_date->format('d') }}</div>
                                                        {{-- <div class="text-xs text-gray-500 dark:text-gray-400">{{ $expense->transaction_date->format('M Y') }}</div> --}}
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-gray-800 dark:text-gray-200 font-medium">
                                                        {{ $expense->category->name }}
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-4 text-sm text-right text-gray-900 dark:text-white font-medium">
                                                        Rp {{ number_format($expense->amount, 0, ',', '.') }}
                                                    </td>
                                                    <td class="relative whitespace-nowrap py-4 pl-3 pr-4 text-right text-sm font-medium sm:pr-6">
                                                        @if($deletingExpenseId === $expense->id)
                                                            <span class="text-red-500">Hapus?</span>
                                                            <button wire:click="deleteExpense" class="text-red-600 hover:text-red-900 ml-2 font-semibold">Ya</button>
                                                            <button wire:click="cancelDelete" class="text-gray-600 hover:text-gray-900 ml-2">Tidak</button>
                                                        @else
                                                            <button wire:click="startEditing({{ $expense->id }})" class="text-indigo-600 hover:text-indigo-900">Edit</button>
                                                            <button wire:click="confirmDelete({{ $expense->id }})" class="text-red-600 hover:text-red-900 ml-4">Hapus</button>
                                                        @endif
                                                    </td>
                                                </tr>
                                                @if($expense->description)
                                                <tr wire:key="desc-{{ $expense->id }}">
                                                    {{-- Baris 2: Deskripsi --}}
                                                    <td colspan="4" class="px-6 pb-4 text-sm text-gray-500 dark:text-gray-400">
                                                        {{ $expense->description }}
                                                    </td>
                                                </tr>
                                                @endif
                                            @endif
                                        @empty
                                            <tr><td colspan="4" class="text-center py-12 px-6 text-gray-500">Tidak ada data pengeluaran.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                @if($expenses->hasPages())
                    <div class="mt-6">{{ $expenses->links() }}</div>
                @endif
            </div>
        </x-app.container>
    @endvolt
</x-layouts.app>