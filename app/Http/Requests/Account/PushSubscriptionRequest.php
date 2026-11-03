<?php

namespace App\Http\Requests\Account;

use App\Models\PushSubscription;
use App\Push\PushSubscriptions;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A browser's PushSubscription.toJSON() (POST /cuenta/avisos), plus the
 * content encoding it supports: an endpoint at a known push service and
 * its two base64url keys. Who may send it is the controller's job.
 */
class PushSubscriptionRequest extends FormRequest
{
    /** base64url, with or without padding. */
    public const BASE64URL = '/\A[A-Za-z0-9_-]+={0,2}\z/';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:'.PushSubscriptions::MAX_ENDPOINT_LENGTH, self::endpointRule(...)],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255', 'regex:'.self::BASE64URL],
            'keys.auth' => ['required', 'string', 'max:64', 'regex:'.self::BASE64URL],
            'contentEncoding' => ['nullable', 'string', Rule::in(PushSubscription::ENCODINGS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'required' => 'Falta un dato de la suscripción.',
            'regex' => 'La llave de la suscripción no es válida.',
            'max' => 'La suscripción es demasiado larga.',
            'contentEncoding.in' => 'Este navegador usa un cifrado que el portal no conoce.',
        ];
    }

    public static function endpointRule(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PushSubscriptions::allowsEndpoint($value)) {
            $fail('Este navegador usa un servicio de avisos que el portal no conoce.');
        }
    }
}
