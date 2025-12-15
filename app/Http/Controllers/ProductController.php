<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Favorite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    /**
     * Display a listing of products (public).
     */
    public function index(Request $request)
    {
        $query = Product::with('user:id,name,username,profile_image');

        // Filter by category
        if ($request->has('category') && $request->category) {
            $query->where('category', $request->category);
        }

        // Filter by condition
        if ($request->has('condition') && $request->condition) {
            $query->where('condition', $request->condition);
        }

        // Search by name
        if ($request->has('search') && $request->search) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $products = $query->latest()->paginate(20);
        
        // Get user's favorite product IDs if authenticated
        $userFavoriteIds = [];
        if ($request->user()) {
            $userFavoriteIds = Favorite::where('user_id', $request->user()->id)
                ->pluck('product_id')
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data' => $products->map(function ($product) use ($userFavoriteIds) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'description' => $product->description,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'is_favorite' => in_array($product->id, $userFavoriteIds),
                    'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                ];
            }),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ]
        ]);
    }

    /**
     * Store a newly created product.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:Baju,Perabotan,Elektronik,Kulia,Sepatu',
            'condition' => 'required|string|in:Baru,Bekas,Sangat Baik,Baik,Cukup',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'whatsapp_number' => 'required|string|max:20',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('products', 'public');
        }

        $product = Product::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'category' => $request->category,
            'condition' => $request->condition,
            'description' => $request->description,
            'price' => $request->price,
            'whatsapp_number' => $request->whatsapp_number,
            'image_products' => $imagePath,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created successfully',
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category,
                'condition' => $product->condition,
                'price' => number_format($product->price, 0, ',', '.'),
                'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'description' => $product->description,
                'whatsapp_number' => $product->whatsapp_number,
                'seller_name' => $product->user->name ?? 'Unknown',
                'created_at' => $product->created_at->format('Y-m-d H:i:s'),
            ]
        ], 201);
    }

    /**
     * Display the specified product.
     */
    public function show(Request $request, $id)
    {
        $product = Product::with('user:id,name,username,profile_image')->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check if product is favorited by user
        $isFavorite = false;
        if ($request->user()) {
            $isFavorite = Favorite::where('user_id', $request->user()->id)
                ->where('product_id', $product->id)
                ->exists();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category,
                'condition' => $product->condition,
                'price' => number_format($product->price, 0, ',', '.'),
                'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'description' => $product->description,
                'whatsapp_number' => $product->whatsapp_number,
                'seller_name' => $product->user->name ?? 'Unknown',
                'seller_username' => $product->user->username ?? null,
                'seller_profile_image' => $product->user->profile_image ? url('storage/' . $product->user->profile_image) : null,
                'is_favorite' => $isFavorite,
                'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Update the specified product.
     */
    public function update(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check if user owns the product
        if ($product->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only update your own products.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|string|in:Baju,Perabotan,Elektronik,Kulia,Sepatu',
            'condition' => 'sometimes|required|string|in:Baru,Bekas,Sangat Baik,Baik,Cukup',
            'description' => 'nullable|string',
            'price' => 'sometimes|required|numeric|min:0',
            'whatsapp_number' => 'sometimes|required|string|max:20',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($product->image_products && Storage::disk('public')->exists($product->image_products)) {
                Storage::disk('public')->delete($product->image_products);
            }
            $imagePath = $request->file('image')->store('products', 'public');
            $product->image_products = $imagePath;
        }

        $product->update($request->only([
            'name',
            'category',
            'condition',
            'description',
            'price',
            'whatsapp_number',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Product updated successfully',
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category,
                'condition' => $product->condition,
                'price' => number_format($product->price, 0, ',', '.'),
                'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'description' => $product->description,
                'whatsapp_number' => $product->whatsapp_number,
                'seller_name' => $product->user->name ?? 'Unknown',
                'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                'updated_at' => $product->updated_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Remove the specified product.
     */
    public function destroy(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Check if user owns the product
        if ($product->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You can only delete your own products.'
            ], 403);
        }

        // Delete image if exists
        if ($product->image_products && Storage::disk('public')->exists($product->image_products)) {
            Storage::disk('public')->delete($product->image_products);
        }

        $product->delete();

        return response()->json([
            'success' => true,
            'message' => 'Product deleted successfully'
        ]);
    }

    /**
     * Get products by authenticated user.
     */
    public function getMyProducts(Request $request)
    {
        $products = Product::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'description' => $product->description,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                ];
            })
        ]);
    }

    /**
     * Toggle favorite status for a product.
     * Hanya user yang sedang login yang bisa menambah/hapus favorite.
     * Setiap user memiliki wishlist mereka sendiri.
     */
    public function toggleFavorite(Request $request, $id)
    {
        $product = Product::find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        // Get authenticated user - hanya user yang sedang login
        $user = $request->user();
        
        // Cari favorite berdasarkan user_id dan product_id
        // Memastikan hanya favorite milik user yang sedang login yang diakses
        $favorite = Favorite::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($favorite) {
            // Remove from favorites - hanya favorite milik user ini yang dihapus
            $favorite->delete();
            $isFavorite = false;
        } else {
            // Add to favorites - hanya untuk user yang sedang login
            Favorite::create([
                'user_id' => $user->id, // Pastikan user_id adalah user yang sedang login
                'product_id' => $product->id,
            ]);
            $isFavorite = true;
        }

        return response()->json([
            'success' => true,
            'message' => $isFavorite ? 'Product added to favorites' : 'Product removed from favorites',
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category,
                'condition' => $product->condition,
                'price' => number_format($product->price, 0, ',', '.'),
                'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'description' => $product->description,
                'whatsapp_number' => $product->whatsapp_number,
                'seller_name' => $product->user->name ?? 'Unknown',
                'is_favorite' => $isFavorite,
                'created_at' => $product->created_at->format('Y-m-d H:i:s'),
            ]
        ]);
    }

    /**
     * Get favorite products for authenticated user.
     * Hanya menampilkan wishlist milik user yang sedang login.
     * User tidak bisa melihat wishlist user lain.
     */
    public function getFavorites(Request $request)
    {
        // Get authenticated user - hanya user yang sedang login
        $user = $request->user();
        
        // Validasi: Pastikan user tidak null (harus sudah authenticated)
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }
        
        // Query eksplisit dengan where clause untuk memastikan hanya mengambil favorite milik user ini
        // Menggunakan Favorite model langsung dengan where clause yang eksplisit berdasarkan user_id
        // Ini memastikan TIDAK ADA cara untuk mengakses wishlist user lain
        $favorites = Favorite::where('user_id', $user->id) // EKSPLISIT: Hanya favorite milik user ini
            ->with(['product.user:id,name,username,profile_image'])
            ->latest()
            ->get();

        // Map ke products dan filter null
        $favoriteProducts = $favorites->map(function ($favorite) {
            return $favorite->product;
        })->filter();

        return response()->json([
            'success' => true,
            'data' => $favoriteProducts->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'description' => $product->description,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'is_favorite' => true, // Semua produk di sini adalah favorite milik user ini
                    'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                ];
            })
        ]);
    }
}

