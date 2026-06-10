# SDK PHP Pagar.me

SDK de integração com Pagar.me v5 para Boleto, Pix, Cartão de Crédito, consulta de transações e parser de webhook.

## Funcionalidades

- Pix: geração, consulta de status e payload copia-e-cola
- Cartão de crédito: criação de cobrança e parcelamento
- Boleto: emissão e consulta de dados
- Consulta de transação por `order_id` / `charge_id`
- Mapeamento de status de pagamento
- Parser de webhook de liquidação
- Split de pagamento via `recipient_id`

## Requisitos

- PHP >= 8.1
- Guzzle HTTP
- Docker, para o fluxo local deste projeto

## Instalação

```bash
composer require devsitarget/sdk-pagarme-php
```

Para desenvolvimento local:

```bash
make build
make up
make install
```

## Configuração

```php
<?php

use PagarmeSdk\Environment;
use PagarmeSdk\Pagarme;
use PagarmeSdk\Store;

$store = new Store(
    apiKey: 'SUA_SECRET_KEY',
    environment: Environment::sandbox()
);

$pagarme = new Pagarme($store);
```

> A API v5 do Pagar.me usa a mesma URL para sandbox e produção. O comportamento depende da chave informada.

## Cliente

```php
<?php

use PagarmeSdk\Address;
use PagarmeSdk\Customer;

$customer = new Customer(
    id: '123',
    name: 'Cliente',
    email: 'cliente@exemplo.com',
    document: '12345678900',
    phone: '11999999999',
    address: new Address(
        street: 'Rua A',
        number: '100',
        zipCode: '01234567',
        neighborhood: 'Centro',
        city: 'Sao Paulo',
        state: 'SP'
    )
);
```

## Pix

```php
<?php

use PagarmeSdk\Pix\PixRequest;

$pixResponse = $pagarme->createPixCharge(new PixRequest(
    amount: 120.50,
    currency: 'BRL',
    customer: $customer,
    description: 'Pedido 123',
    number: '123',
    metadata: [
        'expires_in' => 3600,
    ]
));

echo $pixResponse->pixCopyPaste;
```

## Cartão De Crédito

```php
<?php

use PagarmeSdk\CreditCard\CreditCard;
use PagarmeSdk\CreditCard\CreditCardRequest;

$cardResponse = $pagarme->createCreditCardPayment(new CreditCardRequest(
    amount: 150.00,
    currency: 'BRL',
    customer: $customer,
    creditCard: new CreditCard(
        number: '4111111111111111',
        holderName: 'Cliente Teste',
        expirationMonth: '12',
        expirationYear: '2030',
        securityCode: '123'
    ),
    installments: 3,
    description: 'Pedido 123',
    number: '123'
));

echo $cardResponse->status->value;
```

## Boleto

```php
<?php

use DateTime;
use PagarmeSdk\Bank\BankRequest;

$bankResponse = $pagarme->generateBank(new BankRequest(
    amount: 100.00,
    currency: 'BRL',
    customer: $customer,
    description: 'Pedido 123',
    dueDate: new DateTime('2026-03-10'),
    number: '123'
));

echo $bankResponse->url;
```

## Consultas

```php
<?php

$order = $pagarme->getTransactionById('or_123');
$status = $pagarme->checkPaymentStatus('or_123');
$bankData = $pagarme->getBankData('or_123');
$pixData = $pagarme->checkPixStatus('or_123');
$pixPayload = $pagarme->getPixPayload('or_123');
```

## Webhook

```php
<?php

$parsed = Pagarme::parseSettlementWebhook($payload);

// Campos principais:
// tid, transactionId, tokenTransaction, paymentMethodCode,
// statusCode, lowDate, occurrenceDate, authorizationCode, nsu
```

## Split

As requests aceitam `affiliates` com objetos `BillAffiliate`. No Pagar.me, o split usa `recipient_id`.

```php
<?php

use PagarmeSdk\BillAffiliate;
use PagarmeSdk\Pix\PixRequest;

$pixResponse = $pagarme->createPixCharge(new PixRequest(
    amount: 120.50,
    currency: 'BRL',
    customer: $customer,
    description: 'Pedido com split',
    number: '123',
    affiliates: [
        new BillAffiliate(recipientId: 'rp_123', percentage: 50),
        new BillAffiliate(recipientId: 'rp_456', commissionAmount: 10.00),
    ]
));
```

## Docker

```bash
make build
make up
make install
make test
make phpstan
make cs-check
```

Para criar o arquivo `.env` local:

```bash
make setup-env
```

## Testes

```bash
composer test
```

Ou pelo Docker:

```bash
make test
```

## Observações

- Valores monetários devem ser informados em reais no SDK. O payload enviado ao Pagar.me converte para centavos.
- `Environment::sandbox()` e `Environment::production()` usam a mesma base URL da API v5.
- O `Store` recebe a secret key do Pagar.me em `apiKey`.
- A pasta `Pagarme-exemple` foi mantida como referência da integração original.
