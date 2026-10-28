<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An IPv4/IPv6 address, or a CIDR range such as 203.0.113.0/24.
 */
class IpOrCidr implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->valid($value)) {
            $fail('":input" is not a valid IP address or CIDR range.');
        }
    }

    private function valid(string $value): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix >= 0 && (int) $prefix <= $max;
    }
}
