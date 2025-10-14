<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreBuyerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Otorisasi akan kita tangani di controller
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
        ];
    }
}