<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\Product;
use App\Models\Notification;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CommentController extends Controller
{
    
    public function index(Request $request, $productId)
    {
        try {
            
            $comments = Comment::with([
                'user:id,name,username,profile_image',
                'replies.user:id,name,username,profile_image'
            ])
                ->where('product_id', $productId)
                ->whereNull('parent_comment_id')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $comments->map(function ($comment) {
                    return [
                        'id' => $comment->id,
                        'product_id' => $comment->product_id,
                        'user_id' => $comment->user_id,
                        'user_name' => $comment->user->name ?? 'Unknown',
                        'user_username' => $comment->user->username ?? null,
                        'user_profile_image' => $comment->user->profile_image ? url('storage/' . $comment->user->profile_image) : null,
                        'comment' => $comment->comment,
                        'parent_comment_id' => $comment->parent_comment_id,
                        'replies' => $comment->replies->map(function ($reply) {
                            return [
                                'id' => $reply->id,
                                'product_id' => $reply->product_id,
                                'user_id' => $reply->user_id,
                                'user_name' => $reply->user->name ?? 'Unknown',
                                'user_username' => $reply->user->username ?? null,
                                'user_profile_image' => $reply->user->profile_image ? url('storage/' . $reply->user->profile_image) : null,
                                'comment' => $reply->comment,
                                'parent_comment_id' => $reply->parent_comment_id,
                                'created_at' => $reply->created_at->format('Y-m-d H:i:s'),
                                'updated_at' => $reply->updated_at->format('Y-m-d H:i:s'),
                            ];
                        }),
                        'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $comment->updated_at->format('Y-m-d H:i:s'),
                    ];
                })
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting comments: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get comments: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function store(Request $request, $productId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'comment' => 'required|string|max:1000',
                'parent_comment_id' => 'nullable|integer|exists:comments,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

          
            $product = \App\Models\Product::find($productId);
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found'
                ], 404);
            }

            $parentComment = null;
            if ($request->parent_comment_id) {
                $parentComment = Comment::where('id', $request->parent_comment_id)
                    ->where('product_id', $productId)
                    ->first();
                
                if (!$parentComment) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Parent comment not found or does not belong to this product'
                    ], 404);
                }
            }

            $comment = Comment::create([
                'product_id' => $productId,
                'user_id' => $request->user()->id,
                'comment' => $request->comment,
                'parent_comment_id' => $request->parent_comment_id,
            ]);

            $comment->load(['user:id,name,username,profile_image']);

            $notificationRecipientId = null;
            $notificationTitle = '';
            $notificationMessage = '';
            $isReply = $request->parent_comment_id !== null;

            if ($isReply) {
                $parentComment->load('user');
                $notificationRecipientId = $parentComment->user_id;
                
                if ($notificationRecipientId !== $request->user()->id) {
                    $notificationTitle = 'Komentar Anda Dibalas';
                    $notificationMessage = ($comment->user->name ?? 'Seseorang') . ' membalas komentar Anda';
                }
            } else {
                $notificationRecipientId = $product->user_id;
                
                if ($notificationRecipientId !== $request->user()->id) {
                    $notificationTitle = 'Postingan Anda Dikomentari';
                    $notificationMessage = ($comment->user->name ?? 'Seseorang') . ' mengomentari postingan Anda';
                }
            }

            if ($notificationRecipientId && $notificationRecipientId !== $request->user()->id) {
                try {
                    $commenterName = $comment->user->name ?? 'Seseorang';
                    $productName = $product->name;
                    
                    Log::info("Creating notification - Recipient: {$notificationRecipientId}, Commenter: {$request->user()->id}, Product: {$productId}, Is Reply: " . ($isReply ? 'Yes' : 'No'));
                    
                    $notification = Notification::create([
                        'user_id' => $notificationRecipientId,
                        'product_id' => $productId,
                        'comment_id' => $comment->id,
                        'type' => $isReply ? 'reply' : 'comment',
                        'title' => $notificationTitle,
                        'message' => $notificationMessage,
                        'is_read' => false,
                    ]);

                    Log::info("✅ Notification created successfully - ID: {$notification->id}, User ID: {$notificationRecipientId}");

                    try {
                        $fcmService = new FirebaseNotificationService();
                        $fcmResult = $fcmService->sendNotification(
                            $notificationRecipientId,
                            $notificationTitle,
                            $notificationMessage,
                            [
                                'type' => $isReply ? 'reply' : 'comment',
                                'product_id' => (string)$productId,
                                'comment_id' => (string)$comment->id,
                            ]
                        );

                        if ($fcmResult) {
                            Log::info("✅ FCM notification sent successfully to user {$notificationRecipientId}");
                        } else {
                            Log::warning("⚠️ FCM notification failed for user {$notificationRecipientId} (user mungkin tidak memiliki FCM token)");
                        }
                    } catch (\Exception $fcmException) {
                        Log::error("❌ FCM error: " . $fcmException->getMessage());
                    }
                } catch (\Exception $e) {
                    Log::error('❌ Error creating notification: ' . $e->getMessage());
                    Log::error('Stack trace: ' . $e->getTraceAsString());
                }
            } else {
                Log::info("ℹ️ Skipping notification - User commenting on own content. Recipient: {$notificationRecipientId}, Commenter: {$request->user()->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Comment created successfully',
                'data' => [
                    'id' => $comment->id,
                    'product_id' => $comment->product_id,
                    'user_id' => $comment->user_id,
                    'user_name' => $comment->user->name ?? 'Unknown',
                    'user_username' => $comment->user->username ?? null,
                    'user_profile_image' => $comment->user->profile_image ? url('storage/' . $comment->user->profile_image) : null,
                    'comment' => $comment->comment,
                    'parent_comment_id' => $comment->parent_comment_id,
                    'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                    'updated_at' => $comment->updated_at->format('Y-m-d H:i:s'),
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating comment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create comment: ' . $e->getMessage()
            ], 500);
        }
    }

    
     
    public function update(Request $request, $id)
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found'
            ], 404);
        }

        if ($comment->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $comment->update([
            'comment' => $request->comment,
        ]);

        $comment->load(['user:id,name,username,profile_image']);

        return response()->json([
            'success' => true,
            'message' => 'Comment updated successfully',
            'data' => [
                'id' => $comment->id,
                'product_id' => $comment->product_id,
                'user_id' => $comment->user_id,
                'user_name' => $comment->user->name ?? 'Unknown',
                'user_username' => $comment->user->username ?? null,
                'user_profile_image' => $comment->user->profile_image ? url('storage/' . $comment->user->profile_image) : null,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $comment->updated_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    
    public function destroy(Request $request, $id)
    {
        $comment = Comment::find($id);

        if (!$comment) {
            return response()->json([
                'success' => false,
                'message' => 'Comment not found'
            ], 404);
        }

        if ($comment->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $comment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Comment deleted successfully'
        ]);
    }
}

