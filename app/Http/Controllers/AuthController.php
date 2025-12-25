<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Notification;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    public function getUserProfile($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Create notification if authenticated user is viewing someone else's profile
        $visitor = Auth::user();
        if ($visitor && $visitor->id !== $user->id) {
            try {
                $visitorName = $visitor->name ?? 'Seseorang';
                
                Log::info("Creating profile visit notification - Recipient: {$user->id}, Visitor: {$visitor->id}");
                
                // Create notification
                $notification = Notification::create([
                    'user_id' => $user->id,
                    'type' => 'profile_visit',
                    'title' => 'Profil Dikunjungi',
                    'message' => $visitorName . ' mengunjungi profilmu',
                    'is_read' => false,
                ]);
                
                Log::info("✅ Profile visit notification created - ID: {$notification->id}, User ID: {$user->id}");

                // Send FCM push notification
                try {
                    $fcmService = new FirebaseNotificationService();
                    $fcmResult = $fcmService->sendNotification(
                        $user->id,
                        'Profil Dikunjungi',
                        $visitorName . ' mengunjungi profilmu',
                        [
                            'type' => 'profile_visit',
                            'visitor_id' => (string)$visitor->id,
                            'visitor_name' => $visitorName
                        ]
                    );
                    
                    if ($fcmResult) {
                        Log::info("✅ FCM profile visit notification sent to user {$user->id}");
                    } else {
                        Log::warning("⚠️ FCM profile visit notification failed for user {$user->id}");
                    }
                } catch (\Exception $fcmException) {
                    Log::error("❌ FCM profile visit error: " . $fcmException->getMessage());
                }
            } catch (\Exception $e) {
                Log::error("❌ Error creating profile visit notification: " . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username ?? null,
                'email' => $user->email ?? null,
                'phone_number' => $user->phone_number ?? null,
                'profile_image' => $user->profile_image ? url('storage/' . $user->profile_image) : null,
                'joined_at' => $user->created_at->format('d F Y'),
            ]
        ]);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username ?? null,
                    'phone_number' => $user->phone_number ?? null,
                    'gender' => $user->gender ?? null,
                    'profile_image' => $user->profile_image ? url('storage/' . $user->profile_image) : null,
                ],
                'token' => $token
            ]
        ]);
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:6|confirmed',
            'username' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'gender' => 'nullable|string|in:Laki-laki,Perempuan',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Use name from request or generate from email
        $name = $request->name ?? explode('@', $request->email)[0];

        $user = User::create([
            'name' => $name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'username' => $request->username,
            'phone_number' => $request->phone_number,
            'gender' => $request->gender,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username ?? null,
                    'phone_number' => $user->phone_number ?? null,
                    'gender' => $user->gender ?? null,
                    'profile_image' => $user->profile_image ? url('storage/' . $user->profile_image) : null,
                ],
                'token' => $token
            ]
        ], 201);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username ?? null,
                'phone_number' => $user->phone_number ?? null,
                'gender' => $user->gender ?? null,
                'profile_image' => $user->profile_image ? url('storage/' . $user->profile_image) : null,
            ]
        ]);
    }

    public function updateUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $request->user()->id,
            'username' => 'sometimes|string|max:255',
            'phone_number' => 'sometimes|string|max:20',
            'gender' => 'sometimes|string|in:Laki-laki,Perempuan',
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        
        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            // Delete old profile image if exists
            if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
                Storage::disk('public')->delete($user->profile_image);
            }
            
            // Store new profile image
            $imagePath = $request->file('profile_image')->store('profiles', 'public');
            $user->profile_image = $imagePath;
        }
        
        // Update other fields
        $user->update($request->only(['name', 'email', 'username', 'phone_number', 'gender']));
        
        // Save profile_image if it was uploaded
        if (isset($imagePath)) {
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username ?? null,
                'phone_number' => $user->phone_number ?? null,
                'gender' => $user->gender ?? null,
                'profile_image' => $user->profile_image ? url('storage/' . $user->profile_image) : null,
            ]
        ]);
    }
    
    public function deleteProfileImage(Request $request)
    {
        $user = $request->user();
        
        // Delete profile image if exists
        if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
            Storage::disk('public')->delete($user->profile_image);
        }
        
        $user->profile_image = null;
        $user->save();
        
        return response()->json([
            'success' => true,
            'message' => 'Profile image deleted successfully',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username ?? null,
                'phone_number' => $user->phone_number ?? null,
                'gender' => $user->gender ?? null,
                'profile_image' => null,
            ]
        ]);
    }
    
    public function changePassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();

        // Verify current password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password lama tidak sesuai'
            ], 422);
        }

        // Update password
        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah'
        ]);
    }

    /**
     * Save FCM token for push notifications
     */
    public function saveFcmToken(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'fcm_token' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $request->user();
            
            // Check if fcm_token column exists
            if (!Schema::hasColumn('users', 'fcm_token')) {
                Log::error('Column fcm_token does not exist in users table');
                return response()->json([
                    'success' => false,
                    'message' => 'Database column fcm_token tidak ditemukan. Silakan jalankan migration: php artisan migrate'
                ], 500);
            }
            
            $user->fcm_token = $request->fcm_token;
            $user->save();

            Log::info("FCM token saved for user {$user->id}");

            return response()->json([
                'success' => true,
                'message' => 'FCM token berhasil disimpan'
            ]);
        } catch (\Exception $e) {
            \Log::error('Error saving FCM token: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan FCM token: ' . $e->getMessage()
            ], 500);
        }
    }
}

