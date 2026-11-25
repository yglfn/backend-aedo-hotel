<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddBookingServicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Kolom database memakai quantity, sementara payload lama memakai qty.
     * Terima keduanya agar Postman dan frontend lama tidak langsung putus.
     */
    protected function prepareForValidation(): void
    {
        $services = $this->input('services');

        if (!is_array($services)) {
            return;
        }

        foreach ($services as $index => $service) {
            if (is_array($service) && !array_key_exists('qty', $service) && array_key_exists('quantity', $service)) {
                $services[$index]['qty'] = $service['quantity'];
            }
        }

        $this->merge(['services' => $services]);
    }

    public function rules(): array
    {
        return [
            'services' => ['required', 'array', 'min:1'],
            'services.*.service_id' => ['required', 'integer', 'exists:services,id'],
            'services.*.qty' => ['required', 'integer', 'min:1'],
            'services.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'services.required' => 'Pilih minimal satu layanan tambahan.',
            'services.*.service_id.required' => 'Layanan tambahan wajib dipilih.',
            'services.*.service_id.exists' => 'Layanan tambahan tidak ditemukan.',
            'services.*.qty.required' => 'Jumlah layanan wajib diisi.',
            'services.*.qty.integer' => 'Jumlah layanan harus berupa bilangan bulat.',
            'services.*.qty.min' => 'Jumlah layanan minimal 1.',
        ];
    }
}
