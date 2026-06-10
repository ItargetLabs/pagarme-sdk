<?php

declare(strict_types=1);

namespace PagarmeSdk\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PagarmeSdk\Address;
use PagarmeSdk\CreditCard\CreditCard;
use PagarmeSdk\CreditCard\CreditCardRequest;
use PagarmeSdk\Customer;
use PagarmeSdk\Environment;
use PagarmeSdk\Pagarme;
use PagarmeSdk\PaymentStatus;
use PagarmeSdk\Store;
use PHPUnit\Framework\TestCase;

final class CreditCardClientTest extends TestCase
{
    public function testCreateCreditCardPayment(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode([
            'id' => 'or_1',
            'charges' => [[
                'id' => 'ch_1',
                'status' => 'paid',
                'amount' => 15000,
                'last_transaction' => [
                    'installments' => 3,
                    'acquirer_nsu' => 'nsu-1',
                    'acquirer_auth_code' => 'auth-1',
                ],
            ]],
        ], JSON_THROW_ON_ERROR))]);

        $sdk = new Pagarme(
            new Store('sk_test', Environment::sandbox()),
            new Client(['handler' => HandlerStack::create($mock), 'base_uri' => 'https://example.test/'])
        );

        $response = $sdk->createCreditCardPayment(new CreditCardRequest(
            amount: 150.0,
            currency: 'BRL',
            customer: $this->customer(),
            creditCard: new CreditCard('4111111111111111', 'Cliente Teste', '12', '2030', '123'),
            installments: 3,
            description: 'Cartao teste',
            number: '123'
        ));

        $this->assertSame('ch_1', $response->tid);
        $this->assertSame(PaymentStatus::APPROVED, $response->status);
        $this->assertSame(3, $response->installments);
        $this->assertSame(50.0, $response->installmentAmount);
        $this->assertSame('nsu-1', $response->nsu);
    }

    private function customer(): Customer
    {
        return new Customer(
            id: '1',
            name: 'Cliente',
            email: 'cliente@test.com',
            document: '12345678900',
            phone: '11999999999',
            address: new Address('Rua A', '100', '01234567', 'Centro', 'Sao Paulo', 'SP')
        );
    }
}
