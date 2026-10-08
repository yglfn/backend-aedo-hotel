<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CancelBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorisasi via middleware
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'min:5'],
        ];
    }
}
