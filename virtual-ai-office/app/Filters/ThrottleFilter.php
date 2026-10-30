<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Token-bucket rate limit per IP (and per bucket name).
 * Usage: 'throttle:login,5,60'  => 5 requests per 60 seconds for bucket "login".
 */
class ThrottleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        [$bucket, $capacity, $seconds] = array_pad((array) $arguments, 3, null);
        $bucket   = preg_replace('/[^a-z0-9_]/i', '', (string) ($bucket ?: 'api'));
        $capacity = (int) ($capacity ?: 60);
        $seconds  = (int) ($seconds ?: 60);

        $key = 'throttle_' . $bucket . '_' . md5($request->getIPAddress());
        if (Services::throttler()->check($key, $capacity, $seconds) === false) {
            return Services::response()
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) Services::throttler()->getTokenTime())
                ->setJSON(['error' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
