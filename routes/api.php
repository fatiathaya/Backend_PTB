<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Public product routes
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show'])->where('id', '[0-9]+'); // Public access, only numeric ID

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user', [AuthController::class, 'updateUser']);
    
    // Products routes - IMPORTANT: Specific routes MUST come before parameterized routes
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/my-products', [ProductController::class, 'getMyProducts']);
    Route::get('/products/favorites', [ProductController::class, 'getFavorites']); // Must be before any /products/{id} in protected
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/products/{id}/favorite', [ProductController::class, 'toggleFavorite']);
    
    // Addresses routes (you'll need to create AddressController)
    // Route::apiResource('addresses', AddressController::class);
});

