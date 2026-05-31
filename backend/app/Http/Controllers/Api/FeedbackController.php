<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeedbackMessage;
use App\Services\LegacySchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FeedbackController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'content' => ['required', 'string', 'max:5000'],
        ]);

        if (! LegacySchema::feedbackReady()) {
            Log::error('Feedback table is not available');

            return response()->json([
                'message' => 'Unable to send message right now. Please try again later.',
            ], 503);
        }

        FeedbackMessage::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'content' => $data['content'],
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'sent']);
    }
}
