<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\PhotoModerationController;
use App\Http\Controllers\Admin\ReferenceDataController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Luvhere CRM API  (served under /api)
|--------------------------------------------------------------------------
| Roles: super_admin (everything) · admin · moderator · support.
| `admin.role:x,y` lets those roles (and always super_admin) through.
*/

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'admin.role'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/password', [AuthController::class, 'changePassword']);

    // Read access for every role
    Route::get('/overview', [DashboardController::class, 'overview']);
    Route::get('/analytics', [DashboardController::class, 'analytics']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/{id}', [UserController::class, 'show'])->whereNumber('id');
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/blocks', [ReportController::class, 'blocks']);
    Route::get('/verifications', [VerificationController::class, 'index']);
    Route::get('/photos', [PhotoModerationController::class, 'index']);
    Route::get('/reference/{type}', [ReferenceDataController::class, 'index']);
    Route::get('/broadcasts', [BroadcastController::class, 'index']);

    // Moderation
    Route::middleware('admin.role:admin,moderator')->group(function () {
        Route::put('/users/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::post('/users/{id}/action', [UserController::class, 'action'])->whereNumber('id');
        Route::post('/photos/review', [PhotoModerationController::class, 'review']);
        Route::put('/reports/{id}', [ReportController::class, 'update'])->whereNumber('id');
        Route::delete('/blocks/{id}', [ReportController::class, 'removeBlock'])->whereNumber('id');
        Route::post('/verifications/{id}/decision', [VerificationController::class, 'decide'])->whereNumber('id');
    });

    // Support can message a single user
    Route::post('/users/{id}/notify', [UserController::class, 'notify'])->whereNumber('id')->middleware('admin.role:admin,moderator,support');

    // Content & growth
    Route::middleware('admin.role:admin')->group(function () {
        Route::post('/reference/{type}', [ReferenceDataController::class, 'store']);
        Route::put('/reference/{type}/{id}', [ReferenceDataController::class, 'update'])->whereNumber('id');
        Route::delete('/reference/{type}/{id}', [ReferenceDataController::class, 'destroy'])->whereNumber('id');
        Route::get('/settings', [SettingController::class, 'show']);
        Route::put('/settings', [SettingController::class, 'update']);
        Route::get('/integrations', [IntegrationController::class, 'show']);
        Route::put('/integrations', [IntegrationController::class, 'update']);
        Route::post('/broadcasts/preview', [BroadcastController::class, 'preview']);
        Route::post('/broadcasts', [BroadcastController::class, 'send']);
    });

    // Staff management + audit log
    Route::middleware('admin.role:super_admin')->group(function () {
        Route::get('/admins', [AdminUserController::class, 'index']);
        Route::post('/admins', [AdminUserController::class, 'store']);
        Route::put('/admins/{id}', [AdminUserController::class, 'update'])->whereNumber('id');
        Route::delete('/admins/{id}', [AdminUserController::class, 'destroy'])->whereNumber('id');
        Route::get('/audit', [AdminUserController::class, 'audit']);
    });
});
