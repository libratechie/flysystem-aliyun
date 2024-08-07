<?php

/*
 * This file is part of the libratechie/flysystem-aliyun.
 *
 * (c) libratechie <libratechie@foxmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Libratechie\Flysystem\Aliyun;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\Flysystem\Config;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToRetrieveMetadata;
use OSS\Core\OssException;
use OSS\OssClient;

class AliyunAdapter implements FilesystemAdapter, TemporaryUrlGenerator
{
    protected ?OssClient $client = null;

    public function __construct(
        protected string $accessKeyId,
        protected string $accessKeySecret,
        protected string $endpoint,
        protected string $bucket,
    ) {
    }

    public function getClient(): OssClient
    {
        if (is_null($this->client)) {
            $this->client = new OssClient($this->accessKeyId, $this->accessKeySecret, $this->endpoint);
        }

        return $this->client;
    }

    public function fileExists(string $path): bool
    {
        return $this->getClient()->doesObjectExist($this->bucket, $path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->getClient()->putObject($this->bucket, $path, $contents, $this->getOssOptions($config));
        } catch (OssException $e) {
            throw UnableToWriteFile::atLocation($path, $e->getErrorMessage());
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if (!is_resource($contents)) {
            throw UnableToWriteFile::atLocation($path, 'The contents is invalid resource.');
        }
        $bufferSize = 1000000; // 1MB
        $i = 0;
        while (!feof($contents)) {
            $buffer = fread($contents, $bufferSize);
            if (false === $buffer) {
                throw UnableToWriteFile::atLocation($path, 'fread failed');
            }
            $position = $i * $bufferSize;
            try {
                $this->getClient()->appendObject($this->bucket, $path, $buffer, $position, $this->getOssOptions($config));
            } catch (OssException $e) {
                throw UnableToWriteFile::atLocation($path, $e->getErrorMessage());
            }
            ++$i;
        }
        fclose($contents);
    }

    public function read(string $path): string
    {
        try {
            return $this->getClient()->getObject($this->bucket, $path);
        } catch (OssException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getErrorMessage());
        }
    }

    public function readStream(string $path)
    {
        $contents = $this->read($path);
        $resource = fopen('php://temp', 'r+');
        if ('' !== $contents) {
            fwrite($resource, $contents);
            fseek($resource, 0);
        }

        return $resource;
    }

    public function delete(string $path): void
    {
        try {
            $this->getClient()->deleteObject($this->bucket, $path);
        } catch (OssException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getErrorMessage());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $lists = $this->listContents($path, true);
        if (!$lists) {
            return;
        }
        $objectList = [];
        foreach ($lists as $value) {
            $objectList[] = $value['path'];
        }
        try {
            $this->getClient()->deleteObjects($this->bucket, $objectList);
        } catch (OssException $e) {
            throw UnableToDeleteFile::atLocation($path, $e->getErrorMessage());
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->getClient()->createObjectDir($this->bucket, $path);
        } catch (OssException $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getErrorMessage());
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $this->getClient()->putObjectAcl(
                $this->bucket,
                $path,
                ('public' == $visibility) ? 'public-read' : 'private'
            );
        } catch (OssException $e) {
            throw UnableToSetVisibility::atLocation($path, $e->getErrorMessage());
        }
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $response = $this->getClient()->getObjectAcl($this->bucket, $path);
        } catch (OssException $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getErrorMessage());
        }

        return new FileAttributes($path, null, $response);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->getMetadata($path);
    }

    public function getMetadata(string $path): FileAttributes
    {
        try {
            $response = $this->getClient()->getObjectMeta($this->bucket, $path);
        } catch (OssException $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getErrorMessage());
        }

        return new FileAttributes($path, $response['content-length'], null, strtotime($response['last-modified']), $response['content-type']);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $directory = rtrim($path, '\\/');
        $nextMarker = '';
        while (true) {
            $options = [
                'max-keys' => 1000,
                'prefix' => $directory.'/',
                'delimiter' => '/',
                'marker' => $nextMarker,
            ];
            // Get the client and request to list objects
            $res = $this->getClient()->listObjects($this->bucket, $options);
            // Update nextMarker to fetch the next batch of objects
            $nextMarker = $res->getNextMarker();
            // Process the list of directory prefixes and recursively process subdirectories
            yield from $this->processPrefixList($res->getPrefixList(), $deep);
            // Process the list of file objects
            yield from $this->processObjectList($res->getObjectList(), $directory);
            // If nextMarker is empty, it indicates the end; exit the loop
            if ('' === $nextMarker) {
                break;
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->getClient()->copyObject($this->bucket, $source, $this->bucket, $destination);
            $this->getClient()->deleteObject($this->bucket, $source);
        } catch (OssException) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->getClient()->copyObject($this->bucket, $source, $this->bucket, $destination);
        } catch (OssException) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    public function temporaryUrl(string $path, \DateTimeInterface $expiresAt, Config $config): string
    {
        try {
            return $this->getClient()->generatePresignedUrl($this->bucket, $path, $expiresAt->getTimestamp());
        } catch (OssException $e) {
            throw UnableToReadFile::fromLocation($path, $e->getErrorMessage());
        }
    }

    protected function processPrefixList(array $prefixList, bool $deep): iterable
    {
        if ($prefixList) {
            foreach ($prefixList as $value) {
                yield [
                    'type' => 'dir',
                    'path' => $value->getPrefix(),
                ];
                // If recursive processing of subdirectories is needed, call the listContents method
                if ($deep) {
                    yield from $this->listContents($value->getPrefix(), $deep);
                }
            }
        }
    }

    protected function processObjectList(array $objectList, string $directory): iterable
    {
        if ($objectList) {
            foreach ($objectList as $value) {
                // Skip objects with size 0 and paths that match the directory
                if ((0 === $value->getSize()) && ($value->getKey() === $directory.'/')) {
                    continue;
                }
                yield [
                    'type' => 'file',
                    'path' => $value->getKey(),
                    'timestamp' => strtotime($value->getLastModified()),
                    'size' => $value->getSize(),
                ];
            }
        }
    }

    public function getOssOptions(Config $config): array
    {
        $options = [];
        $optionsKey = [OssClient::OSS_HEADERS, OssClient::OSS_CONTENT_TYPE, OssClient::OSS_CHECK_MD5];
        foreach ($optionsKey as $key) {
            if ($value = $config->get($key)) {
                $options[$key] = $value;
            }
        }

        return $options;
    }
}
