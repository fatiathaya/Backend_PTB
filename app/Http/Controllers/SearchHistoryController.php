<?php

namespace App\Http\Controllers;

use App\Models\SearchHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SearchHistoryController extends Controller
{
    /**
     * Get user's search history (most recent first, limit 10).
     */
    public function index(Request $request)
    {
        $histories = SearchHistory::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get(['id', 'query', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => $histories
        ]);
    }

    /**
     * Store a new search query.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $queryValue = (string) $request->input('query');

        // Check if query already exists for this user
        $existingHistory = SearchHistory::where('user_id', $request->user()->id)
            ->where('query', $queryValue)
            ->first();

        if ($existingHistory) {
            // Update timestamp to make it most recent
            $existingHistory->touch();
            
            return response()->json([
                'success' => true,
                'message' => 'Search history updated',
                'data' => $existingHistory
            ]);
        }

        // Create new search history
        $history = SearchHistory::create([
            'user_id' => $request->user()->id,
            'query' => $queryValue,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Search history saved',
            'data' => $history
        ], 201);
    }

    /**
     * Delete a specific search history.
     */
    public function destroy(Request $request, $id)
    {
        $history = SearchHistory::find($id);

        if (!$history) {
            return response()->json([
                'success' => false,
                'message' => 'Search history not found'
            ], 404);
        }

        // Check if user owns this history
        if ($history->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $history->delete();

        return response()->json([
            'success' => true,
            'message' => 'Search history deleted'
        ]);
    }

    /**
     * Clear all search history for authenticated user.
     */
    public function clear(Request $request)
    {
        $deleted = SearchHistory::where('user_id', $request->user()->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'All search history cleared',
            'deleted_count' => $deleted
        ]);
    }
}
