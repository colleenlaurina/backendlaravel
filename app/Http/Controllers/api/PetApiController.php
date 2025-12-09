<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Pet;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PetApiController extends Controller
{
    // Get all available pets (public)
    public function index()
    {
        $pets = Pet::with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($pet) {
                if ($pet->image) {
                    $pet->image_url = url('storage/' . $pet->image);
                }
                return $pet;
            });

       return response()->json($pets->values());
    }

    // Get single pet (public)
    public function show($id)
    {
        $pet = Pet::with('user:id,name,email')->findOrFail($id);

        if ($pet->image) {
            $pet->image_url = url('storage/' . $pet->image);
        }

        return response()->json($pet);
    }

    // Get authenticated user's pets
    public function myPets(Request $request)
    {
        $pets = Pet::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($pet) {
                if ($pet->image) {
                    $pet->image_url = url('storage/' . $pet->image);
                }
                return $pet;
            });

        return response()->json($pets->values());
    }

    // Create pet
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pet_name' => 'required|string|max:255',
            'category' => 'required|in:dog,cat',
            'breed' => 'nullable|string|max:255',
            'age' => 'nullable|integer|min:0',
            'gender' => 'nullable|in:male,female',
            'color' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'listing_type' => 'required|in:adopt,sell',
            'price' => 'required|numeric|min:0',
            'status' => 'required|in:available,adopted',
            'allergies' => 'nullable|string',
            'medications' => 'nullable|string',
            'food_preferences' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $validated['user_id'] = $request->user()->id;

        // Handle image upload
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $imagePath = $image->storeAs('pets', $imageName, 'public');
            $validated['image'] = $imagePath;
        }

        $pet = Pet::create($validated);

        if ($pet->image) {
            $pet->image_url = url('storage/' . $pet->image);
        }

        return response()->json([
            'message' => 'Pet created successfully',
            'pet' => $pet
        ], 201);
    }

    // Update pet
    public function update(Request $request, $id)
    {
        $pet = Pet::findOrFail($id);

        // Check ownership
        if ($pet->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'pet_name' => 'sometimes|required|string|max:255',
            'category' => 'sometimes|required|in:dog,cat',
            'breed' => 'nullable|string|max:255',
            'age' => 'nullable|integer|min:0',
            'gender' => 'nullable|in:male,female',
            'color' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'listing_type' => 'sometimes|required|in:adopt,sell',
            'price' => 'sometimes|required|numeric|min:0',
            'status' => 'sometimes|required|in:available,adopted',
            'allergies' => 'nullable|string',
            'medications' => 'nullable|string',
            'food_preferences' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // Handle image upload
        if ($request->hasFile('image')) {
            // Delete old image
            if ($pet->image) {
                Storage::disk('public')->delete($pet->image);
            }
            
            $image = $request->file('image');
            $imageName = time() . '_' . $image->getClientOriginalName();
            $imagePath = $image->storeAs('pets', $imageName, 'public');
            $validated['image'] = $imagePath;
        }

        $pet->update($validated);

        if ($pet->image) {
            $pet->image_url = url('storage/' . $pet->image);
        }

        return response()->json([
            'message' => 'Pet updated successfully',
            'pet' => $pet
        ]);
    }

    // Delete pet
    public function destroy(Request $request, $id)
    {
        $pet = Pet::findOrFail($id);

        // Check ownership
        if ($pet->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Delete image
        if ($pet->image) {
            Storage::disk('public')->delete($pet->image);
        }

        $pet->delete();

        return response()->json([
            'message' => 'Pet deleted successfully'
        ]);
    }
}