<?php

use App\Http\Controllers\Flower\FlowerController;
use App\Http\Controllers\Flower\FlowerActionController;

// All route names are prefixed with 'admin.auth'.
Route::group([
    'prefix' => 'flower',
    'as' => 'flower.',
    'namespace' => 'Flower',
], function () {
    // User Management
    Route::group(['namespace' => 'Flower'], function () {
        // User Status'
        Route::get('user/deactivated', [FlowerActionController::class, 'getDeactivated'])->name('flower_rate.deactivated');
        Route::get('user/deleted', [FlowerActionController::class, 'getDeleted'])->name('flower_rate.deleted');

        // User CRUD
        Route::get('user', [FlowerController::class, 'index'])->name('flower_rate.index');
        Route::get('user/create', [FlowerController::class, 'create'])->name('flower_rate.create');
        Route::post('user', [FlowerController::class, 'store'])->name('flower_rate.store');

        // Specific User
        Route::group(['prefix' => 'user/{user}'], function () {
            // User
            Route::get('/', [FlowerController::class, 'show'])->name('flower_rate.show');
            Route::get('edit', [FlowerController::class, 'edit'])->name('flower_rate.edit');
            Route::patch('/', [FlowerController::class, 'update'])->name('flower_rate.update');
            Route::delete('/', [FlowerController::class, 'destroy'])->name('flower_rate.destroy');

            // Status
            Route::get('mark/{status}', [FlowerActionController::class, 'mark'])->name('flower_rate.mark')->where(['status' => '[0,1]']);

            // Deleted
            Route::get('delete', [FlowerActionController::class, 'delete'])->name('flower_rate.delete-permanently');
            Route::get('restore', [FlowerActionController::class, 'restore'])->name('flower_rate.restore');
        });
    });

    // Role Management
    Route::group(['namespace' => 'Role'], function () {

        Route::group(['prefix' => 'role/{role}'], function () {
      
        });
    });
});
