# League\Flysystem\AwsS3v3

[![Author](http://img.shields.io/badge/author-@frankdejonge-blue.svg?style=flat-square)](https://twitter.com/frankdejonge)
[![Build Status](https://img.shields.io/travis/thephpleague/flysystem-aws-s3-v3/master.svg?style=flat-square)](https://travis-ci.org/thephpleague/flysystem-aws-s3-v3)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/thephpleague/flysystem-aws-s3-v3.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem-aws-s3-v3)
[![Quality Score](https://img.shields.io/scrutinizer/g/thephpleague/flysystem-aws-s3-v3.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem-aws-s3-v3)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/league/flysystem-aws-s3-v3.svg?style=flat-square)](https://packagist.org/packages/league/flysystem-aws-s3-v3)
[![Total Downloads](https://img.shields.io/packagist/dt/league/flysystem-aws-s3-v3.svg?style=flat-square)](https://packagist.org/packages/league/flysystem-aws-s3-v3)

This is a Flysystem adapter for the aws-sdk-php v3.

# Add the repository label to your composer.json
```
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/rankarpan/flysystem-aws-s3-v3"
        }
    ],
    "require": {
        "league/flysystem-aws-s3-v3": "dev-cloudfront-v1.x"
    }
}
```

# Installation

```bash
composer require league/flysystem-aws-s3-v3:dev-cloudfront-v1.x
```

# Bootstrap

Using standard `Aws\S3\S3Client`:

``` php
<?php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

include __DIR__ . '/vendor/autoload.php';

$client = new S3Client([
    'credentials' => [
        'key'    => 'your-key',
        'secret' => 'your-secret'
    ],
    'region' => 'your-region',
    'version' => 'latest|version',
]);

$adapter = new AwsS3Adapter($client, 'your-bucket-name');
$filesystem = new Filesystem($adapter);
```

or using `Aws\S3\S3MultiRegionClient` which does not require to specify the `region` parameter:

``` php
<?php
use Aws\S3\S3MultiRegionClient;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

include __DIR__ . '/vendor/autoload.php';

$client = new S3MultiRegionClient([
    'credentials' => [
        'key'    => 'your-key',
        'secret' => 'your-secret'
    ],
    'version' => 'latest|version',
]);

$adapter = new AwsS3Adapter($client, 'your-bucket-name');
$filesystem = new Filesystem($adapter);
```

# CloudFront Configuration Options
```
'options' => [
    'endpoint' => 'https://xxxxxxxxxxx.cloudfront.net',
    'private_key' => 'private_key',
    'key_pair_id' => 'key_pair_id',
]
```

# Support for CloudFront URL
```
$filesystem->getCloudFrontUrl('file.txt', new DateTime('+ 3 days'));
```

# Support for temporary URL
```
$filesystem->getTemporaryUrl('file.txt', new DateTime('+ 3 days'));
```
