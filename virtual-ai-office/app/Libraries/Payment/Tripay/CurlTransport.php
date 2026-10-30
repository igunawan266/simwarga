<?php

namespace App\Libraries\Payment\Tripay;

use Config\Services;
use Throwable;

class CurlTransport implements HttpTransport
{
    public function __construct(private readonly int $timeout = 20)
    {
    }

    public function send(string $method, string $url, array $headers, array $query = [], array $form = []): array
    {
        $options = ['headers' => $headers, 'http_errors' => false, 'timeout' => $this->timeout, 'connect_timeout' => 10];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($form !== []) {
            $options['form_params'] = $form;
        }

        try {
            $response = Services::curlrequest([], null, null, false)->request($method, $url, $options);
        } catch (Throwable $e) {
            throw new TripayException('Tidak dapat terhubung ke Tripay: ' . $e->getMessage(), 0, $e);
        }

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getBody()];
    }
}
