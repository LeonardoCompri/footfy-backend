<?php

return [
    'pagination' => [
        'per_page' => (int) env('PAGINATION_PER_PAGE', 20),
        'max_per_page' => (int) env('PAGINATION_MAX_PER_PAGE', 100),
    ],

    'seed' => [
        'password' => env('SEED_PASSWORD'),
    ],

    'telescope_prune_hours' => (int) env('TELESCOPE_PRUNE_HOURS', 48),
];
