<?php

/*
 * This file is part of the libratechie/flysystem-aliyun.
 *
 * (c) libratechie <libratechie@foxmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Libratechie\Flysystem\Aliyun\Tests;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use OSS\Core\OssException;
use OSS\Model\ObjectInfo;
use OSS\Model\PrefixInfo;
use OSS\Model\ObjectListInfo;
use OSS\OssClient;
use Libratechie\Flysystem\Aliyun\AliyunAdapter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Mockery;

class AliyunAdapterTest extends TestCase
{
    private static string $lastModifiedString = '2024-08-01T08:08:27.000Z';

    public static function aliyunProvider(): array
    {
        $adapter = Mockery::mock(AliyunAdapter::class, ['accessKey', 'secretKey', 'endpoint', 'bucket'])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $adapter->expects()->getOssOptions(new Config())
            ->andReturn([]);

        $client = Mockery::mock(OssClient::class);
        $client->allows()->andReturns(OssClient::class);
        $adapter->allows([
            'getClient' => $client,
        ]);

        return [
            [$adapter, $client],
        ];
    }

    #[DataProvider('aliyunProvider')]
    public function testFileExists($adapter, $client)
    {
        $client->expects()->doesObjectExist('bucket', 'foo/boo.md')
            ->andReturns(true, false)
            ->twice();

        $this->assertTrue($adapter->fileExists('foo/boo.md'));
        $this->assertFalse($adapter->fileExists('foo/boo.md'));
    }

    #[DataProvider('aliyunProvider')]
    public function testWrite($adapter, $client)
    {
        $client->expects()->putObject('bucket', 'foo/bar.md', 'content', [])
            ->andReturn([])
            ->once();
        $this->assertNull($adapter->write('foo/bar.md', 'content', new Config()));

        $message = 'The specified bucket does not exist.';
        $client->expects()->putObject('bucket', 'foo/bar.md', 'content', [])
            ->andThrow(new OssException(['code' => '404', 'request-id' => uniqid('', true), 'message' => $message]));
        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage($message);

        $adapter->write('foo/bar.md', 'content', new Config());
    }

    #[DataProvider('aliyunProvider')]
    public function testWriteStream($adapter, $client)
    {
        // 打开一个内存中的流
        $resource = fopen('php://memory', 'r+');
        fwrite($resource, str_repeat('A', 1024 * 100));
        fseek($resource, 0);

        $message = 'The specified bucket does not exist.';
        $client->expects()->appendObject('bucket', 'foo/bar.md', fread($resource, 1000000), 0, [])
            ->andThrow(new OssException(['code' => '404', 'request-id' => uniqid('', true), 'message' => $message]));
        fseek($resource, 0);

        $this->expectException(UnableToWriteFile::class);
        $adapter->writeStream('foo/bar.md', $resource, new Config());

        $this->expectException(UnableToWriteFile::class);
        $this->expectExceptionMessage('The contents is invalid resource.');
        $adapter->writeStream('foo/bar.md', 'invalid resource', new Config());
    }

    #[DataProvider('aliyunProvider')]
    public function testRead($adapter, $client)
    {
        $message = 'The specified bucket does not exist.';
        $client->expects()->getObject('bucket', 'foo/bar.md')
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage($message);
        $adapter->read('foo/bar.md');
    }

    #[DataProvider('aliyunProvider')]
    public function testReadStream($adapter, $client)
    {
        $adapter->expects()->read('foo/boo.md')
            ->andReturn('string', '')
            ->twice();
        $this->assertSame(fread($adapter->readStream('foo/boo.md'), 6), 'string');
        $this->assertSame(fread($adapter->readStream('foo/boo.md'), 6), '');
    }

    #[DataProvider('aliyunProvider')]
    public function testDelete($adapter, $client)
    {
        $client->expects()->deleteObject('bucket', 'foo/boo.md')
            ->andReturn([])
            ->once();
        $this->assertNull($adapter->delete('foo/boo.md'));

        $message = 'The specified bucket does not exist.';
        $client->expects()->deleteObject('bucket', 'foo/bar.md')
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToDeleteFile::class);
        $this->expectExceptionMessage($message);
        $adapter->delete('foo/bar.md');
    }

    #[DataProvider('aliyunProvider')]
    public function testDeleteDirectory($adapter, $client)
    {
        $objectList = [
            new ObjectInfo('foo/bar.md', self::$lastModifiedString, '', '', '1024', ''),
            new ObjectInfo('foo/boo.md', self::$lastModifiedString, '', '', '1024', ''),
        ];
        $client->expects()
            ->listObjects('bucket', ['max-keys' => 1000, 'prefix' => 'foo/', 'delimiter' => '/', 'marker' => ''])
            ->andReturn(new ObjectListInfo('bucket', 'foo/', '', '', 1000, '/', '', $objectList, []))
            ->once();

        $message = 'The specified bucket does not exist.';
        $client->expects()->deleteObjects('bucket', ['foo/bar.md', 'foo/boo.md'])
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToDeleteFile::class);
        $this->expectExceptionMessage($message);
        $adapter->deleteDirectory('foo/');
    }

    #[DataProvider('aliyunProvider')]
    public function testCreateDirectory($adapter, $client)
    {
        $client->expects()->createObjectDir('bucket', 'foo')
            ->andReturn([])
            ->once();
        $this->assertNull($adapter->createDirectory('foo', new Config()));

        $message = 'The specified bucket does not exist.';
        $client->expects()->createObjectDir('bucket', 'foo')
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToCreateDirectory::class);
        $this->expectExceptionMessage($message);
        $adapter->createDirectory('foo', new Config());
    }

    #[DataProvider('aliyunProvider')]
    public function testSetVisibility($adapter, $client)
    {
        $client->expects()->putObjectAcl('bucket', 'foo/boo.md', 'public-read')
            ->andReturn([])
            ->once();
        $this->assertNull($adapter->setVisibility('foo/boo.md', 'public'));

        $message = 'The specified bucket does not exist.';
        $client->expects()->putObjectAcl('bucket', 'foo/bar.md', 'public-read')
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToSetVisibility::class);
        $this->expectExceptionMessage($message);
        $adapter->setVisibility('foo/bar.md', 'public');
    }

    #[DataProvider('aliyunProvider')]
    public function testVisibility($adapter, $client)
    {
        $path = 'foo/boo.md';
        $client->expects()->getObjectAcl('bucket', $path)
            ->andReturn('public-read')
            ->once();
        $asser = new FileAttributes($path, null, 'public-read');
        $this->assertEquals($adapter->visibility($path), $asser);

        $message = 'The specified bucket does not exist.';
        $client->expects()->getObjectAcl('bucket', $path)
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToRetrieveMetadata::class);
        $this->expectExceptionMessage($message);
        $adapter->visibility($path);
    }

    public static function metadataProvider(): array
    {
        $provider = self::aliyunProvider();
        $adapter = $provider[0][0];
        $client = $provider[0][1];

        return [
            [$adapter, $client, 'mimeType'],
            [$adapter, $client, 'lastModified'],
            [$adapter, $client, 'fileSize'],
        ];
    }

    #[DataProvider('metadataProvider')]
    public function testGetMetadata($adapter, $client, $method)
    {
        $path = 'foo/boo.md';
        $client->expects()->getObjectMeta('bucket', $path)
            ->andReturn([
                'content-length' => '1024',
                'last-modified' => self::$lastModifiedString,
                'content-type' => 'text/markdown',
            ])
            ->once();
        $asser = new FileAttributes($path, '1024', null, strtotime(self::$lastModifiedString), 'text/markdown');
        $this->assertEquals($adapter->$method($path), $asser);

        $message = 'The specified bucket does not exist.';
        $client->expects()->getObjectMeta('bucket', $path)
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToRetrieveMetadata::class);
        $this->expectExceptionMessage($message);
        $adapter->$method($path);
    }

    #[DataProvider('aliyunProvider')]
    public function testListContents($adapter, $client)
    {
        $objectList = [
            new ObjectInfo('foo/', self::$lastModifiedString, '', '', '0', ''),
            new ObjectInfo('foo/bar.md', self::$lastModifiedString, '', '', '1024', ''),
            new ObjectInfo('foo/boo.md', self::$lastModifiedString, '', '', '1024', ''),
        ];
        $prefixList = [new PrefixInfo('foo/subfolder/')];
        $client->expects()
            ->listObjects('bucket', ['max-keys' => 1000, 'prefix' => 'foo/', 'delimiter' => '/', 'marker' => ''])
            ->andReturn(new ObjectListInfo('bucket', 'foo/', '', '', 1000, '/', '', $objectList, $prefixList))
            ->once();
        // Subfolder traversal
        $objectList = [
            new ObjectInfo('foo/subfolder/', self::$lastModifiedString, '', '', '0', ''),
            new ObjectInfo('foo/subfolder/sub_file.md', self::$lastModifiedString, '', '', '1024', ''),
        ];
        $client->expects()
            ->listObjects('bucket', ['max-keys' => 1000, 'prefix' => 'foo/subfolder/', 'delimiter' => '/', 'marker' => ''])
            ->andReturn(new ObjectListInfo('bucket', 'foo/', '', '', 1000, '/', '', $objectList, []))
            ->once();

        $res = $adapter->listContents('foo/', true);

        $timestamp = \DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', self::$lastModifiedString, new \DateTimeZone('UTC'))->getTimestamp();
        $asserts = [
            [
                'type' => 'dir',
                'path' => 'foo/subfolder/',
            ],
            [
                'type' => 'file',
                'path' => 'foo/subfolder/sub_file.md',
                'timestamp' => $timestamp,
                'size' => 1024,
            ],
            [
                'type' => 'file',
                'path' => 'foo/bar.md',
                'timestamp' => $timestamp,
                'size' => 1024,
            ],
            [
                'type' => 'file',
                'path' => 'foo/boo.md',
                'timestamp' => $timestamp,
                'size' => 1024,
            ],
        ];
        foreach ($res as $item) {
            $this->assertEquals(array_shift($asserts), $item);
        }
    }

    #[DataProvider('aliyunProvider')]
    public function testMove($adapter, $client)
    {
        $client->expects()->copyObject('bucket', 'foo/boo.md', 'bucket', 'foo/new_boo.md')->andReturn([])->once();
        $client->expects()->deleteObject('bucket', 'foo/boo.md')->andReturn([])->once();
        $this->assertNull($adapter->move('foo/boo.md', 'foo/new_boo.md', new Config()));

        $message = 'The specified bucket does not exist.';
        $client->expects()
            ->copyObject('bucket', 'foo/boo.md', 'bucket', 'foo/copy.md')
            ->andThrow(
                new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message])
            );

        $this->expectException(UnableToMoveFile::class);
        $adapter->move('foo/boo.md', 'foo/copy.md', new Config());
    }

    #[DataProvider('aliyunProvider')]
    public function testCopy($adapter, $client)
    {
        $client->expects()->copyObject('bucket', 'foo/boo.md', 'bucket', 'foo/copy.md')->andReturn([])->once();
        $this->assertNull($adapter->copy('foo/boo.md', 'foo/copy.md', new Config()));

        $message = 'The specified bucket does not exist.';
        $client->expects()
            ->copyObject('bucket', 'foo/boo.md', 'bucket', 'foo/copy.md')
            ->andThrow(
                new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message])
            );

        $this->expectException(UnableToCopyFile::class);
        $adapter->copy('foo/boo.md', 'foo/copy.md', new Config());
    }

    #[DataProvider('aliyunProvider')]
    public function testTemporaryUrl($adapter, $client)
    {
        $expiresAt = new \DateTimeImmutable('+1 hour');
        $message = 'The specified bucket does not exist.';
        $client->expects()->generatePresignedUrl('bucket', 'foo/bar.md', $expiresAt->getTimestamp())
            ->andThrow(new OssException(['code' => 'NoSuchBucket', 'request-id' => uniqid('', true), 'message' => $message]));

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage($message);
        $adapter->temporaryUrl('foo/bar.md', $expiresAt, new Config());
    }
}
