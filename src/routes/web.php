<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\DatasetController;
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

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});
