<div x-data="{ sidebarOpen: false }"  @open-sidebar.window="sidebarOpen = true"
    x-init="
        $watch('sidebarOpen', function(value){
            if(value){ document.body.classList.add('overflow-hidden'); } else { document.body.classList.remove('overflow-hidden'); }
        });
    "
    class="relative z-50 w-screen md:w-auto" x-cloak>
    {{-- Backdrop for mobile --}}
    <div x-show="sidebarOpen" @click="sidebarOpen=false" class="fixed top-0 right-0 z-50 w-screen h-screen duration-300 ease-out bg-black/20 dark:bg-white/10"></div>
    
    {{-- Sidebar --}} 
    <div :class="{ '-translate-x-full': !sidebarOpen }"
        class="fixed top-0 left-0 flex items-stretch -translate-x-full overflow-hidden lg:translate-x-0 z-50 h-dvh md:h-screen transition-[width,transform] duration-150 ease-out bg-zinc-50 dark:bg-zinc-900 w-64 group @if(config('wave.dev_bar')){{ 'pb-10' }}@endif">  
        <div class="flex flex-col justify-between w-full overflow-auto md:h-full h-svh pt-4 pb-2.5">
            <div class="relative flex flex-col">
                <button x-on:click="sidebarOpen=false" class="flex items-center justify-center flex-shrink-0 w-10 h-10 ml-4 rounded-md lg:hidden text-zinc-400 hover:text-zinc-800 dark:hover:text-zinc-200 dark:hover:bg-zinc-700/70 hover:bg-gray-200/70">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>

                <div class="flex items-center px-5 space-x-2">
                    <a href="/" class="flex justify-center items-center py-4 pl-0.5 space-x-1 font-bold text-zinc-900">
                        <x-logo class="w-auto h-7" />
                    </a>
                </div>
                <div class="flex items-center px-4 pt-1 pb-3">
                    <div class="relative flex items-center w-full h-full rounded-lg">
                        <x-phosphor-magnifying-glass class="absolute left-0 w-5 h-5 ml-2 text-gray-400 -translate-y-px" />
                        <input type="text" class="w-full py-2 pl-8 text-sm border rounded-lg bg-zinc-200/70 focus:bg-white duration-50 dark:bg-zinc-950 ease border-zinc-200 dark:border-zinc-700/70 dark:ring-zinc-700/70 focus:ring dark:text-zinc-200 dark:focus:ring-zinc-700/70 dark:focus:border-zinc-700 focus:ring-zinc-200 focus:border-zinc-300 dark:placeholder-zinc-400" placeholder="Search">
                    </div>
                </div>

                <div class="flex flex-col justify-start items-center px-4 space-y-1.5 w-full h-full text-slate-600 dark:text-zinc-400">
                    <x-app.sidebar-link href="/dashboard" icon="phosphor-house" :active="Request::is('dashboard')">Dashboard</x-app.sidebar-link>
                    <x-app.sidebar-dropdown text="Kalkulator Margin" icon="phosphor-calculator" id="kalkulators_dropdown" :active="(Request::is('kalkulators'))" :open="(Request::is('kalkulator-margin-shopee') || Request::is('kalkulator-margin-tiktok') || Request::is('kalkulator-margin-tokopedia')) ? '1' : '0'">
                        <x-app.sidebar-link href="/kalkulator-margin-shopee" icon="phosphor-bag" :active="(Request::is('kalkulator-margin-shopee'))">Shopee</x-app.sidebar-link>
                        <x-app.sidebar-link href="/kalkulator-margin-tiktok" icon="phosphor-tiktok-logo" :active="(Request::is('kalkulator-margin-tiktok'))">Tiktok</x-app.sidebar-link>
                        <x-app.sidebar-link href="/kalkulator-margin-tokopedia" icon="phosphor-storefront" :active="(Request::is('kalkulator-margin-tokopedia'))">Tokopedia</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-dropdown text="Voucher" icon="phosphor-calculator" id="vouchers_dropdown" :active="(Request::is('vouchers'))" :open="(Request::is('voucher-toko') || Request::is('voucher-produk') || Request::is('voucher-ikuti')) ? '1' : '0'">
                        <x-app.sidebar-link href="/voucher-toko" icon="phosphor-bag" :active="(Request::is('voucher-toko'))">Toko</x-app.sidebar-link>
                        <x-app.sidebar-link href="/voucher-produk" icon="phosphor-tiktok-logo" :active="(Request::is('voucher-produk'))">Produk</x-app.sidebar-link>
                        <x-app.sidebar-link href="/voucher-ikuti" icon="phosphor-storefront" :active="(Request::is('voucher-ikuti'))">Ikuti Toko</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-dropdown text="Performa Toko" icon="phosphor-chart-line" id="datacenters_dropdown" :active="(Request::is('datacenters'))" :open="(Request::is('datacenter-organik-iklan') || Request::is('datacenter-produk') || Request::is('campaigns')) ? '1' : '0'">
                        <x-app.sidebar-link href="/datacenter-organik-iklan" icon="phosphor-traffic-signal" :active="(Request::is('datacenter-organik-iklan'))">Organik vs Iklan</x-app.sidebar-link>
                        <x-app.sidebar-link href="/datacenter-produk" icon="phosphor-tiktok-logo" :active="(Request::is('datacenter-produk'))">Produk</x-app.sidebar-link>
                        <x-app.sidebar-link href="/campaigns" icon="phosphor-storefront" :active="(Request::is('campaigns'))">Campaign</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>

                    <x-app.sidebar-dropdown text="Sinkronisasi" icon="phosphor-arrows-clockwise" id="sinkronisasis_dropdown" :active="(Request::is('sinkronisasis'))" :open="(Request::is('sinkronisasi/pesanan-shopee') || Request::is('sinkronisasi/produk-shopee') || Request::is('datacenter-ikuti')) ? '1' : '0'">
                        <x-app.sidebar-link href="/sinkronisasi/produk-shopee" icon="phosphor-package" :active="(Request::is('sinkronisasi/produk-shopee'))">Produk</x-app.sidebar-link>
                        <x-app.sidebar-link href="/sinkronisasi/pesanan-shopee" icon="phosphor-trolley-suitcase" :active="(Request::is('sinkronisasi/pesanan-shopee'))">Pesanan</x-app.sidebar-link>
                        <x-app.sidebar-link href="/datacenter-ikuti" icon="phosphor-storefront" :active="(Request::is('datacenter-ikuti'))">Tokopedia</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-dropdown text="Keuangan" icon="phosphor-arrows-clockwise" id="keuangans_dropdown" :active="(Request::is('keuangans'))" :open="(Request::is('finances/expenses') || Request::is('purchases*') || Request::is('datacenter-ikuti')) ? '1' : '0'">
                        <x-app.sidebar-link href="/finances/expenses" icon="phosphor-package" :active="(Request::is('finances/expenses'))">Pengeluaran</x-app.sidebar-link>
                        <x-app.sidebar-link href="/purchases" icon="phosphor-trolley-suitcase" :active="(Request::is('purchases*'))">Purchase Order</x-app.sidebar-link>
                        <x-app.sidebar-link href="/datacenter-ikuti" icon="phosphor-storefront" :active="(Request::is('datacenter-ikuti'))">-</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-dropdown text="Pelanggan" icon="phosphor-arrows-clockwise" id="pelangans_dropdown" :active="(Request::is('pelangans'))" :open="(Request::is('customer/name-update') || Request::is('customer/detail') || Request::is('customer/segmentation')) ? '1' : '0'">
                        <x-app.sidebar-link href="/customer/name-update" icon="phosphor-package" :active="(Request::is('customer/name-update'))">Update Nama</x-app.sidebar-link>
                        <x-app.sidebar-link href="/customer/detail" icon="phosphor-trolley-suitcase" :active="(Request::is('customer/detail'))">Database</x-app.sidebar-link>
                        <x-app.sidebar-link href="/customer/segmentation" icon="phosphor-trolley-suitcase" :active="(Request::is('customer/segmentation'))">Segmentasi</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-dropdown text="Produk" icon="phosphor-arrows-clockwise" id="produks_dropdown" :active="(Request::is('produks'))" :open="(Request::is('inventory/sku-master') || Request::is('inventory/stock-takes*') || Request::is('inventory/stock*') || Request::is('inventory/categories')) ? '1' : '0'">
                        <x-app.sidebar-link href="/inventory/categories" icon="phosphor-package" :active="(Request::is('inventory/categories'))">Kategori</x-app.sidebar-link>
                        <x-app.sidebar-link href="/inventory/sku-master" icon="phosphor-package" :active="(Request::is('inventory/sku-master'))">SKU Master</x-app.sidebar-link>
                        <x-app.sidebar-link href="/inventory/stock-update" icon="phosphor-package" :active="(Request::is('inventory/stock-update*'))">STOK Update</x-app.sidebar-link>
                        <x-app.sidebar-link href="/inventory/stock-takes" icon="phosphor-trolley-suitcase" :active="(Request::is('inventory/stock-takes*'))">STOK Opname</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-dropdown text="Laporan" icon="phosphor-arrows-clockwise" id="reports_dropdown" :active="(Request::is('reports'))" :open="(Request::is('reports/daily-shipments') || Request::is('reports/profit-loss') || Request::is('reports/quarterly-review') || Request::is('reports/shipping-anomaly') || Request::is('reports/cancelled-orders')) ? '1' : '0'">
                        <x-app.sidebar-link href="/reports/daily-shipments" icon="phosphor-package" :active="(Request::is('reports/daily-shipments'))">Barang Keluar</x-app.sidebar-link>
                        <x-app.sidebar-link href="/reports/cancelled-orders" icon="phosphor-package" :active="(Request::is('reports/cancelled-orders'))">Pesanan Dibatalkan</x-app.sidebar-link>
                        <x-app.sidebar-link href="/reports/profit-loss" icon="phosphor-package" :active="(Request::is('reports/profit-loss'))">Laba Rugi</x-app.sidebar-link>
                        <x-app.sidebar-link href="/reports/quarterly-review" icon="phosphor-package" :active="(Request::is('reports/quarterly-review'))">Kuartalan</x-app.sidebar-link>
                        <x-app.sidebar-link href="/reports/shipping-anomaly" icon="phosphor-package" :active="(Request::is('reports/shipping-anomaly'))">Anomali Ongkir</x-app.sidebar-link>
                    </x-app.sidebar-dropdown>
                    
                    <x-app.sidebar-link onclick="event.preventDefault(); new FilamentNotification().title('Modify this button inside of sidebar.blade.php').send()" icon="phosphor-pencil-line" active="false">Produk - Hitung Margin</x-app.sidebar-link>
                    <x-app.sidebar-link  onclick="event.preventDefault(); new FilamentNotification().title('Modify this button inside of sidebar.blade.php').send()" icon="phosphor-users" active="false">Users</x-app.sidebar-link>
                </div>
            </div>

            <div class="relative px-2.5 space-y-1.5 text-zinc-700 dark:text-zinc-400">
                
                <x-app.sidebar-link href="https://devdojo.com/wave/docs" target="_blank" icon="phosphor-book-bookmark-duotone" active="false">Documentation</x-app.sidebar-link>
                <x-app.sidebar-link href="https://devdojo.com/questions" target="_blank" icon="phosphor-chat-duotone" active="false">Questions</x-app.sidebar-link>
                <x-app.sidebar-link :href="route('changelogs')" icon="phosphor-book-open-text-duotone" :active="Request::is('changelog') || Request::is('changelog/*')">Changelog</x-app.sidebar-link>

                <div x-show="sidebarTip" x-data="{ sidebarTip: $persist(true) }" class="px-1 py-3" x-collapse x-cloak>
                    <div class="relative w-full px-4 py-3 space-y-1 border rounded-lg bg-zinc-50 text-zinc-700 dark:text-zinc-100 dark:bg-zinc-800 border-zinc-200/60 dark:border-zinc-700">
                        <button @click="sidebarTip=false" class="absolute top-0 right-0 z-50 p-1.5 mt-2.5 mr-2.5 rounded-full opacity-80 cursor-pointer hover:opacity-100 hover:bg-zinc-100 hover:dark:bg-zinc-700 hover:dark:text-zinc-300 text-zinc-500 dark:text-zinc-400">
                            <x-phosphor-x-bold class="w-3 h-3" />
                        </button>
                        <h5 class="pb-1 text-sm font-bold -translate-y-0.5">Edit This Section</h5>
                        <p class="block pb-1 text-xs opacity-80 text-balance">You can edit any aspect of your user dashboard. This section can be found inside your theme component/app/sidebar file.</p>
                    </div>
                </div>

                <div class="w-full h-px my-2 bg-slate-100 dark:bg-zinc-700"></div>
                <x-app.user-menu />
            </div>
        </div>
    </div>
</div>
