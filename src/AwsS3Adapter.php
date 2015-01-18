<?php

namespace League\Flysystem\AwsS3v3;

use Aws\Result;
use Aws\S3\ClearBucket;
use Aws\S3\Exception\ClearBucketException;
use Aws\Exception\S3Exception;
use Aws\S3Client;
use GuzzleHttp\Exception\RequestException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;

class AwsS3Adapter extends AbstractAdapter
{
    const PUBLIC_GRANT_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    /**
     * @var array
     */
    protected static $resultMap = [
        'Body'          => 'contents',
        'ContentLength' => 'size',
        'ContentType'   => 'mimetype',
        'Size'          => 'size',
    ];

    /**
     * @var array
     */
    protected static $metaOptions = [
        'CacheControl',
        'Expires',
        'StorageClass',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType',
    ];

    /**
     * @var S3Client
     */
    protected $s3Client;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * Constructor.
     *
     * @param S3Client $client
     * @param          $bucket
     * @param string   $prefix
     */
    public function __construct(S3Client $client, $bucket, $prefix = '')
    {
        $this->s3Client = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return false|array false on failure file meta data on success
     */
    public function write($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config   Config object
     *
     * @return false|array false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * $return bool
     */
    public function rename($path, $newpath)
    {
        if (! $this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * $return bool
     */
    public function delete($path)
    {
        $location = $this->applyPathPrefix($path);

        $command = $this->s3Client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key' => $location,
        ]);

        /** @var Result $response */
        $response = $this->s3Client->execute($command);

        return $response->get('DeleteMarker');
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * $return bool
     */
    public function deleteDir($dirname)
    {
        $iterator = $this->s3Client->getIterator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => ltrim($this->applyPathPrefix($dirname).'/', '/'),
        ]);

        $clearBucket = new ClearBucket($this->s3Client, $this->bucket, [
            'iterator' => $iterator,
        ]);

        try {
            $clearBucket->clear();
        } catch (ClearBucketException $exception) {
            return false;
        }

        return true;
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return bool|array
     */
    public function createDir($dirname, Config $config)
    {
        return $this->upload($dirname.'/', '', $config);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function read($path)
    {
        $response = $this->readObject($path);

        if ($response !== false) {
            $response['contents'] = $response['contents']->getContents();
        }

        return $response;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $prefix = $this->applyPathPrefix(rtrim($directory, '/').'/');

        $command = $this->s3Client->getCommand('listObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => ltrim($prefix, '/'),
        ]);

        /** @var Result $result */
        $result = $this->s3Client->execute($command);

        return array_map([$this, 'normalizeResponse'], $result->get('Contents'));
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMetadata($path)
    {
        $command = $this->s3Client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
        ]);

        /** @var Result $response */
        $response = $this->s3Client->execute($command);

        return $this->normalizeResponse($response->toArray(), $path);
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return false|array
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Write a new file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string   $path
     * @param resource $resource
     * @param Config   $config   Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->upload($path, $resource, $config);
    }

    /**
     * Copy a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * $return bool
     */
    public function copy($path, $newpath)
    {
        $visibility = $this->getRawVisibility($path);

        $command = $this->s3Client->getCommand('copyObject', [
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($newpath),
            'CopySource' => $this->bucket.'/'.$this->applyPathPrefix($path),
            'ACL' => $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private',
        ]);

        try {
            $this->s3Client->execute($command);
        } catch (S3Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $response = $this->readObject($path);

        if ($response !== false) {
            $response['stream'] = $response['contents']->detach();
            rewind($response['stream']);
            unset($response['contents']);
        }

        return $response;
    }

    /**
     * Read an object and normalize the response.
     *
     * @param $path
     *
     * @return array|bool
     */
    protected function readObject($path)
    {
        $command = $this->s3Client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key'    => $this->applyPathPrefix($path),
        ]);

        try {
            /** @var Result $response */
            $response = $this->s3Client->execute($command);
        } catch (RequestException $e) {
            return false;
        }

        return $this->normalizeResponse($response->toArray(), $path);
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $command = $this->s3Client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
            'ACL' => $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private',
        ]);

        try {
            $this->s3Client->execute($command);
        } catch (S3Exception $exception) {
            return false;
        }

        return compact('path', 'visibility');
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        return ['visibility' => $this->getRawVisibility($path)];
    }

    /**
     * {@inheritdoc}
     */
    public function applyPathPrefix($prefix)
    {
        return ltrim(parent::applyPathPrefix($prefix), '/');
    }

    public function setPathPrefix($prefix)
    {
        $prefix = ltrim($prefix, '/');

        return parent::setPathPrefix($prefix);
    }

    /**
     * Get the object acl presented as a visibility.
     *
     * @param string $path
     *
     * @return string
     */
    protected function getRawVisibility($path)
    {
        $command = $this->s3Client->getCommand('getObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $this->applyPathPrefix($path),
        ]);

        $result = $this->s3Client->execute($command);
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;

        foreach ($result->get('Grants') as $grant) {
            if (
                isset($grant['Grantee']['URI'])
                && $grant['Grantee']['URI'] === self::PUBLIC_GRANT_URI
                && $grant['Permission'] === 'READ'
            ) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;
                break;
            }
        }

        return $visibility;
    }

    /**
     * Upload an object.
     *
     * @param        $path
     * @param        $body
     * @param Config $config
     *
     * @return array
     */
    protected function upload($path, $body, Config $config)
    {
        $key = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);
        $acl = isset($options['ACL']) ? $options['ACL'] : 'private';

        if (! isset($options['ContentType']) && is_string($body)) {
            $options['ContentType'] = Util::guessMimeType($path, $body);
        }

        if (! isset($options['ContentLength'])) {
            $options['ContentLength'] = is_string($body) ? Util::contentSize($body) : Util::getStreamSize($body);
        }

        $this->s3Client->upload($this->bucket, $key, $body, $acl, ['params' => $options]);

        return $this->normalizeResponse($options, $key);
    }

    /**
     * Get options from the config.
     *
     * @param Config $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config)
    {
        $options = [];

        if ($visibility = $config->get('visibility')) {
            // For local reference
            $options['visibility'] = $visibility;
            // For external reference
            $options['ACL'] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'private';
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            $options['mimetype'] = $mimetype;
            // For external reference
            $options['ContentType'] = $mimetype;
        }

        foreach (static::$metaOptions as $option) {
            if (! $config->has($option)) {
                continue;
            }
            $options[$option] = $config->get($option);
        }

        return $options;
    }

    /**
     * Normalize the object result array.
     *
     * @param array $response
     *
     * @return array
     */
    protected function normalizeResponse(array $response, $path = null)
    {
        $result = ['path' => $path ?: $this->removePathPrefix($response['Key'])];

        if (isset($response['LastModified'])) {
            $result['timestamp'] = strtotime($response['LastModified']);
        }

        if (substr($result['path'], -1) === '/') {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');

            return $result;
        }

        return array_merge($result, Util::map($response, static::$resultMap), ['type' => 'file']);
    }
}
