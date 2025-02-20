<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Filesystem\Dbafs;

use Contao\CoreBundle\File\Metadata;
use Contao\CoreBundle\Filesystem\Dbafs\RetrieveDbafsMetadataEvent;
use Contao\CoreBundle\Filesystem\Dbafs\StoreDbafsMetadataEvent;
use Contao\CoreBundle\Filesystem\ExtraMetadata;
use Contao\CoreBundle\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

class StoreDbafsMetadataEventTest extends TestCase
{
    public function testSetAndGetValues(): void
    {
        $uuid = Uuid::v1();

        $rowData = [
            'uuid' => $uuid->toBinary(),
            'path' => 'foo/bar',
            'baz' => 42,
        ];

        $extraMetadata = new ExtraMetadata([
            'foo' => new Metadata(['some' => 'value']),
        ]);

        $event = new StoreDbafsMetadataEvent('tl_files', $rowData, $extraMetadata);

        $this->assertSame('tl_files', $event->getTable());
        $this->assertSame($uuid->toBinary(), $event->getUuid()->toBinary());
        $this->assertSame('foo/bar', $event->getPath());
        $this->assertSame($extraMetadata, $event->getExtraMetadata());
        $this->assertSame($rowData, $event->getRow());

        $event->set('foo', $event->getExtraMetadata()->get('foo')->all());

        $this->assertSame(
            [
                'uuid' => $uuid->toBinary(),
                'path' => 'foo/bar',
                'baz' => 42,
                'foo' => ['some' => 'value'],
            ],
            $event->getRow(),
        );
    }

    #[DataProvider('provideRowData')]
    public function testEnforcesRequiredValues(array $row, string $expectedExceptionMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new RetrieveDbafsMetadataEvent('tl_foo', $row);
    }

    public static function provideRowData(): iterable
    {
        yield 'path missing' => [
            ['foo' => 'bar', 'uuid' => '12345'],
            'Row must contain key "path".',
        ];

        yield 'path has wrong type' => [
            ['path' => 123, 'uuid' => '12345'],
            'Row key "path" must be of type string, got integer.',
        ];

        yield 'uuid missing' => [
            ['path' => 'foo/bar', 'baz' => 42],
            'Row must contain key "uuid".',
        ];

        yield 'uuid has wrong type' => [
            ['uuid' => new \stdClass(), 'path' => 'foo/bar'],
            'Row key "uuid" must be of type string, got object.',
        ];
    }
}
