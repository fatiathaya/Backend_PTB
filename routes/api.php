<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\NotificationController;
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
Route::get('/users/{id}', [AuthController::class, 'getUserProfile'])->where('id', '[0-9]+');

// Public product routes
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show'])->where('id', '[0-9]+'); // Public access, only numeric ID

// Public comments route (read only)
Route::get('/products/{productId}/comments', [CommentController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/user', [AuthController::class, 'updateUser']);
    Route::delete('/user/profile-image', [AuthController::class, 'deleteProfileImage']);
    Route::post('/user/change-password', [AuthController::class, 'changePassword']);
    Route::post('/user/fcm-token', [AuthController::class, 'saveFcmToken']);
    
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
    
    // Comments routes (create, update, delete require authentication)
    Route::post('/products/{productId}/comments', [CommentController::class, 'store']);
    Route::put('/comments/{id}', [CommentController::class, 'update']);
    Route::delete('/comments/{id}', [CommentController::class, 'destroy']);
    
    // Notifications routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    
    // Test notification routes (for testing push notification with single emulator)
    Route::post('/test/notification', [\App\Http\Controllers\TestNotificationController::class, 'sendTestNotification']);
    Route::get('/test/fcm-token', [\App\Http\Controllers\TestNotificationController::class, 'getFcmTokenStatus']);
    Route::post('/test/notification/{userId}', [\App\Http\Controllers\TestNotificationController::class, 'sendTestNotificationToUser']);
    
    // Addresses routes (you'll need to create AddressController)
    // Route::apiResource('addresses', AddressController::class);
});

