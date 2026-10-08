<?php

namespace App\Http\Requests;

use App\Enums\BookingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorisasi via middleware
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'source' => ['required', new Enum(BookingSource::class)],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guest_count' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
            
            // Rooms to book
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*' => ['required', 'exists:rooms,id'],
            
            // Guests (Optional - minimal 1 primary guest)
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.ktp' => ['nullable', 'string'],
            'guests.*.is_primary' => ['required', 'boolean'],
            
            // Additional Services (Optional)
            'services' => ['nullable', 'array'],
            'services.*.service_id' => ['required_with:services', 'exists:services,id'],
            'services.*.qty' => ['required_with:services', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_out.after' => 'Tanggal check-out harus setelah tanggal check-in.',
            'rooms.required' => 'Minimal satu kamar harus dipilih.',
            'guests.required' => 'Data tamu wajib diisi.',
        ];
    }
}
