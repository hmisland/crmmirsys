<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_required_field_missing_fails(): void
    {
        $v = Validator::make([], ['email' => 'required']);

        $this->assertTrue($v->fails());
        $this->assertArrayHasKey('email', $v->errors());
    }

    public function test_valid_email_passes_email_rule(): void
    {
        $v = Validator::make(['email' => 'a@b.com'], ['email' => 'required|email']);

        $this->assertFalse($v->fails());
    }

    public function test_invalid_email_fails(): void
    {
        $v = Validator::make(['email' => 'not-an-email'], ['email' => 'email']);

        $this->assertTrue($v->fails());
    }

    public function test_min_length_rule(): void
    {
        $this->assertTrue(Validator::make(['password' => 'short'], ['password' => 'min:8'])->fails());
        $this->assertFalse(Validator::make(['password' => 'longenough'], ['password' => 'min:8'])->fails());
    }

    public function test_confirmed_rule_requires_matching_field(): void
    {
        $fails = Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'different'],
            ['password' => 'confirmed']
        )->fails();
        $this->assertTrue($fails);

        $passes = Validator::make(
            ['password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['password' => 'confirmed']
        )->fails();
        $this->assertFalse($passes);
    }
}
