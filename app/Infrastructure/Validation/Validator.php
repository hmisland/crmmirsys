<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

/**
 * Deliberately small: covers what Phase 2's auth forms need
 * (required, email, min length, confirmed) rather than a general
 * validation framework. If later phases need more rule types, extend
 * the match() in run() — this is not meant to become a DSL.
 */
final class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    private function __construct(private readonly array $data, private readonly array $rules)
    {
        $this->run();
    }

    /**
     * @param array<string, string> $rules field => 'required|email|min:8'
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;

            foreach (explode('|', $ruleString) as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $this->applyRule($field, $value, $name, $param);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule, ?string $param): void
    {
        $isEmpty = $value === null || $value === '';

        match ($rule) {
            'required' => $isEmpty ? $this->fail($field, 'This field is required.') : null,
            'email' => (!$isEmpty && !filter_var($value, FILTER_VALIDATE_EMAIL))
                ? $this->fail($field, 'Must be a valid email address.')
                : null,
            'min' => (!$isEmpty && mb_strlen((string) $value) < (int) $param)
                ? $this->fail($field, "Must be at least {$param} characters.")
                : null,
            'confirmed' => ($value !== ($this->data[$field . '_confirmation'] ?? null))
                ? $this->fail($field, 'Confirmation does not match.')
                : null,
            default => null,
        };
    }

    private function fail(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }
}
