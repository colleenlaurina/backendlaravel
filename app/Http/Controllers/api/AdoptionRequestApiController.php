<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AdoptionRequest;
use App\Models\Pet;
use Illuminate\Support\Facades\DB;

class AdoptionRequestApiController extends Controller
{
    // Get user's adoption requests
    public function myRequests(Request $request)
    {
        $requests = AdoptionRequest::with(['pet.user'])
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($req) {
                if ($req->pet && $req->pet->image) {
                    $req->pet->image_url = asset('storage/' . $req->pet->image);
                }
                return $req;
            });

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    // Get requests for user's pets (pet owner view)
public function myPetRequests(Request $request)
{
    $requests = AdoptionRequest::with(['pet', 'user'])
        ->whereHas('pet', function ($query) use ($request) {
            $query->where('user_id', $request->user()->id);
        })
        ->orderBy('created_at', 'desc')
        ->get()
        ->map(function ($req) {
            if ($req->pet && $req->pet->image) {
                $req->pet->image_url = asset('storage/' . $req->pet->image);
            }
            // Cast IDs to integers
            $req->id = (int) $req->id;
            $req->user_id = (int) $req->user_id;
            $req->pet_id = (int) $req->pet_id;
            if ($req->pet) {
                $req->pet->id = (int) $req->pet->id;
                $req->pet->user_id = (int) $req->pet->user_id;
            }
            if ($req->user) {
                $req->user->id = (int) $req->user->id;
            }
            return $req;
        })
        ->values();

    return response()->json($requests);
}

    // Create adoption request
    public function store(Request $request)
    {
        $validated = $request->validate([
            'pet_id' => 'required|exists:pets,id',
            'message' => 'required|string|min:20',
        ]);

        $pet = Pet::findOrFail($validated['pet_id']);

        // Check if pet is available
        if ($pet->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'This pet is no longer available'
            ], 400);
        }

        // Check if user already has pending request for this pet
        $existingRequest = AdoptionRequest::where('user_id', $request->user()->id)
            ->where('pet_id', $validated['pet_id'])
            ->where('status', 'pending')
            ->first();

        if ($existingRequest) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending request for this pet'
            ], 400);
        }

        // Check if user is trying to adopt their own pet
        if ($pet->user_id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot request your own pet'
            ], 400);
        }

        $adoptionRequest = AdoptionRequest::create([
            'user_id' => $request->user()->id,
            'pet_id' => $validated['pet_id'],
            'message' => $validated['message'],
            'status' => 'pending',
        ]);

        $adoptionRequest->load('pet.user');

        if ($adoptionRequest->pet && $adoptionRequest->pet->image) {
            $adoptionRequest->pet->image_url = asset('storage/' . $adoptionRequest->pet->image);
        }

        return response()->json([
            'success' => true,
            'message' => 'Request submitted successfully',
            'data' => $adoptionRequest
        ], 201);
    }

    // Cancel adoption request
    public function cancel(Request $request, $id)
    {
        $adoptionRequest = AdoptionRequest::findOrFail($id);

        // Check ownership
        if ($adoptionRequest->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        // Can only cancel pending requests
        if ($adoptionRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Can only cancel pending requests'
            ], 400);
        }

        $adoptionRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Request cancelled successfully'
        ]);
    }

    // Approve adoption request (pet owner)
    public function approve(Request $request, $id)
    {
        try {
            $adoptionRequest = AdoptionRequest::with('pet')->findOrFail($id);

            // Check if user owns the pet
            if ($adoptionRequest->pet->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Check if request is still pending
            if ($adoptionRequest->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Request already processed'
                ], 400);
            }

            DB::beginTransaction();

            // Update adoption request
            $adoptionRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            // Update pet status
            $adoptionRequest->pet->update(['status' => 'adopted']);

            // Reject all other pending requests for this pet
            AdoptionRequest::where('pet_id', $adoptionRequest->pet_id)
                ->where('id', '!=', $id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'admin_notes' => 'Pet was adopted by another user',
                    'reviewed_by' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);

            // Create adoption history record
            DB::table('adoption_history')->insert([
                'user_id' => $adoptionRequest->user_id,
                'pet_id' => $adoptionRequest->pet_id,
                'adoption_request_id' => $adoptionRequest->id,
                'adoption_date' => now()->toDateString(),
                'notes' => 'Approved by pet owner: ' . $request->user()->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Request approved successfully!'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    // Reject adoption request (pet owner)
    public function reject(Request $request, $id)
    {
        try {
            $adoptionRequest = AdoptionRequest::with('pet')->findOrFail($id);

            // Check if user owns the pet
            if ($adoptionRequest->pet->user_id !== $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            // Check if request is still pending
            if ($adoptionRequest->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Request already processed'
                ], 400);
            }

            // Update adoption request
            $adoptionRequest->update([
                'status' => 'rejected',
                'admin_notes' => $request->input('reason', 'Rejected by pet owner'),
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Request rejected'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }
}