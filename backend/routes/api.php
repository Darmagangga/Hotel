<?php

use App\Http\Controllers\Api\CoaAccountController;
use App\Http\Controllers\Api\JournalController;
use App\Http\Controllers\Api\BookingAddonController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RoomTypeController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\NightAuditController;
use App\Http\Controllers\Api\SeedController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\TransportController;
use App\Http\Controllers\Api\ActivityCatalogController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\AccountingSyncController;
use App\Http\Controllers\Api\AuditTrailController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\RoleController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('pms.permission:coa')->group(function () {
    Route::get('/coa-accounts', [CoaAccountController::class, 'index']);
    Route::post('/coa-accounts', [CoaAccountController::class, 'store']);
    Route::put('/coa-accounts/{coaAccount}', [CoaAccountController::class, 'update']);
});

Route::middleware('pms.permission:rooms')->group(function () {
    Route::get('/room-types', [RoomTypeController::class, 'index']);
    Route::get('/rooms', [RoomController::class, 'index']);
    Route::post('/rooms', [RoomController::class, 'store']);
    Route::put('/rooms/{room}', [RoomController::class, 'update']);
});

Route::middleware('pms.permission:bookings')->group(function () {
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::put('/bookings/{booking}', [BookingController::class, 'update']);
    Route::patch('/bookings/{booking}/status', [BookingController::class, 'updateStatus']);
    Route::post('/bookings/{booking}/addons', [BookingAddonController::class, 'store']);
    Route::patch('/bookings/{booking}/addons/{bookingAddon}', [BookingAddonController::class, 'update']);
    Route::delete('/bookings/{booking}/addons/{bookingAddon}', [BookingAddonController::class, 'destroy']);
});

Route::middleware('pms.permission:journals')->group(function () {
    Route::get('/journals', [JournalController::class, 'index']);
    Route::post('/journals', [JournalController::class, 'store']);
    Route::put('/journals/{journal}', [JournalController::class, 'update']);
});

Route::middleware('pms.permission:finance')->group(function () {
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
});

Route::middleware('pms.permission:inventory')->group(function () {
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::post('/inventory/items', [InventoryController::class, 'storeItem']);
    Route::put('/inventory/items/{item}', [InventoryController::class, 'updateItem']);
    Route::get('/master-units', [InventoryController::class, 'listUnits']);
    Route::post('/master-units', [InventoryController::class, 'storeUnit']);
    Route::put('/master-units/{id}', [InventoryController::class, 'updateUnit']);
    Route::delete('/master-units/{id}', [InventoryController::class, 'deleteUnit']);
    Route::post('/inventory/purchases', [InventoryController::class, 'storePurchase']);
    Route::post('/inventory/issues', [InventoryController::class, 'storeIssue']);
});

Route::middleware('pms.permission:transport')->group(function () {
    Route::get('/transport-rates', [TransportController::class, 'index']);
    Route::post('/transport-rates', [TransportController::class, 'store']);
    Route::put('/transport-rates/{id}', [TransportController::class, 'update']);
});

Route::middleware('pms.permission:activities')->group(function () {
    Route::get('/activity-catalog', [ActivityCatalogController::class, 'index']);
    Route::post('/activity-catalog/scooters', [ActivityCatalogController::class, 'storeScooter']);
    Route::put('/activity-catalog/scooters/{id}', [ActivityCatalogController::class, 'updateScooter']);
    Route::post('/activity-catalog/operators', [ActivityCatalogController::class, 'storeOperator']);
    Route::put('/activity-catalog/operators/{id}', [ActivityCatalogController::class, 'updateOperator']);
    Route::post('/activity-catalog/island-tours', [ActivityCatalogController::class, 'storeIslandTour']);
    Route::put('/activity-catalog/island-tours/{id}', [ActivityCatalogController::class, 'updateIslandTour']);
    Route::post('/activity-catalog/boat-tickets', [ActivityCatalogController::class, 'storeBoatTicket']);
    Route::put('/activity-catalog/boat-tickets/{id}', [ActivityCatalogController::class, 'updateBoatTicket']);
});

Route::middleware('pms.permission:reports')->group(function () {
    Route::get('/reports/balance-sheet', [ReportController::class, 'balanceSheet']);
    Route::get('/reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow']);
    Route::get('/reports/reconciliation', [AccountingSyncController::class, 'reconciliationReport']);
    Route::get('/audit-trails', [AuditTrailController::class, 'index']);
});

Route::middleware('pms.permission:dashboard')->group(function () {
    Route::get('/dashboard/owner', [DashboardController::class, 'owner']);
    Route::get('/night-audit/status', [NightAuditController::class, 'status']);
});

Route::middleware('pms.permission:settings')->group(function () {
    Route::get('/settings/policies', [SettingsController::class, 'policies']);
    Route::put('/settings/policies', [SettingsController::class, 'updatePolicies']);
    Route::post('/settings/reset-transactions', [SettingsController::class, 'resetTransactions']);
});

Route::middleware('pms.permission:roles')->group(function () {
    Route::post('/night-audit', [NightAuditController::class, 'trigger']);
    Route::post('/accounting/sync-history', [AccountingSyncController::class, 'syncHistoricalData']);
    Route::get('/roles', [RoleController::class, 'index']);
    Route::put('/roles/{id}/permissions', [RoleController::class, 'updatePermissions']);
});

Route::middleware('pms.permission:users')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::put('/users/{id}', [UserController::class, 'update']);
    Route::patch('/users/{id}/toggle', [UserController::class, 'toggleStatus']);
});

Route::middleware('pms.permission:roles')->group(function () {
    Route::get('/seed-dummy', [SeedController::class, 'seedNightAudit']);
});
