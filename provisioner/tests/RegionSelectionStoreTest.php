<?php

declare(strict_types=1);

namespace Provisioner\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Provisioner\RegionSelectionStore;

final class RegionSelectionStoreTest extends TestCase
{
    private string $tmpDir;

    private string $path;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/provisioner-test-'.uniqid('', true);
        mkdir($this->tmpDir, 0o755, true);
        $this->path = $this->tmpDir.'/regions.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function loadReturnsEmptyArrayWhenFileMissing(): void
    {
        $store = new RegionSelectionStore($this->path);

        self::assertFalse($store->exists());
        self::assertSame([], $store->load());
    }

    #[Test]
    public function saveThenLoadRoundTrips(): void
    {
        $store = new RegionSelectionStore($this->path);

        $store->save(['nord-pas-de-calais', 'bretagne']);

        self::assertTrue($store->exists());
        self::assertSame(['nord-pas-de-calais', 'bretagne'], $store->load());
    }

    #[Test]
    public function loadReturnsEmptyArrayWhenJsonIsCorrupted(): void
    {
        file_put_contents($this->path, '{not valid json');

        $store = new RegionSelectionStore($this->path);

        self::assertTrue($store->exists());
        self::assertSame([], $store->load());
    }

    #[Test]
    public function loadReturnsEmptyArrayWhenSlugsKeyMissing(): void
    {
        file_put_contents($this->path, json_encode(['other' => 'value']));

        $store = new RegionSelectionStore($this->path);

        self::assertSame([], $store->load());
    }

    #[Test]
    public function loadIgnoresNonStringSlugs(): void
    {
        file_put_contents($this->path, json_encode(['slugs' => ['valid', 42, null, '', 'bretagne']]));

        $store = new RegionSelectionStore($this->path);

        self::assertSame(['valid', 'bretagne'], $store->load());
    }

    #[Test]
    public function saveCreatesParentDirectoryIfMissing(): void
    {
        $nested = $this->tmpDir.'/nested/dir/regions.json';
        $store = new RegionSelectionStore($nested);

        $store->save(['alsace']);

        self::assertTrue(is_file($nested));
        self::assertSame(['alsace'], (new RegionSelectionStore($nested))->load());

        unlink($nested);
        rmdir($this->tmpDir.'/nested/dir');
        rmdir($this->tmpDir.'/nested');
    }

    #[Test]
    public function loadReturnsEmptyArrayOnEmptyFile(): void
    {
        file_put_contents($this->path, '');

        $store = new RegionSelectionStore($this->path);

        self::assertSame([], $store->load());
    }
}
