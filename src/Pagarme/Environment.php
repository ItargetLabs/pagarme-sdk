<?php

declare(strict_types=1);

namespace PagarmeSdk;

final class Environment
{
    private function __construct(private readonly string $apiUrl)
    {
    }

    public static function production(): self
    {
        return new self('https://api.pagar.me/core/v5/');
    }

    public static function sandbox(): self
    {
        return self::production();
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }
}
