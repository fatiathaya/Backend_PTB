<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TestNotificationController extends Controller
{
    /**
     * Test send push notification to current user
     * 
     * POST /api/test/notification
     * Headers: Authorization: Bearer {token}
     * Body: {
     *   "title": "Test Notification",
     *   "message": "Ini adalah test notification"
     * }
     */
    public function sendTestNotification(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'message' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            if (!$user->fcm_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak memiliki FCM token. Pastikan sudah login dan FCM token tersimpan.',
                    'user_id' => $user->id,
                    'user_name' => $user->name
                ], 400);
            }

            $title = $request->input('title', 'Test Notification');
            $message = $request->input('message', 'Ini adalah test push notification dari backend');

            $fcmService = new FirebaseNotificationService();
            $result = $fcmService->sendNotification(
                $user->id,
                $title,
                $message,
                [
                    'type' => 'test',
                    'test_id' => time()
                ]
            );

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification berhasil dikirim',
                    'data' => [
                        'user_id' => $user->id,
                        'user_name' => $user->name,
                        'title' => $title,
                        'message' => $message,
                        'fcm_token' => substr($user->fcm_token, 0, 30) . '...'
                    ]
                ]);
            } else {
                // Check if FIREBASE_SERVER_KEY exists
                $serverKey = env('FIREBASE_SERVER_KEY');
                $errorMessage = 'Gagal mengirim test notification. ';
                
                if (!$serverKey) {
                    $errorMessage .= 'FIREBASE_SERVER_KEY tidak ditemukan di .env. Silakan tambahkan FIREBASE_SERVER_KEY di file .env.';
                } else {
                    $errorMessage .= 'Cek Laravel log untuk detail. Kemungkinan FIREBASE_SERVER_KEY tidak valid atau expired.';
                }
                
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                    'user_id' => $user->id,
                    'fcm_token_exists' => !empty($user->fcm_token),
                    'firebase_server_key_exists' => !empty($serverKey),
                    'hint' => 'Cek file storage/logs/laravel.log untuk detail error'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error sending test notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get current user FCM token status
     * 
     * GET /api/test/fcm-token
     * Headers: Authorization: Bearer {token}
     */
    public function getFcmTokenStatus(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'has_fcm_token' => !empty($user->fcm_token),
                    'fcm_token_preview' => $user->fcm_token ? substr($user->fcm_token, 0, 30) . '...' : null,
                    'fcm_token_length' => $user->fcm_token ? strlen($user->fcm_token) : 0
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send test notification to specific user (for admin/testing)
     * 
     * POST /api/test/notification/{userId}
     * Headers: Authorization: Bearer {token}
     * Body: {
     *   "title": "Test Notification",
     *   "message": "Ini adalah test notification"
     * }
     */
    public function sendTestNotificationToUser(Request $request, $userId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'nullable|string|max:255',
                'message' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $targetUser = User::find($userId);
            
            if (!$targetUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            if (!$targetUser->fcm_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak memiliki FCM token',
                    'user_id' => $targetUser->id,
                    'user_name' => $targetUser->name
                ], 400);
            }

            $title = $request->input('title', 'Test Notification');
            $message = $request->input('message', 'Ini adalah test push notification');

            $fcmService = new FirebaseNotificationService();
            $result = $fcmService->sendNotification(
                $targetUser->id,
                $title,
                $message,
                [
                    'type' => 'test',
                    'test_id' => time()
                ]
            );

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => 'Test notification berhasil dikirim',
                    'data' => [
                        'user_id' => $targetUser->id,
                        'user_name' => $targetUser->name,
                        'title' => $title,
                        'message' => $message
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengirim test notification'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error sending test notification: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}

