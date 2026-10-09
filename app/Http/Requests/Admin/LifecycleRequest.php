<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class LifecycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() && ($this->route('hotel')
            ? $this->user()->can('archive', $this->route('hotel'))
            : ($this->user()->can('view', $this->route('supplier'))
                && $this->user()->can('deactivate', $this->route('supplier'))));
    }

    public function rules(): array
    {
        return ['confirmed' => ['required', 'accepted']];
    }
}
