<?php

namespace App\Filters;

use CodeIgniter\HTTP\RequestInterface;
use Config\Services;

trait RespondsWithError
{
    /** JSON for API/AJAX callers, redirect to the portal for page requests. */
    protected function deny(RequestInterface $request, int $status, string $message)
    {
        $path = method_exists($request, 'getPath') ? trim($request->getPath(), '/') : '';
        $wantsJson = str_starts_with($path, 'api/')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');

        if ($wantsJson) {
            return Services::response()->setStatusCode($status)->setJSON(['error' => $message]);
        }

        return redirect()->to('/')->with('error', $message);
    }
}
