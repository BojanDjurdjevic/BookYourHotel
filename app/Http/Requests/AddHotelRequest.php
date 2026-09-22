<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddHotelRequest extends FormRequest
{

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:64',
            'star_rating' => 'nullable|integer|between:1,5',
            'country' => 'required|string|min:3|max:64',
            'city' => 'required|string|min:3|max:64',
            'address' => 'required|string|max:128',
            'description' => 'nullable|string|max:255',
            'facilities' => $this->user()?->is_demo_sandbox ? 'nullable|array|max:32' : 'nullable|array',
            'facilities.*' => $this->user()?->is_demo_sandbox ? 'string|max:100' : 'string',
        ];
    }
}
