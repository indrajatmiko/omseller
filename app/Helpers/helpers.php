<?php

if (!function_exists('chartComponent')) {
    /**
     * Menyiapkan data options dan series untuk ApexCharts.
     *
     * @param string $id
     * @param string $title
     * @param array $chartData ['labels' => [], 'series' => []]
     * @param string $prefix
     * @param string $type
     * @return object
     */
    function chartComponent(string $id, string $title, array $chartData, string $prefix = 'Rp', string $type = 'area'): object
    {
        $options = [
            'chart' => ['id' => $id, 'height' => 300, 'type' => $type, 'toolbar' => ['show' => false], 'zoom' => ['enabled' => false]],
            'xaxis' => ['categories' => $chartData['labels'] ?? [], 'labels' => ['style' => ['colors' => '#9ca3af']]],
            'yaxis' => ['labels' => ['style' => ['colors' => '#9ca3af'], 'formatter' => "function(val) { return '{$prefix} ' + new Intl.NumberFormat('id-ID').format(val); }"]],
            'dataLabels' => ['enabled' => false],
            'stroke' => ['curve' => 'smooth', 'width' => 2],
            'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.6, 'opacityTo' => 0.05]],
            'tooltip' => ['theme' => 'dark', 'x' => ['format' => 'dd MMM yyyy']],
            'grid' => ['borderColor' => '#374151', 'strokeDashArray' => 4],
        ];
        $series = [['name' => $title, 'data' => $chartData['series'] ?? []]];

        return (object) ['options' => json_encode($options), 'series' => json_encode($series)];
    }
}