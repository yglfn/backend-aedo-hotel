<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReviewRequest;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json([
            'data' => Review::with(['booking:id,booking_code,customer_id', 'booking.customer:id,name', 'createdBy:id,name'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function store(StoreReviewRequest $request, Booking $booking)
    {
        if ($booking->review()->exists()) {
            return response()->json(['message' => 'Reservasi ini sudah memiliki review.'], 422);
        }

        try {
            $review = Review::create([
                'booking_id' => $booking->id,
                'created_by' => $request->user()->id,
                'rating' => $request->validated('rating'),
                'comment' => $request->validated('comment'),
            ]);

            return response()->json([
                'message' => 'Review berhasil disimpan.',
                'data' => $review->load('createdBy:id,name'),
            ], 201);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json(['message' => 'Reservasi ini sudah memiliki review.'], 422);
            }

            throw $e;
        }
    }
}
