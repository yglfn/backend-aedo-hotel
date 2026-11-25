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
            'cancel_reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'cancel_reason.required' => 'Alasan pembatalan wajib diisi.',
            'cancel_reason.string'   => 'Alasan pembatalan harus berupa teks.',
            'cancel_reason.min'      => 'Alasan pembatalan minimal 5 karakter.',
            'cancel_reason.max'      => 'Alasan pembatalan maksimal 500 karakter.',
        ];
    }

    public function attributes(): array
    {
        return [
            'cancel_reason' => 'alasan pembatalan',
        ];
    }
}
