<?php

namespace App\Http\Requests;

use App\Enums\BookingSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorisasi via middleware
    }

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
            'customer_id' => ['required', 'exists:customers,id'],
            'source' => ['required', new Enum(BookingSource::class)],
            'check_in' => ['required', 'date', 'after_or_equal:today'],
            'check_out' => ['required', 'date', 'after:check_in'],
            'guest_count' => ['required', 'integer', 'min:1'],
            // Diskon manual; nilainya dijaga <= subtotal di BookingService (aturan no. 4)
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string'],
            
            // Rooms to book
            'rooms' => ['required', 'array', 'min:1'],
            'rooms.*' => ['required', 'exists:rooms,id'],
            
            // Guests: penanggung jawab wajib, penghuni lain boleh menyusul saat check-in.
            // Jumlah baris tidak boleh melebihi guest_count supaya data tidak menyangkal angka reservasi.
            'guests' => ['required', 'array', 'min:1'],
            'guests.*.name' => ['required', 'string', 'max:255'],
            'guests.*.ktp' => ['nullable', 'string'],
            'guests.*.phone' => ['nullable', 'string', 'max:20'],
            // Satu kamar: penempatan tidak ambigu jadi boleh dikosongkan (service menautkan
            // otomatis). Multi-kamar: tiap penghuni wajib punya kamar sendiri.
            'guests.*.room_id' => [
                Rule::requiredIf(fn () => count((array) $this->input('rooms', [])) > 1),
                'nullable',
                'integer',
                'exists:rooms,id',
            ],
            'guests.*.is_primary' => ['required', 'boolean'],
            
            // Additional Services (Optional)
            'services' => ['nullable', 'array'],
            'services.*.service_id' => ['required_with:services', 'exists:services,id'],
            'services.*.qty' => ['required_with:services', 'integer', 'min:1'],
            'services.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_out.after' => 'Tanggal check-out harus setelah tanggal check-in.',
            'check_in.after_or_equal' => 'Tanggal check-in tidak boleh sebelum hari ini.',
            'rooms.required' => 'Minimal satu kamar harus dipilih.',
            'guests.required' => 'Data tamu wajib diisi.',
            'guests.min' => 'Minimal satu tamu (penanggung jawab reservasi) harus diisi.',
            'guests.*.name.required' => 'Nama penghuni wajib diisi.',
            'guests.*.room_id.required' => 'Pilih kamar untuk setiap penghuni pada reservasi multi-kamar.',
            'guests.*.room_id.exists' => 'Kamar untuk penghuni harus salah satu kamar yang dipilih pada reservasi ini.',
            'guest_count.min' => 'Jumlah tamu minimal 1 orang.',
            'discount_amount.numeric' => 'Nilai diskon harus berupa angka.',
            'discount_amount.min' => 'Nilai diskon tidak boleh negatif.',
            'services.*.service_id.required' => 'Layanan tambahan wajib dipilih.',
            'services.*.qty.required' => 'Jumlah layanan wajib diisi.',
            'services.*.qty.min' => 'Jumlah layanan minimal 1.',
        ];
    }

    /**
     * Aturan lintas field: data tamu harus nyambung dengan guest_count, dan hanya boleh
     * ada satu penanggung jawab. Diletak di sini supaya validasi tetap jalan walau
     * controller berubah.
     */
    public function after(): array
    {
        return [
            function (\Illuminate\Validation\Validator $validator) {
                $guests = $this->input('guests', []);
                $guests = is_array($guests) ? $guests : [];
                $count  = (int) $this->input('guest_count', 0);

                if ($count > 0 && count($guests) > $count) {
                    $validator->errors()->add(
                        'guests',
                        'Jumlah data tamu (' . count($guests) . ') melebihi jumlah tamu yang dipesan (' . $count . ' orang).'
                    );
                }

                $primary = collect($guests)->filter(fn ($guest) => !empty($guest['is_primary']))->count();
                if ($primary !== 1) {
                    $validator->errors()->add('guests', 'Harus ada tepat satu penanggung jawab reservasi (is_primary).');
                }
            },
        ];
    }
}
