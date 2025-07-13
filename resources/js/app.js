// resources/js/app.js
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import ApexCharts from 'apexcharts'; // <-- Tambahkan ini

Alpine.plugin(collapse);

window.Alpine = Alpine;
window.ApexCharts = ApexCharts; // <-- Tambahkan ini

Alpine.start();