<?php

use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\CodeSetController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MyAiController;
use App\Http\Controllers\ReportDraftController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'landing'])->name('landing');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SsoController::class, 'showLogin'])->name('login');
    Route::get('/auth/sso', [SsoController::class, 'redirect'])->name('sso.redirect');
    Route::get('/auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
    Route::post('/auth/dev-login', [SsoController::class, 'devLogin'])->name('dev.login');
});

Route::middleware('auth')->group(function () {
    Route::get('/home', [HomeController::class, 'home'])->name('home');
    Route::post('/logout', [SsoController::class, 'logout'])->name('logout');

    Route::get('/datasets/create', [DatasetController::class, 'create'])->name('datasets.create');
    Route::post('/datasets', [DatasetController::class, 'store'])->name('datasets.store');
    Route::get('/datasets/{dataset}', [DatasetController::class, 'show'])->name('datasets.show');
    Route::get('/datasets/{dataset}/sheets', [DatasetController::class, 'sheets'])->name('datasets.sheets');
    Route::post('/datasets/{dataset}/import', [DatasetController::class, 'import'])->name('datasets.import');
    Route::put('/datasets/{dataset}/columns', [DatasetController::class, 'updateColumns'])->name('datasets.columns.update');
    Route::delete('/datasets/{dataset}', [DatasetController::class, 'destroy'])->name('datasets.destroy');
    Route::get('/datasets/{dataset}/excel-template', [ExcelTemplateController::class, 'fromDataset'])->name('datasets.excel-template');

    Route::get('/excel-templates', [ExcelTemplateController::class, 'index'])->name('excel-templates.index');
    Route::post('/datasets/{dataset}/drafts', [ReportDraftController::class, 'start'])->name('drafts.start');
    Route::get('/drafts/{draft}', [ReportDraftController::class, 'show'])->name('drafts.show');
    Route::post('/drafts/{draft}/answers', [ReportDraftController::class, 'answer'])->name('drafts.answer');
    Route::post('/drafts/{draft}/retry', [ReportDraftController::class, 'retry'])->name('drafts.retry');
    Route::get('/dashboards/{dashboard}', [DashboardController::class, 'show'])->name('dashboards.show');

    Route::get('/me/ai', [MyAiController::class, 'show'])->name('me.ai');
    Route::post('/me/ai', [MyAiController::class, 'store'])->name('me.ai.store')->middleware('throttle:10,1');
    Route::put('/me/ai/budget', [MyAiController::class, 'updateBudget'])->name('me.ai.budget');
    Route::delete('/me/ai', [MyAiController::class, 'destroy'])->name('me.ai.destroy');
    Route::get('/excel-templates/{key}/download', [ExcelTemplateController::class, 'download'])->name('excel-templates.download');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

        Route::get('/ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
        Route::put('/ai', [AiSettingsController::class, 'update'])->name('ai.update');
        Route::post('/ai/test', [AiSettingsController::class, 'test'])->name('ai.test');
        Route::post('/ai/credentials/{credential}/revoke', [AiSettingsController::class, 'revoke'])->name('ai.revoke');

        Route::get('/codes', [CodeSetController::class, 'index'])->name('codes.index');
        Route::post('/codes', [CodeSetController::class, 'store'])->name('codes.store');
        Route::get('/codes/{codeSet}', [CodeSetController::class, 'show'])->name('codes.show');
        Route::put('/codes/{codeSet}', [CodeSetController::class, 'update'])->name('codes.update');
        Route::post('/codes/{codeSet}/values', [CodeSetController::class, 'storeValue'])->name('codes.values.store');
        Route::put('/codes/{codeSet}/values/{value}', [CodeSetController::class, 'updateValue'])->name('codes.values.update');
        Route::delete('/codes/{codeSet}/values/{value}', [CodeSetController::class, 'destroyValue'])->name('codes.values.destroy');
        Route::post('/codes/{codeSet}/import', [CodeSetController::class, 'import'])->name('codes.import');
        Route::post('/codes/{codeSet}/rules', [CodeSetController::class, 'storeRule'])->name('codes.rules.store');
        Route::delete('/codes/{codeSet}/rules/{rule}', [CodeSetController::class, 'destroyRule'])->name('codes.rules.destroy');
    });
});
