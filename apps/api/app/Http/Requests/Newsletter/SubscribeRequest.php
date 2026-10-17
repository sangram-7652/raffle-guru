<?php

namespace App\Http\Requests\Newsletter;

use Illuminate\Foundation\Http\FormRequest;

class SubscribeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * No `unique` rule here — a repeat submission of an already-subscribed
     * (or previously unsubscribed) address is handled as a normal,
     * idempotent success by the controller, not a validation error. That
     * also avoids using this endpoint to probe which emails are on the list.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
