{{-- resources/views/pages/inventory/categories/index.blade.php --}}
<?php

use function Laravel\Folio\{middleware, name};
use App\Models\ProductCategory;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;

middleware('auth');
name('inventory.categories');

new class extends Component {
    use WithPagination;

    public ?ProductCategory $editing = null;
    public string $name = '';

    public function mount(): void
    {
        //
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => 'required|string|min:2|max:255',
        ]);
        
        $slug = Str::slug($validated['name']);
        
        // Cek duplikasi slug
        $isDuplicated = ProductCategory::where('slug', $slug)
            ->where('user_id', auth()->id())
            ->when($this->editing, fn ($q) => $q->where('id', '!=', $this->editing->id))
            ->exists();

        if ($isDuplicated) {
            $this->addError('name', 'Nama kategori ini sudah ada.');
            return;
        }

        if ($this->editing) {
            $this->editing->update([
                'name' => $validated['name'],
                'slug' => $slug,
            ]);
            Notification::make()->title('Kategori diperbarui!')->success()->send();
        } else {
            ProductCategory::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'user_id' => auth()->id(),
            ]);
            Notification::make()->title('Kategori baru ditambahkan!')->success()->send();
        }
        
        $this->resetForm();
    }
    
    public function edit(ProductCategory $category): void
    {
        $this->editing = $category;
        $this->name = $category->name;
    }
    
    public function delete(ProductCategory $category): void
    {
        // Cek apakah kategori masih digunakan oleh produk
        if ($category->products()->exists()) {
            Notification::make()
                ->title('Gagal Menghapus')
                ->danger()
                ->body('Kategori ini masih digunakan oleh beberapa produk.')
                ->send();
            return;
        }
        
        $category->delete();
        Notification::make()->title('Kategori dihapus!')->success()->send();
    }

    public function resetForm(): void
    {
        $this->reset('editing', 'name');
    }

    public function with(): array
    {
        return [
            'categories' => ProductCategory::where('user_id', auth()->id())->latest()->paginate(10),
        ];
    }
}; ?>

<x-layouts.app>
    @volt('inventory-categories')
        <div>
            <x-app.container>
                <x-app.heading 
                    title="Master Kategori"
                    description="Kelola semua kategori produk Anda di satu tempat."
                    :border="true" />

                {{-- Form Input --}}
                <div class="mt-6 p-4 bg-white dark:bg-gray-800 rounded-lg shadow">
                    <form wire:submit="save">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                            {{ $editing ? 'Edit Kategori' : 'Tambah Kategori Baru' }}
                        </h3>
                        <div class="mt-4 flex items-end space-x-4">
                            <div class="flex-grow">
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Nama Kategori</label>
                                <input type="text" id="name" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-black focus:ring-black dark:focus:border-white dark:focus:ring-white sm:text-sm" placeholder="Contoh: Perawatan Wajah">
                                @error('name') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-black hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-black">
                                    {{ $editing ? 'Simpan Perubahan' : 'Simpan' }}
                                </button>
                                @if($editing)
                                    <button type="button" wire:click="resetForm" class="ml-2 inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none">
                                        Batal
                                    </button>
                                @endif
                            </div>
                        </div>
                    </form>
                </div>
                
                {{-- Tabel Kategori --}}
                <div class="mt-6 flex flex-col">
                    <div class="-my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                        <div class="py-2 align-middle inline-block min-w-full sm:px-6 lg:px-8">
                            <div class="shadow overflow-hidden border-b border-gray-200 dark:border-gray-700 sm:rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Nama</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Jumlah Produk</th>
                                            <th scope="col" class="relative px-6 py-3"><span class="sr-only">Aksi</span></th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                        @forelse($categories as $category)
                                            <tr wire:key="category-{{ $category->id }}">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">{{ $category->name }}</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">{{ $category->products()->count() }}</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <button wire:click="edit({{ $category->id }})" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-300">Edit</button>
                                                    <button wire:click="delete({{ $category->id }})" wire:confirm="Anda yakin ingin menghapus kategori ini?" class="ml-4 text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">Hapus</button>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="3" class="px-6 py-12 text-center text-sm text-gray-500">
                                                    Belum ada kategori.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                @if($categories->hasPages())
                    <div class="mt-4">
                        {{ $categories->links() }}
                    </div>
                @endif

            </x-app.container>
        </div>
    @endvolt
</x-layouts.app>