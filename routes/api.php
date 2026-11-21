<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\SiteExplorerController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\PortalProfileController;
use App\Http\Middleware\EnsurePortalReady;
Route::get('/company', [CompanyController::class,'index']);
Route::get('/company-photo/{file}', [CompanyController::class,'photo']);
Route::post('/careers/apply', [CompanyController::class,'apply'])->middleware('throttle:5,1');
Route::middleware(['auth:sanctum', EnsurePortalReady::class, 'permission:company.manage,careers.manage,users.manage'])->prefix('admin')->group(function(){
 Route::get('/company',[CompanyController::class,'index']);
 Route::post('/company',[CompanyController::class,'save']);
 Route::patch('/company/{id}/placement',[CompanyController::class,'place']);
 Route::put('/company/{id}',[CompanyController::class,'save']);
 Route::delete('/company/{id}',[CompanyController::class,'destroy']);
 Route::post('/company-image',[CompanyController::class,'upload'])->middleware('permission:company.manage,users.manage');
 Route::get('/applications',[CompanyController::class,'applications'])->middleware('permission:careers.manage,users.manage');
 Route::patch('/applications/{id}',[CompanyController::class,'application'])->middleware('permission:careers.manage,users.manage');
 Route::get('/applications/{id}/resume',[CompanyController::class,'resume'])->middleware('permission:careers.manage,users.manage');
});

Route::get('/seo/{slug}', [SeoController::class, 'show']);
Route::get('/site-explorer', [SiteExplorerController::class, 'show']);
Route::get('/directory', [DirectoryController::class, 'index']);
Route::post('/directory', [DirectoryController::class, 'store']);
Route::post('/leads/{type}', [LeadController::class, 'store']);
Route::post('/chat', [ChatController::class, 'store']);
Route::prefix('nirvana')->middleware('throttle:24,1,nirvana:')->group(function () {
    Route::get('/google', [\App\Http\Controllers\Api\NirvanaGoogleController::class, 'configuration']);
    Route::post('/google', [\App\Http\Controllers\Api\NirvanaGoogleController::class, 'profile'])->middleware('throttle:5,1,nirvana-google:');
    Route::post('/session', [\App\Http\Controllers\Api\NirvanaController::class, 'start'])->middleware('throttle:5,1,nirvana-start:');
    Route::get('/session', [\App\Http\Controllers\Api\NirvanaController::class, 'show']);
    Route::post('/heartbeat', [\App\Http\Controllers\Api\NirvanaController::class, 'heartbeat']);
    Route::post('/depart', [\App\Http\Controllers\Api\NirvanaController::class, 'depart']);
    Route::post('/message', [\App\Http\Controllers\Api\NirvanaController::class, 'message']);
    Route::post('/rates', [\App\Http\Controllers\Api\NirvanaController::class, 'rate']);
    Route::post('/callback', [\App\Http\Controllers\Api\NirvanaController::class, 'callback'])->middleware('throttle:5,1,nirvana-callback:');
    Route::get('/slots', [\App\Http\Controllers\Api\NirvanaMeetingController::class, 'slots']);
    Route::post('/bookings', [\App\Http\Controllers\Api\NirvanaMeetingController::class, 'book']);
});
Route::post('/nirvana/zoom/webhook', [\App\Http\Controllers\Api\NirvanaMeetingController::class, 'webhook']);

Route::post('/admin/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/me/setup', [PortalProfileController::class, 'setup'])->middleware('throttle:10,1');
    Route::get('/me/avatar', [PortalProfileController::class, 'avatar']);
});

Route::middleware(['auth:sanctum', EnsurePortalReady::class])->prefix('admin')->group(function () {
    Route::post('/me/profile', [PortalProfileController::class, 'profile']);
    Route::patch('/me/password', [PortalProfileController::class, 'password'])->middleware('throttle:10,1');
    Route::get('/site-explorer', [SiteExplorerController::class, 'show'])->middleware('permission:explorer.manage,users.manage');
    Route::put('/site-explorer', [SiteExplorerController::class, 'publish'])->middleware('permission:explorer.manage,users.manage');
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
