<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
   
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            Log::info("Fetching notifications for user: {$userId}");

            $notifications = Notification::where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            Log::info("Found {$notifications->count()} notifications for user {$userId}");
            $wishlistCount = $notifications->where('type', 'wishlist')->count();
            Log::info("Found {$wishlistCount} wishlist notifications for user {$userId}");

            $mappedNotifications = $notifications->map(function ($notification) {
                try {
                    $productImage = null;
                    $productName = null;
                    $commentText = null;

                    
                    if ($notification->product_id) {
                    try {
                        $product = \App\Models\Product::find($notification->product_id);
                        if ($product) {
                            $productName = $product->name;
                            
                            $firstImage = \App\Models\ProductImage::where('product_id', $product->id)
                                ->orderBy('sort_order')
                                ->first();
                            
                            if ($firstImage && $firstImage->path) {
                                $productImage = url('storage/' . $firstImage->path);
                            } elseif ($product->image_products) {
                                $productImage = url('storage/' . $product->image_products);
                            }
                            } else {
                                Log::warning("Product not found for notification {$notification->id}, product_id: {$notification->product_id}");
                        }
                    } catch (\Exception $e) {
                        Log::warning("Error loading product for notification {$notification->id}: " . $e->getMessage());
                        }
                    }

                    if ($notification->comment_id) {
                    try {
                        $comment = \App\Models\Comment::find($notification->comment_id);
                        if ($comment) {
                            $commentText = $comment->comment;
                        }
                    } catch (\Exception $e) {
                        Log::warning("Error loading comment for notification {$notification->id}: " . $e->getMessage());
                        }
                    }

                    return [
                        'id' => $notification->id,
                        'type' => $notification->type ?? 'comment',
                        'title' => $notification->title ?? 'Notifikasi',
                        'message' => $notification->message ?? '',
                        'is_read' => (bool)$notification->is_read,
                        'product_id' => $notification->product_id,
                        'product_name' => $productName,
                        'product_image' => $productImage,
                        'comment_id' => $notification->comment_id,
                        'comment_text' => $commentText,
                        'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
                        'timestamp' => $notification->created_at->diffForHumans(),
                    ];
                } catch (\Exception $e) {
                    Log::error("Error mapping notification {$notification->id}: " . $e->getMessage());
                    Log::error("Stack trace: " . $e->getTraceAsString());
                    return [
                        'id' => $notification->id,
                        'type' => $notification->type ?? 'comment',
                        'title' => $notification->title ?? 'Notifikasi',
                        'message' => $notification->message ?? '',
                        'is_read' => (bool)$notification->is_read,
                        'product_id' => $notification->product_id,
                        'product_name' => null,
                        'product_image' => null,
                        'comment_id' => $notification->comment_id,
                        'comment_text' => null,
                        'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
                        'timestamp' => $notification->created_at->diffForHumans(),
                    ];
                }
            });

            return response()->json([
                'success' => true,
                'data' => $mappedNotifications
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting notifications: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get notifications: ' . $e->getMessage()
            ], 500);
        }
    }

    public function markAsRead(Request $request, $id)
    {
        try {
            $notification = Notification::where('user_id', $request->user()->id)
                ->find($id);

            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }

            $notification->update(['is_read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking notification as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read: ' . $e->getMessage()
            ], 500);
        }
    }

   
    public function markAllAsRead(Request $request)
    {
        try {
            Notification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->update(['is_read' => true]);

            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking all notifications as read: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark all notifications as read: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function unreadCount(Request $request)
    {
        try {
            $count = Notification::where('user_id', $request->user()->id)
                ->where('is_read', false)
                ->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'count' => $count
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting unread count: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count: ' . $e->getMessage()
            ], 500);
        }
    }
}
