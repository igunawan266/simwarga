<?php

namespace App\Libraries\Payment\Tripay;

/** Minimal HTTP abstraction so the Tripay client can be tested without network access. */
interface HttpTransport
{
    /**
     * @return array{status: int, body: string}
     */
    public function send(string $method, string $url, array $headers, array $query = [], array $form = []): array;
}
