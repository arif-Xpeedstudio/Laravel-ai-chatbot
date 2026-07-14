<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation moved out of the controller (Phase "Form Request, later").
 * The controller can now assume $request->validated() is clean.
 */
class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:2', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'অনুগ্রহ করে একটি মেসেজ লিখুন।',
            'message.min' => 'মেসেজটি কমপক্ষে ২ অক্ষরের হতে হবে।',
            'message.max' => 'মেসেজটি ৪০০০ অক্ষরের বেশি হতে পারবে না।',
        ];
    }
}
