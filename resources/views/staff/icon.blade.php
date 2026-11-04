@php
    $paths = [
        'schedule' => 'M4 6h16v15H4z M8 3v6m8-6v6M4 11h16m-12 4h3m2 0h3',
        'patients' => 'M3 6h7l2 2h9v12H3z M10 13a2 2 0 1 0 4 0a2 2 0 1 0-4 0m-2 5c1-3 7-3 8 0',
        'encounters' => 'M7 4h10v2h2v15H5V6h2z M8 13h3l1-3 2 6 1-3h2',
        'inventory' => 'M3 7l9-4 9 4-9 4z M3 7v10l9 4 9-4V7m-9 4v10M7 5l10 4',
        'pos' => 'M6 3h12v18l-3-2-3 2-3-2-3 2z M9 8h6m-6 4h6m-6 4h3',
        'reports' => 'M4 3v17h17M8 16v-4m5 4V9m5 7V5',
        'inbox' => 'M4 4h16v13H9l-5 4z M8 9h8m-8 4h5',
        'hr' => 'M8 10a3 3 0 1 0 0-6a3 3 0 1 0 0 6m-6 9v-2a6 6 0 0 1 12 0v2z M16 5a3 3 0 0 1 0 6m1 2a5 5 0 0 1 5 5v1h-5',
        'login' => 'M6 10h12v11H6z M8 10V7a4 4 0 0 1 8 0v3m-4 4v3',
    ];
@endphp
<svg class="module-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $paths[$module] }}"></path></svg>
