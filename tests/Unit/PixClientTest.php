<?php

declare(strict_types=1);

namespace PagarmeSdk\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PagarmeSdk\Address;
use PagarmeSdk\Customer;
use PagarmeSdk\Environment;
use PagarmeSdk\Pagarme;
use PagarmeSdk\Pix\PixRequest;
use PagarmeSdk\Store;
use PHPUnit\Framework\TestCase;

final class PixClientTest extends TestCase
{
    public function testCreatePixCharge(): void
    {
        $history = [];
        $sdk = $this->sdkWithResponse($history, [
            'id' => 'or_1',
            'charges' => [[
                'id' => 'ch_1',
                'status' => 'pending',
                'amount' => 1000,
                'last_transaction' => [
                    'id' => 'tran_1',
                    'qr_code' => 'pix-copy-paste',
                    'qr_code_url' => 'https://qr.test/pix.png',
                ],
            ]],
        ]);

        $response = $sdk->createPixCharge(new PixRequest(
            amount: 10.0,
            currency: 'BRL',
            customer: $this->customer(),
            description: 'PIX teste',
            number: '123'
        ));

        $payload = json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ch_1', $response->tid);
        $this->assertSame('pix-copy-paste', $response->pixCopyPaste);
        $this->assertSame('pix', $payload['payments'][0]['payment_method']);
        $this->assertSame(1000, $payload['items'][0]['amount']);
    }

    private function sdkWithResponse(array &$history, array $payload): Pagarme
    {
        $mock = new MockHandler([new Response(200, [], json_encode($payload, JSON_THROW_ON_ERROR))]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        return new Pagarme(
            new Store('sk_test', Environment::sandbox()),
            new Client(['handler' => $handlerStack, 'base_uri' => 'https://example.test/'])
        );
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
