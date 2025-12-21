<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SearchHistoryController;
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
    Route::delete('/user/profile-image', [AuthController::class, 'deleteProfileImage']);
    
    // Products routes - IMPORTANT: Specific routes MUST come before parameterized routes
    Route::post('/products', [ProductController::class, 'store']);
    Route::get('/products/my-products', [ProductController::class, 'getMyProducts']);
    Route::get('/products/favorites', [ProductController::class, 'getFavorites']); // Must be before any /products/{id} in protected
    Route::put('/products/{id}', [ProductController::class, 'update']);
    Route::delete('/products/{id}', [ProductController::class, 'destroy']);
    Route::post('/products/{id}/favorite', [ProductController::class, 'toggleFavorite']);
    Route::delete('/products/{productId}/images/{imageId}', [ProductController::class, 'deleteProductImage']);
    
    // Search History routes
    Route::get('/search-history', [SearchHistoryController::class, 'index']);
    Route::post('/search-history', [SearchHistoryController::class, 'store']);
    Route::delete('/search-history/{id}', [SearchHistoryController::class, 'destroy']);
    Route::delete('/search-history', [SearchHistoryController::class, 'clear']);
    
    // Addresses routes (you'll need to create AddressController)
    // Route::apiResource('addresses', AddressController::class);
});

