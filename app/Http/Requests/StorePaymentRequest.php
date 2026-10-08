<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount'           => ['required', 'numeric', 'min:1000'],
            'method'           => ['required', new Enum(PaymentMethod::class)],
            'type'             => ['required', new Enum(PaymentType::class)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes'            => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min'    => 'Nominal pembayaran minimal Rp 1.000.',
            'method.required' => 'Metode pembayaran wajib diisi.',
            'type.required'   => 'Tipe pembayaran wajib diisi.',
        ];
    }
}
