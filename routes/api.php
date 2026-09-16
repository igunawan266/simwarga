<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LetterRequestController;
use App\Http\Controllers\WargaProfileController;
use App\Http\Controllers\FinancialTransactionController;
use App\Http\Controllers\DuesBillingController;

Route::middleware('guest')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me', [AuthController::class, 'update']);

    Route::apiResource('warga-profiles', WargaProfileController::class);

    Route::prefix('letters')->group(function () {
        Route::post('/', [LetterRequestController::class, 'store']);
        Route::get('/{id}', [LetterRequestController::class, 'show']);

        Route::middleware('role:ketua_rt,sekretaris_rt')->group(function () {
            Route::get('/rt/pending', [LetterRequestController::class, 'indexForRT']);
            Route::post('/{id}/approve-rt', [LetterRequestController::class, 'approveForRT']);
            Route::post('/{id}/reject-rt', [LetterRequestController::class, 'rejectForRT']);
        });

        Route::middleware('role:ketua_rw')->group(function () {
            Route::get('/rw/pending', [LetterRequestController::class, 'indexForRW']);
            Route::post('/{id}/approve-rw', [LetterRequestController::class, 'approveForRW']);
            Route::post('/{id}/reject-rw', [LetterRequestController::class, 'rejectForRW']);
        });
    });

    Route::prefix('financial')->group(function () {
        Route::get('/transactions', [FinancialTransactionController::class, 'index']);
        Route::post('/transactions', [FinancialTransactionController::class, 'store']);
        Route::get('/summary', [FinancialTransactionController::class, 'summary']);
    });

    Route::prefix('dues')->group(function () {
        Route::get('/billings', [DuesBillingController::class, 'index']);
        Route::post('/payments', [DuesBillingController::class, 'recordPayment']);
    });
});

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});
