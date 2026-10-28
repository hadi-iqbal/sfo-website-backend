<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\SeoController;
use Illuminate\Support\Facades\Route;

Route::get('/seo/{slug}', [SeoController::class, 'show']);
Route::get('/directory', [DirectoryController::class, 'index']);
Route::post('/directory', [DirectoryController::class, 'store']);
Route::post('/leads/{type}', [LeadController::class, 'store']);
Route::post('/chat', [ChatController::class, 'store']);

Route::post('/admin/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->middleware('permission:dashboard.view');

    Route::get('/leads', [LeadController::class, 'index'])->middleware('permission:leads.contact,leads.quote,leads.rate,leads.schedule,leads.chat');
    Route::patch('/leads/{lead}', [LeadController::class, 'update'])->middleware('permission:leads.contact,leads.quote,leads.rate,leads.schedule,leads.chat');

    Route::get('/directory', [DirectoryController::class, 'adminIndex'])->middleware('permission:directory.agent,directory.pnc');
    Route::patch('/directory/{profile}', [DirectoryController::class, 'update'])->middleware('permission:directory.agent,directory.pnc');
    Route::delete('/directory/{profile}', [DirectoryController::class, 'destroy'])->middleware('permission:directory.review');

    Route::get('/chat', [ChatController::class, 'index'])->middleware('permission:chat.view');
    Route::get('/chat/{conversation}', [ChatController::class, 'show'])->middleware('permission:chat.view');
    Route::patch('/chat/{conversation}', [ChatController::class, 'update'])->middleware('permission:chat.view');

    Route::get('/seo', [SeoController::class, 'index'])->middleware('permission:seo.manage');
    Route::put('/seo/{seoPage}', [SeoController::class, 'update'])->middleware('permission:seo.manage');

    Route::get('/permissions', [AdminController::class, 'permissions'])->middleware('permission:users.manage');
    Route::get('/users', [AdminController::class, 'users'])->middleware('permission:users.manage');
    Route::post('/users', [AdminController::class, 'storeUser'])->middleware('permission:users.manage');
    Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->middleware('permission:users.manage');
});
