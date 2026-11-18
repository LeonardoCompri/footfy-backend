<?php

namespace Tests\Feature;

use Tests\TestCase;

class MaskSensitiveDataHelperTest extends TestCase
{
    public function test_masks_password_keys(): void
    {
        $masked = mask_sensitive_data([
            'name' => 'Fulano',
            'password' => 'segredo123',
            'password_confirmation' => 'segredo123',
            'current_password' => 'antiga123',
        ]);

        $this->assertSame([
            'name' => 'Fulano',
            'password' => '***',
            'password_confirmation' => '***',
            'current_password' => '***',
        ], $masked);
    }

    public function test_shows_only_the_last_two_digits_of_the_document(): void
    {
        $masked = mask_sensitive_data(['document' => '12345678912', 'cpf' => '123.456.789-34']);

        $this->assertSame(['document' => '***.***.***-12', 'cpf' => '***.***.***-34'], $masked);
    }

    public function test_masks_short_document_entirely(): void
    {
        $this->assertSame(['document' => '***'], mask_sensitive_data(['document' => '12']));
    }

    public function test_masks_sensitive_keys_inside_nested_arrays(): void
    {
        $masked = mask_sensitive_data([
            'headers' => ['Authorization' => 'Bearer abc', 'Cookie' => 'sessao=1', 'Accept' => 'application/json'],
            'payment' => ['creditCard' => ['number' => '4111111111111111', 'ccv' => '123'], 'value' => 49.9],
            'players' => [['name' => 'Fulano', 'document' => '98765432100']],
            'asaas-access-token' => 'chave',
        ]);

        $this->assertSame([
            'headers' => ['Authorization' => '***', 'Cookie' => '***', 'Accept' => 'application/json'],
            'payment' => ['creditCard' => '***', 'value' => 49.9],
            'players' => [['name' => 'Fulano', 'document' => '***.***.***-00']],
            'asaas-access-token' => '***',
        ], $masked);
    }

    public function test_keeps_the_card_field_of_the_event_type(): void
    {
        $masked = mask_sensitive_data(['card' => 'yellow', 'event_type' => ['card' => 'red']]);

        $this->assertSame(['card' => 'yellow', 'event_type' => ['card' => 'red']], $masked);
    }

    public function test_keeps_null_values(): void
    {
        $this->assertSame(['password' => null, 'document' => null], mask_sensitive_data(['password' => null, 'document' => null]));
    }
}
