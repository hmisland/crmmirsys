<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\SuperAdminContext;
use PHPUnit\Framework\TestCase;

final class OrganizationContextTest extends TestCase
{
    protected function tearDown(): void
    {
        OrganizationContext::reset();
        SuperAdminContext::disable();
    }

    public function test_throws_when_no_organization_is_set(): void
    {
        $this->expectException(\RuntimeException::class);

        OrganizationContext::id();
    }

    public function test_returns_the_set_organization_id(): void
    {
        OrganizationContext::set(7);

        $this->assertTrue(OrganizationContext::has());
        $this->assertSame(7, OrganizationContext::id());
    }

    public function test_super_admin_run_disables_itself_even_after_an_exception(): void
    {
        try {
            SuperAdminContext::run(function () {
                $this->assertTrue(SuperAdminContext::isActive());
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(SuperAdminContext::isActive());
    }
}
