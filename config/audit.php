<?php

return [
    'retention_months' => (int) env('AUDIT_RETENTION_MONTHS', 24),

    'sensitive_keys' => [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'token',
        'access_token',
        'api_key',
        'secret',
        'authorization',
        'cookie',
        'asaas-access-token',
        'credit_card',
        'credit_card_number',
        'credit_card_token',
        'credit_card_holder_info',
        'card_number',
        'card_holder_name',
        'card_expiry_month',
        'card_expiry_year',
        'card_token',
        'card_cvv',
        'cvv',
        'ccv',
    ],

    'document_keys' => [
        'document',
        'cpf',
    ],
];
