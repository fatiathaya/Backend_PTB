<?php

namespace App\Http\Controllers;

use App\Models\Product;
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

        return response()->json([
            'success' => true,
            'data' => $products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'location' => $product->location,
                    'description' => $product->description,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
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
            'location' => 'required|string|max:255',
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
            'location' => $request->location,
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
                'image' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'location' => $product->location,
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
    public function show($id)
    {
        $product = Product::with('user:id,name,username,profile_image')->find($id);

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category,
                'condition' => $product->condition,
                'price' => number_format($product->price, 0, ',', '.'),
                'image' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'location' => $product->location,
                'description' => $product->description,
                'whatsapp_number' => $product->whatsapp_number,
                'seller_name' => $product->user->name ?? 'Unknown',
                'seller_username' => $product->user->username ?? null,
                'seller_profile_image' => $product->user->profile_image ? url('storage/' . $product->user->profile_image) : null,
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
            'location' => 'sometimes|required|string|max:255',
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
            'location',
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
                'image' => $product->image_products ? url('storage/' . $product->image_products) : null,
                'location' => $product->location,
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
                    'image' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'location' => $product->location,
                    'description' => $product->description,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                ];
            })
        ]);
    }
}

