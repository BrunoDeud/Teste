<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\ClaimController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\AdminController;

Route::middleware('throttle:api')->group(function () {
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/auth/resend-verification', [AuthController::class, 'resendVerification']);

    Route::get('/categories', [DirectoryController::class, 'categories']);
    Route::get('/locations', [DirectoryController::class, 'locations']);
    Route::get('/guard-points', [DirectoryController::class, 'guardPoints']);
    Route::get('/items', [ItemController::class, 'index']);
    Route::get('/items/{id}', [ItemController::class, 'show'])->whereUuid('id');

    Route::middleware(['auth:sanctum','active'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::patch('/auth/preferences', [AuthController::class, 'preferences']);

        Route::post('/items', [ItemController::class, 'store']);
        Route::get('/items/{id}/verification-question', [ItemController::class, 'verificationQuestion'])->whereUuid('id');
        Route::patch('/items/{id}', [ItemController::class, 'update'])->whereUuid('id');
        Route::post('/items/{id}/claims', [ItemController::class, 'claim'])->whereUuid('id');
        Route::post('/items/{id}/reports', [ReportController::class, 'store'])->whereUuid('id');
        Route::get('/my-items', [ClaimController::class, 'myItems']);
        Route::patch('/claims/{id}/decision', [ClaimController::class, 'decide'])->whereUuid('id');
        Route::get('/claims/{id}/messages', [ClaimController::class, 'messages'])->whereUuid('id');
        Route::post('/claims/{id}/messages', [ClaimController::class, 'messages'])->whereUuid('id');
        Route::post('/claims/{id}/returned', [ClaimController::class, 'returned'])->whereUuid('id');

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll']);

        Route::prefix('moderation')->middleware('role:MODERADOR,ADMINISTRADOR')->group(function () {
            Route::get('/reports', [ModerationController::class, 'reports']);
            Route::get('/claims', [ModerationController::class, 'claims']);
            Route::patch('/reports/{id}', [ModerationController::class, 'resolveReport'])->whereUuid('id');
            Route::get('/log', [ModerationController::class, 'log']);
            Route::post('/guard-items', [ItemController::class, 'store']);
        });

        Route::prefix('admin')->middleware('role:ADMINISTRADOR')->group(function () {
            Route::get('/categories', [AdminController::class, 'categories']);
            Route::post('/categories', [AdminController::class, 'createCategory']);
            Route::patch('/categories/{id}', [AdminController::class, 'updateCategory'])->whereUuid('id');
            Route::get('/locations', [AdminController::class, 'locations']);
            Route::post('/locations', [AdminController::class, 'createLocation']);
            Route::patch('/locations/{id}', [AdminController::class, 'updateLocation'])->whereUuid('id');
            Route::get('/guard-points', [AdminController::class, 'guardPoints']);
            Route::post('/guard-points', [AdminController::class, 'createGuardPoint']);
            Route::patch('/guard-points/{id}', [AdminController::class, 'updateGuardPoint'])->whereUuid('id');
            Route::get('/users', [AdminController::class, 'users']);
            Route::patch('/users/{id}/role', [AdminController::class, 'updateUserRole'])->whereUuid('id');
            Route::patch('/users/{id}/status', [AdminController::class, 'updateUserStatus'])->whereUuid('id');
            Route::get('/domains', [AdminController::class, 'domains']);
            Route::post('/domains', [AdminController::class, 'createDomain']);
            Route::patch('/domains/{id}', [AdminController::class, 'updateDomain'])->whereNumber('id');
        });
    });
});
