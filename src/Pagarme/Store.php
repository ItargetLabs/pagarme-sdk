<?php

declare(strict_types=1);

namespace PagarmeSdk;

final class Store
{
    public function __construct(
        private readonly string $apiKey,
        private readonly Environment $environment
    ) {
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getEnvironment(): Environment
    {
        return $this->environment;
    }
}
