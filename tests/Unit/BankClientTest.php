<?php

declare(strict_types=1);

namespace PagarmeSdk\Tests\Unit;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PagarmeSdk\Address;
use PagarmeSdk\Bank\BankRequest;
use PagarmeSdk\BillAffiliate;
use PagarmeSdk\Customer;
use PagarmeSdk\Environment;
use PagarmeSdk\Pagarme;
use PagarmeSdk\Store;
use PHPUnit\Framework\TestCase;

final class BankClientTest extends TestCase
{
    public function testGenerateBankWithSplitRules(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], json_encode([
            'id' => 'or_1',
            'charges' => [[
                'id' => 'ch_1',
                'status' => 'pending',
                'amount' => 10000,
                'last_transaction' => [
                    'line' => '111',
                    'barcode' => '222',
                    'pdf' => 'https://boleto.test/123',
                ],
            ]],
        ], JSON_THROW_ON_ERROR))]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $sdk = new Pagarme(
            new Store('sk_test', Environment::sandbox()),
            new Client(['handler' => $handlerStack, 'base_uri' => 'https://example.test/'])
        );

        $response = $sdk->generateBank(new BankRequest(
            amount: 100.0,
            currency: 'BRL',
            customer: $this->customer(),
            description: 'Boleto teste',
            dueDate: new DateTime('2026-03-10'),
            number: '123',
            affiliates: [new BillAffiliate('rp_1', 50)]
        ));

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ch_1', $response->tid);
        $this->assertSame('boleto', $payload['payments'][0]['payment_method']);
        $this->assertSame('rp_1', $payload['payments'][0]['split'][0]['recipient_id']);
        $this->assertSame(50, $payload['payments'][0]['split'][0]['percentage']);
        $this->assertSame('CPF', $payload['customer']['document_type']);
        $this->assertSame('individual', $payload['customer']['type']);
    }

    public function testGenerateBankWithCnpjCustomer(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], json_encode([
            'id' => 'or_1',
            'charges' => [[
                'id' => 'ch_1',
                'status' => 'pending',
                'amount' => 10000,
                'last_transaction' => [],
            ]],
        ], JSON_THROW_ON_ERROR))]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $sdk = new Pagarme(
            new Store('sk_test', Environment::sandbox()),
            new Client(['handler' => $handlerStack, 'base_uri' => 'https://example.test/'])
        );

        $sdk->generateBank(new BankRequest(
            amount: 100.0,
            currency: 'BRL',
            customer: new Customer(
                id: '42',
                name: 'Empresa Teste LTDA',
                email: 'contato@empresa.com',
                document: '12345678000199',
            ),
            description: 'Boleto PJ',
            dueDate: new DateTime('2026-03-10'),
            number: '456',
        ));

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('12345678000199', $payload['customer']['document']);
        $this->assertSame('CNPJ', $payload['customer']['document_type']);
        $this->assertSame('company', $payload['customer']['type']);
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
