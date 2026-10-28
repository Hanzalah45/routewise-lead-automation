<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MockCrmAdminController;
use App\Http\Controllers\OwnerController;
use App\Http\Controllers\ScoringController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login.attempt');
    Route::post('/demo-login', [AuthController::class, 'demoLogin'])->middleware('throttle:20,1')->name('demo.login');
});

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/leads/{lead:uuid}', [LeadController::class, 'show'])->name('leads.show');
    Route::post('/leads/{lead:uuid}/retry', [LeadController::class, 'retry'])->middleware('throttle:30,1')->name('leads.retry');
    Route::post('/leads/{lead:uuid}/assign', [LeadController::class, 'assign'])->name('leads.assign');
    Route::post('/leads/{lead:uuid}/spam', [LeadController::class, 'spam'])->name('leads.spam');
    Route::post('/leads/{lead:uuid}/rescore', [LeadController::class, 'rescore'])->name('leads.rescore');

    Route::get('/owners', [OwnerController::class, 'index'])->name('owners.index');
    Route::post('/owners', [OwnerController::class, 'store'])->name('owners.store');
    Route::put('/owners/{owner}', [OwnerController::class, 'update'])->name('owners.update');

    Route::get('/scoring', [ScoringController::class, 'index'])->name('scoring.index');
    Route::put('/scoring', [ScoringController::class, 'update'])->name('scoring.update');
    Route::post('/scoring/simulate', [ScoringController::class, 'simulate'])->middleware('throttle:60,1')->name('scoring.simulate');

    Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
    Route::post('/sources', [SourceController::class, 'store'])->name('sources.store');
    Route::post('/sources/{source}/rotate', [SourceController::class, 'rotate'])->name('sources.rotate');
    Route::post('/sources/{source}/toggle', [SourceController::class, 'toggle'])->name('sources.toggle');
    Route::post('/sources/{source}/test', [SourceController::class, 'test'])->middleware('throttle:20,1')->name('sources.test');

    Route::get('/deliveries', [LogController::class, 'deliveries'])->name('deliveries.index');
    Route::get('/outbox', [LogController::class, 'outbox'])->name('outbox.index');

    Route::get('/mock-crm', [MockCrmAdminController::class, 'index'])->name('mock-crm.index');
    Route::put('/mock-crm', [MockCrmAdminController::class, 'update'])->name('mock-crm.update');
});
