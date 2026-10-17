<?php

namespace App\Concerns;

use Closure;

trait ClinicalRowValidationRules
{
    protected function clinicalRowKeys(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            foreach (array_keys($value) as $key) {
                if (! is_int($key) || $key < 0 || $key > 1000000) {
                    $fail('The :attribute must use numbered rows.');

                    return;
                }
            }
        };
    }
}
