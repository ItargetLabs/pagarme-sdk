<?php

declare(strict_types=1);

namespace PagarmeSdk;

final class Address
{
    public function __construct(
        public string $street,
        public string $number,
        public string $zipCode,
        public string $neighborhood,
        public string $city,
        public string $state,
        public ?string $complement = null
    ) {
    }
}
