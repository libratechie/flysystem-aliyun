# Aliyun OSS Adapter For Flysystem.

<p>
<a href="https://packagist.org/packages/libratechie/flysystem-aliyun"><img src="https://poser.pugx.org/libratechie/flysystem-aliyun/version" alt="Version"></a>
<a href="https://github.com/libratechie/flysystem-aliyun/actions/workflows/tests.yml"><img src="https://github.com/libratechie/flysystem-aliyun/actions/workflows/tests.yml/badge.svg?branch=master" alt="Tests"></a>
<a href="https://packagist.org/packages/libratechie/flysystem-aliyun"><img src="https://poser.pugx.org/libratechie/flysystem-aliyun/license" alt="License"></a>
</p>


A Flysystem adapter for Aliyun OSS.

## Requirements

- `PHP` >= 8.2
- `league/flysystem` ^3.28
- `aliyuncs/oss-sdk-php` ^2.7

## Installation

You can install the package via Composer:

```bash
composer require libratechie/flysystem-aliyun
```

## Configuration
To use the Aliyun adapter, you need to configure it with your Aliyun OSS credentials and settings.

```php
use League\Flysystem\Filesystem;
use Libratechie\Flysystem\Aliyun\AliyunAdapter;

$accessKeyId = 'your-access-id';
$accessKeySecret = 'your-access-key';
$bucket = 'your-bucket-name';
$endpoint = 'your-endpoint'; // e.g., oss-cn-guangzhou.aliyuncs.com

$adapter = new AliyunAdapter($accessKeyId, $accessKeySecret, $endpoint, $bucket);

$config = [
    'public_url' => 'your-public-url',
    // e.g., https://your-bucket-name.oss-cn-guangzhou.aliyuncs.com
];
$filesystem = new Filesystem($adapter, $config);
```

## Usage
Here are some examples of how to use the filesystem with the Aliyun adapter:


### Checking
```php
// Checking if a File Exists
$filesystem->fileExists('path/to/file.txt');

// Checking if a Directory Exists
$filesystem->directoryExists('path/to');

// Checking if a File or Folder Exists
$filesystem->directoryExists('path/to/file.txt');
```

### Write
```php
// Writing a File
$filesystem->write('path/to/file.txt', 'contents');

// Write Use writeStream
$stream = fopen('local/path/to/file.txt', 'r+');
$filesystem->writeStream('path/to/file.txt', $stream);

// Create a directory
$filesystem->createDirectory('path/to/directory');

// Move a file
$filesystem->rename('path/to/file.txt', 'new/path/to/file.txt');

// Copy a file
$filesystem->copy('path/to/file.txt', 'new/path/to/file.txt');
```

### Visibility
```php
// Set the visibility of a file to 'public'
$filesystem->setVisibility('path/to/file.txt', 'public');

// Get the visibility of a file
// default: Inherits Bucket permissions. The read/write permissions of individual files are determined by the Bucket's read/write permissions.
// private: Private. All access operations to the file require authentication.
// public-read: Public read. Write operations require authentication, but the file can be read anonymously.
// public-read-write: Public read/write. Anyone (including anonymous visitors) can read and write the file.
$visibility = $filesystem->visibility('path/to/file.txt');
```

### Read
```php
// Listing Contents of a Directory
$contents = $filesystem->listContents('path/to/directory', true);
foreach ($contents as $object) {
    echo $object['type'] . ': ' . $object['path'] . PHP_EOL;
}

// Reading a File
$contents = $filesystem->read('path/to/file.txt');

// Get the last modified time of a file
$lastModified = $filesystem->lastModified('path/to/file.txt');

// Get the file size
$fileSize = $filesystem->fileSize('path/to/file.txt');

// Get the mime type of file
$mimeType = $filesystem->mimeType('path/to/file.txt');

// Assuming 'public_url' is configured in the $config array
// Generate a public URL for a file
$publicUrl = $this->filesystem->publicUrl($path);

// Sign URL with specified expiration time in seconds and HTTP method.
// The signed URL could be used to access the object directly.
$expiresAt = new DateTimeImmutable('+1 hour');
$privateUrl = $this->filesystem->temporaryUrl($path, $expiresAt);
```

### Delete
```php
// Deleting a File
$filesystem->delete('path/to/file.txt');

// Deleting a Directory
$filesystem->deleteDirectory('path/to');
```
