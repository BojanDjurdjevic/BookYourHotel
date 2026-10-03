<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GuestBookingCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() === null;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'uuid'],
            'code' => $this->routeIs('booking.verification.verify') ? ['required', 'string', 'regex:/\A[0-9]{6}\z/'] : ['prohibited'],
            'guest_email' => ['prohibited'],
            'items' => ['prohibited'],
            'hotel_id' => ['prohibited'],
        ];
    }
}
