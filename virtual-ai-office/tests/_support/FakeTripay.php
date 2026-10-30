<?php

namespace Tests\Support;

use App\Libraries\Payment\Tripay\HttpTransport;
use App\Libraries\Payment\Tripay\TripayException;

/** Plays Tripay's API: canned responses per path, records every request. */
final class FakeTripay implements HttpTransport
{
    public array $requests  = [];
    public array $responses = [];
    public array $fail      = [];

    /** @var callable|null fn(array $form): array */
    public $onCreate;

    public function send(string $method, string $url, array $headers, array $query = [], array $form = []): array
    {
        $this->requests[] = compact('method', 'url', 'headers', 'query', 'form');
        $path             = preg_replace('#^https://tripay\.co\.id/api(-sandbox)?/#', '', $url);

        if (! empty($this->fail[$path])) {
            throw new TripayException('Tidak dapat terhubung ke Tripay: timeout');
        }
        if ($path === 'transaction/create' && $this->onCreate) {
            return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => ($this->onCreate)($form)])];
        }
        if (! array_key_exists($path, $this->responses)) {
            return ['status' => 404, 'body' => json_encode(['success' => false, 'message' => 'Not found'])];
        }

        return ['status' => 200, 'body' => json_encode(['success' => true, 'data' => $this->responses[$path]])];
    }
}
