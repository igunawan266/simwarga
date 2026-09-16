<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return [
        'message' => 'SIMWarga API',
        'version' => '1.0.0',
        'documentation' => '/docs',
        'health' => '/health',
    ];
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'database' => 'connected',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::get('/docs', function () {
    return [
        'message' => 'API Documentation',
        'endpoints' => [
            'auth' => '/api/auth',
            'warga' => '/api/warga-profiles',
            'letters' => '/api/letters',
            'financial' => '/api/financial',
            'dues' => '/api/dues',
        ],
    ];
});
