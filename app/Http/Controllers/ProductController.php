<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Favorite;
use App\Models\ProductImage;
use App\Models\Notification;
use App\Services\FirebaseNotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

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
            $category = $request->category;
            // Backward compatible: treat Baju/Pakaian as the same category.
            if ($category === 'Baju' || $category === 'Pakaian') {
                $query->whereIn('category', ['Baju', 'Pakaian']);
            } elseif ($category === 'Kulia' || $category === 'Perlengkapan Kuliah') {
                $query->whereIn('category', ['Kulia', 'Perlengkapan Kuliah']);
            } else {
                $query->where('category', $category);
            }
        }

        // Filter by condition
        if ($request->has('condition') && $request->condition) {
            $query->where('condition', $request->condition);
        }

        // Search by name or category
        if ($request->has('search') && $request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('category', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        $products = $query->latest()->paginate(20);
        
        // Get user's favorite product IDs if authenticated
        $userFavoriteIds = [];
        $currentUserId = null;
        if ($request->user()) {
            $currentUserId = $request->user()->id;
            $userFavoriteIds = Favorite::where('user_id', $currentUserId)
                ->pluck('product_id')
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'data' => $products->map(function ($product) use ($userFavoriteIds, $currentUserId) {
                // Get all product images
                $allImages = ProductImage::where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->get();
                $imageUrls = $allImages->map(fn($img) => [
                    'id' => $img->id,
                    'url' => url('storage/' . $img->path)
                ])->toArray();
                
                // Check if current user is the product owner
                $isOwnProduct = false;
                if ($currentUserId !== null) {
                    $isOwnProduct = $product->user_id === $currentUserId;
                }
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'images' => $imageUrls, // Multi-image with IDs
                    'description' => $product->description,
                    'address' => $product->address,
                    'latitude' => $product->latitude,
                    'longitude' => $product->longitude,
                    'location' => $product->location,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'user_id' => $product->user_id, // ID pemilik produk
                    'is_favorite' => in_array($product->id, $userFavoriteIds),
                    'is_own_product' => $isOwnProduct, // Flag untuk menandai produk milik user sendiri
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
            // Accept legacy values, but we will normalize on save.
            'category' => [
                'required',
                'string',
                Rule::in([
                    'Pakaian',
                    'Baju',
                    'Perabotan',
                    'Elektronik',
                    'Perlengkapan Kuliah',
                    'Kulia',
                    'Sepatu',
                ]),
            ],
            'condition' => 'required|string|in:Baru,Bekas,Sangat Baik,Baik,Cukup',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'location' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'whatsapp_number' => 'required|string|max:20',
            // Legacy single image
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // New multiple images
            'images' => 'nullable|array|max:6',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
        // Handle images (multi preferred, fallback to single)
        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $idx => $file) {
                if ($file) {
                    $imagePaths[] = $file->store('products', 'public');
                }
            }
        } elseif ($request->hasFile('image')) {
            $imagePaths[] = $request->file('image')->store('products', 'public');
        }

        $category = $request->category;
        if ($category === 'Baju') {
            $category = 'Pakaian';
        } elseif ($category === 'Kulia') {
            $category = 'Perlengkapan Kuliah';
        }

        $product = Product::create([
            'user_id' => $request->user()->id,
            'name' => $request->name,
            'category' => $category,
            'condition' => $request->condition,
            'description' => $request->description,
            'address' => $request->address,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
                'location' => $request->location ?? $request->address, // Use location if provided, fallback to address
            'price' => $request->price,
            'whatsapp_number' => $request->whatsapp_number,
            // Use first image as cover
            'image_products' => $imagePaths[0] ?? null,
        ]);

        // Save all images to product_images table
        foreach ($imagePaths as $i => $path) {
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $path,
                'sort_order' => $i,
            ]);
            }
        } catch (\Exception $e) {
            Log::error('Error creating product: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString(),
                'request_data' => $request->except(['images', 'image'])
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat produk: ' . $e->getMessage()
            ], 500);
        }

        // Get all product images with IDs
        $allImages = ProductImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();
        $imageUrls = $allImages->map(fn($img) => [
            'id' => $img->id,
            'url' => url('storage/' . $img->path)
        ])->toArray();

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
                'images' => $imageUrls, // Multi-image with IDs
                'description' => $product->description,
                'address' => $product->address,
                'latitude' => $product->latitude,
                'longitude' => $product->longitude,
                'location' => $product->location,
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

        // Get all product images
        $allImages = ProductImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();
        $imageUrls = $allImages->map(fn($img) => [
            'id' => $img->id,
            'url' => url('storage/' . $img->path)
        ])->toArray();

        // Check if current user is the product owner
        $isOwnProduct = false;
        if ($request->user()) {
            $isOwnProduct = $product->user_id === $request->user()->id;
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
                'images' => $imageUrls,
                'description' => $product->description,
                'address' => $product->address,
                'latitude' => $product->latitude,
                'longitude' => $product->longitude,
                'location' => $product->location,
                'whatsapp_number' => $product->whatsapp_number,
                'seller_name' => $product->user->name ?? 'Unknown',
                'seller_username' => $product->user->username ?? null,
                'seller_profile_image' => $product->user->profile_image ? url('storage/' . $product->user->profile_image) : null,
                'user_id' => $product->user_id, // ID pemilik produk
                'is_favorite' => $isFavorite,
                'is_own_product' => $isOwnProduct, // Flag untuk menandai produk milik user sendiri
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
            // Accept legacy values, but we will normalize on save.
            'category' => [
                'sometimes',
                'required',
                'string',
                Rule::in([
                    'Pakaian',
                    'Baju',
                    'Perabotan',
                    'Elektronik',
                    'Perlengkapan Kuliah',
                    'Kulia',
                    'Sepatu',
                ]),
            ],
            'condition' => 'sometimes|required|string|in:Baru,Bekas,Sangat Baik,Baik,Cukup',
            'description' => 'nullable|string',
            'address' => 'nullable|string|max:1000',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'price' => 'sometimes|required|string', // Accept string, will convert to numeric
            'whatsapp_number' => 'sometimes|required|string|max:20',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            // New multiple images
            'images' => 'nullable|array|max:6',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'delete_image_ids' => 'nullable', // Accept any format, will process manually
        ]);

        if ($validator->fails()) {
            Log::error('Product update validation failed', [
                'product_id' => $id,
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->except(['images', 'image', 'delete_image_ids'])
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation error: ' . $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        // CRITICAL: Handle delete image IDs from multipart form
        // Multipart forms send arrays as delete_image_ids[0], delete_image_ids[1], etc.
        // Sometimes Laravel receives UploadedFile objects instead of string IDs due to multipart parsing
        $deleteImageIds = [];
        
        // Get all request data
        $allInput = $request->all();
        
        // CRITICAL: Read delete_image_ids and filter out UploadedFile objects
        // Laravel might interpret delete_image_ids[] as files if sent incorrectly
        if ($request->has('delete_image_ids')) {
            $rawDeleteIds = $request->input('delete_image_ids');
            
            // Handle array case
            if (is_array($rawDeleteIds)) {
                foreach ($rawDeleteIds as $item) {
                    // CRITICAL: Skip UploadedFile objects - these are images, not IDs to delete
                    if ($item instanceof \Illuminate\Http\UploadedFile) {
                        Log::warning("Skipping UploadedFile in delete_image_ids (should be ID)", [
                            'product_id' => $product->id,
                            'file_name' => $item->getClientOriginalName(),
                            'file_size' => $item->getSize()
                        ]);
                        continue;
                    }
                    
                    // Only add non-file values
                    if ($item !== null && $item !== '') {
                        $deleteImageIds[] = $item;
                    }
                }
            } else {
                // Single value case
                if (!($rawDeleteIds instanceof \Illuminate\Http\UploadedFile)) {
                    if ($rawDeleteIds !== null && $rawDeleteIds !== '') {
                        $deleteImageIds[] = $rawDeleteIds;
                    }
                } else {
                    Log::warning("delete_image_ids is UploadedFile (should be ID)", [
                        'product_id' => $product->id,
                        'file_name' => $rawDeleteIds->getClientOriginalName()
                    ]);
                }
            }
        }
        
        // If still empty, try to find in all request keys (fallback method)
        // Look for delete_image_ids[0], delete_image_ids[1], etc. (indexed format)
        if (empty($deleteImageIds)) {
            foreach ($allInput as $key => $value) {
                // Match delete_image_ids[] or delete_image_ids[0], delete_image_ids[1], etc.
                if (preg_match('/^delete_image_ids(\[\d*\])?$/', $key)) {
                    // Skip UploadedFile objects
                    if ($value instanceof \Illuminate\Http\UploadedFile) {
                        Log::warning("Skipping UploadedFile in request key: $key", [
                            'product_id' => $product->id
                        ]);
                        continue;
                    }
                    
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            if (!($v instanceof \Illuminate\Http\UploadedFile) && $v !== null && $v !== '') {
                                $deleteImageIds[] = $v;
                            }
                        }
                    } else {
                        if ($value !== null && $value !== '') {
                            $deleteImageIds[] = $value;
                        }
                    }
                }
            }
            
            // CRITICAL: Also try to read from raw request content if still empty
            // This handles cases where Laravel misinterprets the data
            if (empty($deleteImageIds) && $request->getContent()) {
                $content = $request->getContent();
                // Try to extract delete_image_ids from raw content
                if (preg_match_all('/name="delete_image_ids\[(\d+)\]"\s+value="(\d+)"/', $content, $matches)) {
                    foreach ($matches[2] as $id) {
                        $deleteImageIds[] = $id;
                    }
                    Log::info("Extracted delete_image_ids from raw content", [
                        'product_id' => $product->id,
                        'ids' => $deleteImageIds
                    ]);
                }
            }
        }
        
        Log::info("Received delete_image_ids - DEBUG", [
            'product_id' => $product->id,
            'has_delete_image_ids' => $request->has('delete_image_ids'),
            'raw_input' => $deleteImageIds,
            'type' => gettype($deleteImageIds),
            'is_array' => is_array($deleteImageIds),
            'count' => count($deleteImageIds),
            'all_request_keys' => array_keys($allInput),
            'request_content_type' => $request->header('Content-Type'),
            'has_files' => $request->hasFile('delete_image_ids'),
            'all_request_data_types' => array_map(function($v) {
                return is_object($v) ? get_class($v) : gettype($v);
            }, $allInput)
        ]);
        
        // Ensure we have an array
        if (!is_array($deleteImageIds)) {
            $deleteImageIds = [];
        }
        
        // Filter out null/empty values and convert to integers
        // CRITICAL: Handle case where Laravel might receive UploadedFile objects instead of IDs
        $deleteIds = [];
        foreach ($deleteImageIds as $id) {
            // Skip UploadedFile objects (these are images, not IDs to delete)
            if ($id instanceof \Illuminate\Http\UploadedFile) {
                Log::warning("Skipping UploadedFile in delete_image_ids", [
                    'product_id' => $product->id,
                    'file_name' => $id->getClientOriginalName()
                ]);
                continue;
            }
            
            if ($id !== null && $id !== '' && $id !== 'null') {
                // Convert to string first, then to int to handle both string and int inputs
                $idValue = is_numeric($id) ? intval($id) : null;
                if ($idValue !== null && $idValue > 0) {
                    $deleteIds[] = $idValue;
                }
            }
        }
        
        Log::info("Processed delete IDs", [
            'product_id' => $product->id,
            'delete_ids' => $deleteIds,
            'count' => count($deleteIds)
        ]);
        
        // CRITICAL: Execute deletion if we have IDs
        if (!empty($deleteIds)) {
            // Validate that all IDs exist in product_images table
            $existingIds = ProductImage::where('product_id', $product->id)
                ->whereIn('id', $deleteIds)
                ->pluck('id')
                ->toArray();
            
            $invalidIds = array_diff($deleteIds, $existingIds);
            if (!empty($invalidIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Some image IDs do not exist or do not belong to this product',
                    'invalid_ids' => $invalidIds
                ], 422);
            }
            
            $imagesToDelete = ProductImage::where('product_id', $product->id)
                ->whereIn('id', $deleteIds)
                ->get();
            
            Log::info("About to delete images", [
                'product_id' => $product->id,
                'count' => $imagesToDelete->count(),
                'ids' => $imagesToDelete->pluck('id')->toArray()
            ]);
            
            // CRITICAL: Delete each image from database and storage
            foreach ($imagesToDelete as $img) {
                $imageId = $img->id;
                $imagePath = $img->path;
                
                Log::info("Deleting image", [
                    'product_id' => $product->id,
                    'image_id' => $imageId,
                    'path' => $imagePath
                ]);
                
                // Delete file from storage first
                if ($imagePath && Storage::disk('public')->exists($imagePath)) {
                    $storageDeleted = Storage::disk('public')->delete($imagePath);
                    Log::info("Storage delete result", [
                        'image_id' => $imageId,
                        'path' => $imagePath,
                        'deleted' => $storageDeleted
                    ]);
                }
                
                // CRITICAL: Delete record from database using direct DB query to ensure it works
                // First try model delete
                $dbDeleted = $img->delete();
                
                Log::info("Database delete result (model)", [
                    'product_id' => $product->id,
                    'image_id' => $imageId,
                    'deleted' => $dbDeleted
                ]);
                
                // CRITICAL: Verify deletion immediately
                $stillExists = ProductImage::where('id', $imageId)->exists();
                if ($stillExists) {
                    Log::error("CRITICAL: Image still exists after model delete!", [
                        'product_id' => $product->id,
                        'image_id' => $imageId,
                        'attempting_direct_db_delete' => true
                    ]);
                    
                    // Try direct database delete as fallback (bypasses model events)
                    $forceDeleted = DB::table('product_images')->where('id', $imageId)->delete();
                    $stillExistsAfterForce = ProductImage::where('id', $imageId)->exists();
                    
                    if ($stillExistsAfterForce) {
                        Log::error("CRITICAL: Image STILL exists after direct DB delete!", [
                            'product_id' => $product->id,
                            'image_id' => $imageId,
                            'force_deleted_rows' => $forceDeleted
                        ]);
                    } else {
                        Log::info("Image deleted after direct DB delete", [
                            'product_id' => $product->id,
                            'image_id' => $imageId
                        ]);
                    }
                } else {
                    Log::info("Image successfully deleted from database", [
                        'product_id' => $product->id,
                        'image_id' => $imageId
                    ]);
                }
            }
            
            // If we deleted the cover image, set a new one from remaining images
            $deletedPaths = $imagesToDelete->pluck('path')->toArray();
            if ($product->image_products && in_array($product->image_products, $deletedPaths)) {
                $newCover = ProductImage::where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->first();
                $product->image_products = $newCover ? $newCover->path : null;
                $product->save(); // Save the updated cover image
                Log::info("Updated cover image", [
                    'product_id' => $product->id,
                    'new_cover' => $product->image_products
                ]);
            }
        }

        // Handle images (multi preferred, fallback to single)
        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $idx => $file) {
                $imagePath = $file->store('products', 'public');
                $imagePaths[] = $imagePath;
                
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $imagePath,
                'sort_order' => ProductImage::where('product_id', $product->id)->max('sort_order') + $idx + 1,
            ]);
            }
            
            // Set first new image as cover if no cover exists
            if (empty($product->image_products) && !empty($imagePaths)) {
                $product->image_products = $imagePaths[0];
            }
        } elseif ($request->hasFile('image')) {
            // Legacy single image support
            if ($product->image_products && Storage::disk('public')->exists($product->image_products)) {
                Storage::disk('public')->delete($product->image_products);
            }
            $imagePath = $request->file('image')->store('products', 'public');
            $product->image_products = $imagePath;
            
            // Also save to product_images
            ProductImage::create([
                'product_id' => $product->id,
                'path' => $imagePath,
                'sort_order' => ProductImage::where('product_id', $product->id)->max('sort_order') + 1,
            ]);
        }

        try {
            $updateData = [];
            
            // Only update fields that are provided
            if ($request->has('name')) {
                $updateData['name'] = $request->input('name');
            }
            if ($request->has('category')) {
                $category = $request->input('category');
                // Normalize category
                if ($category === 'Baju') {
                    $category = 'Pakaian';
                } elseif ($category === 'Kulia') {
                    $category = 'Perlengkapan Kuliah';
                }
                $updateData['category'] = $category;
            }
            if ($request->has('condition')) {
                $updateData['condition'] = $request->input('condition');
            }
            if ($request->has('description')) {
                $updateData['description'] = $request->input('description');
            }
            if ($request->has('address')) {
                $updateData['address'] = $request->input('address');
            }
            if ($request->has('latitude')) {
                $updateData['latitude'] = $request->input('latitude');
            }
            if ($request->has('longitude')) {
                $updateData['longitude'] = $request->input('longitude');
            }
            if ($request->has('location')) {
                $updateData['location'] = $request->input('location');
            }
            if ($request->has('price')) {
                // Remove formatting from price if present
                $price = $request->input('price');
                $price = str_replace(['Rp ', '.', ','], '', $price);
                $updateData['price'] = floatval($price);
            }
            if ($request->has('whatsapp_number')) {
                $updateData['whatsapp_number'] = $request->input('whatsapp_number');
            }

            if (!empty($updateData)) {
                $product->update($updateData);
            }
        } catch (\Exception $e) {
            Log::error('Error updating product: ' . $e->getMessage(), [
                'product_id' => $product->id,
                'request_data' => $request->except(['images', 'image']),
                'exception' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update product: ' . $e->getMessage()
            ], 500);
        }


        // CRITICAL: Get all product images AFTER deletion and upload
        // This ensures we return the current state of images in database
        // Refresh product to get latest data
        $product->refresh();
        
        // CRITICAL: Eager load user relation to prevent lazy loading issues
        // Lazy loading in response can cause timeout or errors
        $product->load('user');
        
        // CRITICAL: Force fresh query to get latest images from database
        // Use fresh() to bypass any query cache
        $allImages = ProductImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();
        
        $imageUrls = $allImages->map(fn($img) => [
            'id' => $img->id,
            'url' => url('storage/' . $img->path)
        ])->toArray();
        
        Log::info("Product update completed - sending response", [
            'product_id' => $product->id,
            'images_count' => count($imageUrls),
        ]);

        // CRITICAL: Wrap response generation in try-catch to ensure we always send a response
        try {
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
                    'images' => $imageUrls,
                    'description' => $product->description,
                    'address' => $product->address,
                    'latitude' => $product->latitude,
                    'longitude' => $product->longitude,
                    'location' => $product->location,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user ? $product->user->name : 'Unknown',
                    'created_at' => $product->created_at ? $product->created_at->format('Y-m-d H:i:s') : null,
                    'updated_at' => $product->updated_at ? $product->updated_at->format('Y-m-d H:i:s') : null,
                ]
            ]);
        } catch (\Exception $e) {
            // If response generation fails, still send a basic success response
            Log::error('Error generating update response: ' . $e->getMessage());
            return response()->json([
                'success' => true,
                'message' => 'Product updated successfully',
                'data' => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'images' => $imageUrls,
                ]
            ]);
        }
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

        // Delete all product images
        $productImages = ProductImage::where('product_id', $product->id)->get();
        foreach ($productImages as $img) {
            if ($img->path && Storage::disk('public')->exists($img->path)) {
                Storage::disk('public')->delete($img->path);
            }
        }
        ProductImage::where('product_id', $product->id)->delete();
        
        // Delete cover image if exists
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
     * Delete a product image
     */
    public function deleteProductImage(Request $request, $productId, $imageId)
    {
        $product = Product::find($productId);
        
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
                'message' => 'Unauthorized. You can only delete images from your own products.'
            ], 403);
        }
        
        // Find the image
        $image = ProductImage::where('id', $imageId)
            ->where('product_id', $productId)
            ->first();
        
        if (!$image) {
            return response()->json([
                'success' => false,
                'message' => 'Image not found'
            ], 404);
        }
        
        Log::info("Deleting product image", [
            'product_id' => $productId,
            'image_id' => $imageId,
            'path' => $image->path
        ]);
        
        // Delete file from storage
        if ($image->path && Storage::disk('public')->exists($image->path)) {
            $storageDeleted = Storage::disk('public')->delete($image->path);
            Log::info("Storage delete result", [
                'image_id' => $imageId,
                'path' => $image->path,
                'deleted' => $storageDeleted
            ]);
        }
        
        // Delete record from database
        $imageIdToDelete = $image->id;
        $dbDeleted = $image->delete();
        
        Log::info("Database delete result", [
            'product_id' => $productId,
            'image_id' => $imageIdToDelete,
            'deleted' => $dbDeleted
        ]);
        
        // Verify deletion
        $stillExists = ProductImage::where('id', $imageIdToDelete)->exists();
        if ($stillExists) {
            Log::error("CRITICAL: Image still exists after delete!", [
                'product_id' => $productId,
                'image_id' => $imageIdToDelete
            ]);
            
            // Try direct database delete as fallback
            $forceDeleted = DB::table('product_images')->where('id', $imageIdToDelete)->delete();
            $stillExistsAfterForce = ProductImage::where('id', $imageIdToDelete)->exists();
            
            if ($stillExistsAfterForce) {
                Log::error("CRITICAL: Image STILL exists after force delete!", [
                    'product_id' => $productId,
                    'image_id' => $imageIdToDelete
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to delete image from database'
                ], 500);
            }
        }
        
        // If we deleted the cover image, set a new one from remaining images
        if ($product->image_products && $product->image_products === $image->path) {
            $newCover = ProductImage::where('product_id', $product->id)
                ->orderBy('sort_order')
                ->first();
            $product->image_products = $newCover ? $newCover->path : null;
            $product->save();
            Log::info("Updated cover image", [
                'product_id' => $product->id,
                'new_cover' => $product->image_products
            ]);
        }
        
        // Get updated product with remaining images
        $product->refresh();
        $allImages = ProductImage::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();
        
        $imageUrls = $allImages->map(fn($img) => [
            'id' => $img->id,
            'url' => url('storage/' . $img->path)
        ])->toArray();
        
        return response()->json([
            'success' => true,
            'message' => 'Image deleted successfully',
            'data' => [
                'product_id' => $product->id,
                'images' => $imageUrls,
                'deleted_image_id' => $imageIdToDelete
            ]
        ]);
    }
    
    /**
     * Get products by authenticated user.
     */
    public function getMyProducts(Request $request)
    {
        // CRITICAL: Use fresh query to ensure we get latest data
        $products = Product::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $products->map(function ($product) use ($request) {
                // CRITICAL: Always query fresh from database, don't use cached relationship
                $allImages = ProductImage::where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->get();
                $imageUrls = $allImages->map(fn($img) => [
                    'id' => $img->id,
                    'url' => url('storage/' . $img->path)
                ])->toArray();
                
                Log::info("getMyProducts - Product images", [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'images_count' => count($imageUrls),
                    'image_ids' => array_column($imageUrls, 'id')
                ]);
                
                // Semua produk di getMyProducts adalah milik user sendiri
                $isOwnProduct = true;
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'images' => $imageUrls, // Multi-image with IDs - CRITICAL: This is fresh from database
                    'description' => $product->description,
                    'address' => $product->address,
                    'latitude' => $product->latitude,
                    'longitude' => $product->longitude,
                    'location' => $product->location,
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'user_id' => $product->user_id, // ID pemilik produk
                    'is_own_product' => $isOwnProduct, // Flag untuk menandai produk milik user sendiri
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
        
        // Validasi: User tidak bisa wishlist produk sendiri
        if ($product->user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak bisa menambahkan produk sendiri ke wishlist'
            ], 400);
        }
        
        // Cari favorite berdasarkan user_id dan product_id
        // Memastikan hanya favorite milik user yang sedang login yang diakses
        $favorite = Favorite::where('user_id', $user->id)
            ->where('product_id', $product->id)
            ->first();

        if ($favorite) {
            // Remove from favorites - hanya favorite milik user ini yang dihapus
            $favorite->delete();
            $isFavorite = false;
            
            // Hapus notifikasi wishlist terkait ketika produk di-unwishlist
            // Hanya hapus jika user me-unwishlist produk milik user lain
            if ($product->user_id !== $user->id) {
                try {
                    $wishlisterName = $user->name ?? 'Seseorang';
                    
                    // Cari notifikasi wishlist yang sesuai dengan user yang melakukan unwishlist
                    // Kita cari berdasarkan message yang mengandung nama user yang unwishlist
                    $wishlistNotifications = Notification::where('user_id', $product->user_id)
                        ->where('product_id', $product->id)
                        ->where('type', 'wishlist')
                        ->where('message', 'like', '%' . $wishlisterName . '%')
                        ->orderBy('created_at', 'desc')
                        ->get();
                    
                    if ($wishlistNotifications->isNotEmpty()) {
                        // Hapus notifikasi wishlist yang sesuai (biasanya hanya 1, tapi bisa lebih jika ada multiple)
                        $deletedCount = 0;
                        foreach ($wishlistNotifications as $notification) {
                            $notificationId = $notification->id;
                            $notification->delete();
                            $deletedCount++;
                            Log::info("✅ Wishlist notification deleted - ID: {$notificationId}, User ID: {$product->user_id}, Product ID: {$product->id}, Unwishlisted by User: {$user->id} ({$wishlisterName})");
                        }
                        Log::info("✅ Deleted {$deletedCount} wishlist notification(s) for product {$product->id}");
                    } else {
                        // Fallback: Hapus notifikasi wishlist terbaru jika tidak ditemukan berdasarkan nama
                        $wishlistNotification = Notification::where('user_id', $product->user_id)
                            ->where('product_id', $product->id)
                            ->where('type', 'wishlist')
                            ->orderBy('created_at', 'desc')
                            ->first();
                        
                        if ($wishlistNotification) {
                            $notificationId = $wishlistNotification->id;
                            $wishlistNotification->delete();
                            Log::info("✅ Wishlist notification deleted (fallback) - ID: {$notificationId}, User ID: {$product->user_id}, Product ID: {$product->id}, Unwishlisted by User: {$user->id}");
                        } else {
                            Log::info("ℹ️ No wishlist notification found to delete for product {$product->id}, user {$product->user_id}");
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('❌ Error deleting wishlist notification: ' . $e->getMessage());
                    Log::error('Stack trace: ' . $e->getTraceAsString());
                    // Don't fail the unwishlist operation if notification deletion fails
                }
            }
        } else {
            // Add to favorites - hanya untuk user yang sedang login
            Favorite::create([
                'user_id' => $user->id, // Pastikan user_id adalah user yang sedang login
                'product_id' => $product->id,
            ]);
            $isFavorite = true;
            
            // Kirim notifikasi ke pemilik produk ketika produk di-wishlist
            // Hanya kirim jika user mewishlist produk milik user lain
            if ($product->user_id !== $user->id) {
                try {
                    $wishlisterName = $user->name ?? 'Seseorang';
                    $productName = $product->name;
                    
                    Log::info("Creating wishlist notification - Recipient: {$product->user_id}, Wishlister: {$user->id}, Product: {$product->id}");
                    
                    // Create notification in database
                    $notification = Notification::create([
                        'user_id' => $product->user_id, // Pemilik produk
                        'product_id' => $product->id,
                        'type' => 'wishlist',
                        'title' => 'Produk Anda Diwishlist',
                        'message' => $wishlisterName . ' menambahkan "' . $productName . '" ke wishlist',
                        'is_read' => false,
                    ]);

                    Log::info("✅ Wishlist notification created successfully - ID: {$notification->id}, User ID: {$product->user_id}");

                    // Send FCM notification
                    try {
                        $fcmService = new FirebaseNotificationService();
                        $fcmResult = $fcmService->sendNotification(
                            $product->user_id,
                            'Produk Anda Diwishlist',
                            $wishlisterName . ' menambahkan "' . $productName . '" ke wishlist',
                            [
                                'type' => 'wishlist',
                                'product_id' => (string)$product->id,
                            ]
                        );

                        if ($fcmResult) {
                            Log::info("✅ FCM wishlist notification sent successfully to user {$product->user_id}");
                        } else {
                            Log::warning("⚠️ FCM wishlist notification failed for user {$product->user_id} (user mungkin tidak memiliki FCM token)");
                        }
                    } catch (\Exception $fcmException) {
                        Log::error("❌ FCM wishlist error: " . $fcmException->getMessage());
                    }
                } catch (\Exception $e) {
                    Log::error('❌ Error creating wishlist notification: ' . $e->getMessage());
                    Log::error('Stack trace: ' . $e->getTraceAsString());
                }
            } else {
                Log::info("ℹ️ Skipping wishlist notification. User {$user->id} wishlisted their own product {$product->id}");
            }
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
                'address' => $product->address,
                'latitude' => $product->latitude,
                'longitude' => $product->longitude,
                'location' => $product->location,
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
            'data' => $favoriteProducts->map(function ($product) use ($user) {
                // Get all product images
                $allImages = ProductImage::where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->get();
                $imageUrls = $allImages->map(fn($img) => [
                    'id' => $img->id,
                    'url' => url('storage/' . $img->path)
                ])->toArray();
                
                // Check if current user is the product owner
                $isOwnProduct = $product->user_id === $user->id;
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category' => $product->category,
                    'condition' => $product->condition,
                    'price' => number_format($product->price, 0, ',', '.'),
                    'image_url' => $product->image_products ? url('storage/' . $product->image_products) : null,
                    'images' => $imageUrls, // Multi-image with IDs
                    'description' => $product->description,
                    'address' => $product->address,
                    'latitude' => $product->latitude,
                    'longitude' => $product->longitude,
                    'location' => $product->location,
                    'user_id' => $product->user_id, // ID pemilik produk
                    'is_own_product' => $isOwnProduct, // Flag untuk menandai produk milik user sendiri
                    'whatsapp_number' => $product->whatsapp_number,
                    'seller_name' => $product->user->name ?? 'Unknown',
                    'user_id' => $product->user_id, // ID pemilik produk
                    'is_favorite' => true, // Semua produk di sini adalah favorite milik user ini
                    'is_own_product' => $isOwnProduct, // Flag untuk menandai produk milik user sendiri
                    'created_at' => $product->created_at->format('Y-m-d H:i:s'),
                ];
            })
        ]);
    }
}

