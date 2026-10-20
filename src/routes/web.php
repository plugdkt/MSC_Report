<?php

use App\Http\Controllers\Admin\CodeSetController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DatasetController;
use App\Http\Controllers\ExcelTemplateController;
use App\Http\Controllers\HomeController;
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
    Route::get('/excel-templates/{key}/download', [ExcelTemplateController::class, 'download'])->name('excel-templates.download');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');

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
