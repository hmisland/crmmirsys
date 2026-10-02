<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function test_singleton_returns_the_same_instance_every_time(): void
    {
        $container = new Container();
        $container->singleton(\stdClass::class, fn () => new \stdClass());

        $first = $container->make(\stdClass::class);
        $second = $container->make(\stdClass::class);

        $this->assertSame($first, $second);
    }

    public function test_bind_returns_a_new_instance_every_time(): void
    {
        $container = new Container();
        $container->bind(\stdClass::class, fn () => new \stdClass());

        $first = $container->make(\stdClass::class);
        $second = $container->make(\stdClass::class);

        $this->assertNotSame($first, $second);
    }

    public function test_instance_registers_an_already_built_object(): void
    {
        $container = new Container();
        $object = new \stdClass();
        $container->instance(\stdClass::class, $object);

        $this->assertSame($object, $container->make(\stdClass::class));
    }
}
