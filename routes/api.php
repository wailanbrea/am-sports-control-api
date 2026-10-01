<?php

use App\Http\Controllers\Api\V1\AccountingReadController;
use App\Http\Controllers\Api\V1\AdvanceController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CashBoxController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\LedgerEntryController;
use App\Http\Controllers\Api\V1\ManualResultController;
use App\Http\Controllers\Api\V1\MoneyDeliveryController;
use App\Http\Controllers\Api\V1\WeeklySettlementController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function () {
    // Read-only & operational endpoints accessible to both Admin & Collector
    Route::get('/branches', [AccountingReadController::class, 'branches']);
    Route::get('/branches/{branch}', [AccountingReadController::class, 'branch']);
    Route::get('/collections', [CollectionController::class, 'index']);
    Route::post('/collections', [CollectionController::class, 'store']); // Cobros: permitido para cobradores y admin
    Route::get('/dashboard', [AccountingReadController::class, 'dashboard']);

    // Admin-only endpoints (Los cobradores no tienen permiso de modificar nada ni de ver caja chica)
    Route::middleware('admin')->group(function () {
        Route::get('/cash-box', [CashBoxController::class, 'show']);
        Route::get('/cash-boxes', [CashBoxController::class, 'indexBoxes']);
        Route::post('/cash-boxes', [CashBoxController::class, 'storeBox']);
        Route::put('/collections/{collection}', [CollectionController::class, 'update']);
        Route::post('/branches', [BranchController::class, 'store']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
        Route::delete('/branches/{branch}', [BranchController::class, 'destroy']);
        Route::post('/branches/{branch}/absorb-loss', [BranchController::class, 'absorbLoss']);
        Route::get('/ledger', [AccountingReadController::class, 'ledger']);
        Route::post('/cash-box/income', [CashBoxController::class, 'income']);
        Route::post('/cash-box/expenses', [CashBoxController::class, 'expense']);
        Route::post('/cash-box/branch-transfers', [CashBoxController::class, 'branchTransfer']);
        Route::get('/advances', [AdvanceController::class, 'index']);
        Route::post('/advances', [AdvanceController::class, 'store']);
        Route::get('/results', [ManualResultController::class, 'index']);
        Route::post('/results', [ManualResultController::class, 'store']);
        Route::get('/money-deliveries', [MoneyDeliveryController::class, 'index']);
        Route::post('/money-deliveries', [MoneyDeliveryController::class, 'store']);
        Route::get('/weekly-settlements', [WeeklySettlementController::class, 'index']);
        Route::post('/weekly-settlements', [WeeklySettlementController::class, 'store']);
        Route::get('/collectors', [\App\Http\Controllers\Api\V1\CollectorController::class, 'index']);
        Route::post('/collectors', [\App\Http\Controllers\Api\V1\CollectorController::class, 'store']);
        Route::put('/collectors/{collector}', [\App\Http\Controllers\Api\V1\CollectorController::class, 'update']);
        Route::post('/ledger-entries/{ledgerEntry}/reverse', [LedgerEntryController::class, 'reverse']);
    });
});
