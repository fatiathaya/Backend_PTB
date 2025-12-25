<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseNotificationService
{
    private $useV1Api = false;
    private $messaging = null;

    public function __construct()
    {
        // Try to use V1 API if service account exists
        $serviceAccountPath = storage_path('app/firebase-service-account.json');
        if (file_exists($serviceAccountPath)) {
            try {
                $factory = (new Factory)->withServiceAccount($serviceAccountPath);
                $this->messaging = $factory->createMessaging();
                $this->useV1Api = true;
                Log::info('Using Firebase V1 API');
            } catch (\Exception $e) {
                Log::warning('Failed to initialize V1 API, falling back to Legacy API: ' . $e->getMessage());
            }
        }
    }

    /**
     * Send FCM notification to user
     * 
     * @param int $userId User ID yang akan menerima notifikasi
     * @param string $title Judul notifikasi
     * @param string $message Pesan notifikasi
     * @param array $data Data tambahan (product_id, comment_id, dll)
     * @return bool
     */
    public function sendNotification($userId, $title, $message, $data = [])
    {
        // Try V1 API first if available
        if ($this->useV1Api && $this->messaging) {
            return $this->sendNotificationV1($userId, $title, $message, $data);
        }
        
        // Fallback to Legacy API
        return $this->sendNotificationLegacy($userId, $title, $message, $data);
    }

    /**
     * Send notification using V1 API
     */
    private function sendNotificationV1($userId, $title, $message, $data = [])
    {
        try {
            $user = User::find($userId);
            
            if (!$user || !$user->fcm_token) {
                Log::warning("User {$userId} tidak memiliki FCM token");
                return false;
            }

            $notification = Notification::create($title, $message);
            
            $messageData = array_merge([
                'title' => $title,
                'message' => $message,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ], $data);

            $message = CloudMessage::withTarget('token', $user->fcm_token)
                ->withNotification($notification)
                ->withData($messageData)
                ->withAndroidConfig([
                    'priority' => 'high'
                ]);

            $this->messaging->send($message);

            Log::info("FCM notification sent successfully to user {$userId} (V1 API)");
            return true;
        } catch (\Exception $e) {
            Log::error("Error sending FCM notification (V1 API): " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            // Fallback to Legacy API
            return $this->sendNotificationLegacy($userId, $title, $message, $data);
        }
    }

    /**
     * Send notification using Legacy API
     */
    private function sendNotificationLegacy($userId, $title, $message, $data = [])
    {
        try {
            $user = User::find($userId);
            
            if (!$user || !$user->fcm_token) {
                Log::warning("User {$userId} tidak memiliki FCM token");
                return false;
            }

            $serverKey = env('FIREBASE_SERVER_KEY');
            
            if (!$serverKey) {
                Log::error('FIREBASE_SERVER_KEY tidak ditemukan di .env');
                Log::error('Silakan tambahkan FIREBASE_SERVER_KEY di file .env atau setup V1 API dengan service account');
                return false;
            }

            // Validate server key format (should start with specific pattern)
            if (strlen($serverKey) < 50) {
                Log::error('FIREBASE_SERVER_KEY tampaknya tidak valid (terlalu pendek)');
                return false;
            }

            $url = 'https://fcm.googleapis.com/fcm/send';
            
            // FCM Legacy API format (compatible with all Android versions)
            $payload = [
                'to' => $user->fcm_token,
                'notification' => [
                    'title' => $title,
                    'body' => $message,
                    'sound' => 'default',
                ],
                'data' => array_merge([
                    'title' => $title,
                    'message' => $message,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ], $data),
                'priority' => 'high',
                'content_available' => true,
            ];
            
            Log::info("Sending FCM notification to user {$userId} (Legacy API)", [
                'fcm_token' => substr($user->fcm_token, 0, 20) . '...',
                'title' => $title,
                'message' => $message,
                'server_key_preview' => substr($serverKey, 0, 20) . '...'
            ]);

            try {
                $response = Http::timeout(10)->withHeaders([
                    'Authorization' => 'key=' . $serverKey,
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                if ($response->successful()) {
                    Log::info("FCM notification sent successfully to user {$userId} (Legacy API)");
                    return true;
                } else {
                    $statusCode = $response->status();
                    $responseBody = $response->body();
                    
                    Log::error("Failed to send FCM notification - Status: {$statusCode}");
                    Log::error("Response body: " . substr($responseBody, 0, 500));
                    
                    // Check for specific error codes
                    if ($statusCode == 401) {
                        Log::error('FIREBASE_SERVER_KEY tidak valid atau expired. Silakan cek Firebase Console atau migrate ke V1 API.');
                    } elseif ($statusCode == 404) {
                        Log::error('FCM endpoint tidak ditemukan. Legacy API mungkin sudah deprecated. Silakan migrate ke V1 API.');
                    }
                    
                    return false;
                }
            } catch (\Exception $e) {
                Log::error("Exception sending FCM notification: " . $e->getMessage());
                Log::error("Stack trace: " . $e->getTraceAsString());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error sending FCM notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send notification when a comment is created
     */
    public function sendCommentNotification($productOwnerId, $commenterName, $productName, $productId, $commentId)
    {
        $title = 'Postingan Anda Dikomentari';
        $message = "{$commenterName} mengomentari postingan Anda";
        
        $data = [
            'type' => 'comment',
            'product_id' => (string)$productId,
            'comment_id' => (string)$commentId,
        ];

        return $this->sendNotification($productOwnerId, $title, $message, $data);
    }
}

