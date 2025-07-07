@props(['title', 'value'])
<div class="bg-white dark:bg-gray-800 p-4 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">{{ $title }}</dt>
    <dd class="mt-1 text-2xl font-semibold tracking-tight text-gray-900 dark:text-gray-100">{{ $value }}</dd>
</div>