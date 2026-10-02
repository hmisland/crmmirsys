<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Modules\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = sys_get_temp_dir() . '/crm-module-test-' . uniqid();
        mkdir($this->fixtureDir . '/Sales', 0777, true);
        file_put_contents(
            $this->fixtureDir . '/Sales/module.json',
            json_encode([
                'key' => 'sales',
                'name' => 'Sales',
                'version' => '1.0.0',
                'type' => 'optional',
                'is_core' => false,
                'dependencies' => ['core'],
            ])
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->fixtureDir . '/Sales/module.json');
        @rmdir($this->fixtureDir . '/Sales');
        @rmdir($this->fixtureDir);
    }

    public function test_parses_a_valid_manifest(): void
    {
        $manifest = ModuleManifest::fromFile($this->fixtureDir . '/Sales/module.json');

        $this->assertSame('sales', $manifest->key);
        $this->assertSame('Sales', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('optional', $manifest->type);
        $this->assertFalse($manifest->isCore);
        $this->assertSame(['core'], $manifest->dependencies);
    }

    public function test_throws_on_missing_key(): void
    {
        $file = $this->fixtureDir . '/Sales/invalid.json';
        file_put_contents($file, json_encode(['name' => 'No key']));

        $this->expectException(\RuntimeException::class);

        try {
            ModuleManifest::fromFile($file);
        } finally {
            @unlink($file);
        }
    }
}
