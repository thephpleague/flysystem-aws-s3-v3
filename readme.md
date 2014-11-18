# League\Flysystem\AwsS3v3 [BETA]

[![Author](http://img.shields.io/badge/author-@frankdejonge-blue.svg?style=flat-square)](https://twitter.com/frankdejonge)
[![Build Status](https://img.shields.io/travis/thephpleague/flysystem-aws-s3-v3/master.svg?style=flat-square)](https://travis-ci.org/thephpleague/flysystem)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/thephpleague/flysystem-aws-s3-v3.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem)
[![Quality Score](https://img.shields.io/scrutinizer/g/thephpleague/flysystem-aws-s3-v3.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
<!--
[![Packagist Version](https://img.shields.io/packagist/v/league/flysystem.svg?style=flat-square)](https://packagist.org/packages/league/flysystem)
[![Total Downloads](https://img.shields.io/packagist/dt/league/flysystem.svg?style=flat-square)](https://packagist.org/packages/league/flysystem)
-->

This is a Flysystem adapter for the aws-sdk-php v3.

# Bootstrap

``` php
<?php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\Adapter;
use League\Flysystem\Filesystem;

include __DIR__ . '/vendor/autoload.php';

$client = S3Client::factory([
    'key'    => 'your-key',
    'secret' => 'your-secret',
    'region' => 'your-region',
    'version' => 'latest|version',
]);

$adapter = new Adapter($client, 'your-bucket-name');
```
