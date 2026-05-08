<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class OcrReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp,heic',
                'max:8192', // 8 MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'An image file is required (multipart field "image").',
            'image.image' => 'The uploaded file must be a receipt image.',
            'image.max' => 'Receipt image must be 8 MB or smaller.',
        ];
    }
}
