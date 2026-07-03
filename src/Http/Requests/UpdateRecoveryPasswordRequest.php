<?php

declare(strict_types=1);

namespace Codenzia\SuperAdmin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Validates the break-glass recovery password update.
 *
 * `authorize()` returns true unconditionally — the reset token IS the
 * authorization for this unauthenticated flow. The controller still checks
 * `Password::broker()->tokenExists()` itself before applying the change.
 */
final class UpdateRecoveryPasswordRequest extends FormRequest
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
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(12)->max(255)],
        ];
    }
}
