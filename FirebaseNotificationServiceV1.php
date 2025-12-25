<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FirebaseNotificationServiceV1
{
    private $messaging;

    public function __construct()
    {
        try {
            $credentialsPath = storage_path('app/firebase-service-account.json');
            
            if (!file_exists($credentialsPath)) {
                Log::error('Firebase service account file not found: ' . $credentialsPath);
                $this->messaging = null;
                return;
            }

            $factory = (new Factory)->withServiceAccount($credentialsPath);
            $this->messaging = $factory->createMessaging();
        } catch (\Exception $e) {
            Log::error('Error initializing Firebase: ' . $e->getMessage());
            $this->messaging = null;
        }
    }

    /**
     * Send FCM notification to user using V1 API
     * 
     * @param int $userId User ID yang akan menerima notifikasi
     * @param string $title Judul notifikasi
     * @param string $message Pesan notifikasi
     * @param array $data Data tambahan (product_id, comment_id, dll)
     * @return bool
     */
    public function sendNotification($userId, $title, $message, $data = [])
    {
        try {
            if (!$this->messaging) {
                Log::error('Firebase messaging not initialized');
                return false;
            }

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
                ->withHighPriority();

            $this->messaging->send($message);

            Log::info("FCM notification sent successfully to user {$userId} (V1 API)");
            return true;
        } catch (\Exception $e) {
            Log::error("Error sending FCM notification (V1 API): " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
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

