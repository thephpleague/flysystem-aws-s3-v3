<?php

namespace spec\League\Flysystem\AwsS3v3;

use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\DeleteMultipleObjectsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\Exception\S3MultipartUploadException;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7;
use League\Flysystem\AdapterInterface;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\AwsS3v3\Stub\ResultPaginator;
use League\Flysystem\Config;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class AwsS3AdapterSpec extends ObjectBehavior
{
    /**
     * @var \Aws\S3\S3Client
     */
    private $client;
    private $bucket;
    const PATH_PREFIX = 'path-prefix';

    /**
     * @param \Aws\S3\S3Client $client
     */
    public function let($client)
    {
        $this->client = $client;
        $this->bucket = 'bucket';
        $this->beConstructedWith($this->client, $this->bucket, self::PATH_PREFIX);
    }

    public function it_should_retrieve_the_bucket()
    {
        $this->getBucket()->shouldBe('bucket');
    }

    public function it_should_set_the_bucket()
    {
        $this->setBucket('newbucket');
        $this->getBucket()->shouldBe('newbucket');
    }

    public function it_should_retrieve_the_client()
    {
        $this->getClient()->shouldBe($this->client);
    }

    public function it_is_initializable()
    {
        $this->shouldHaveType(AwsS3Adapter::class);
        $this->shouldHaveType(AdapterInterface::class);
    }

    public function it_should_write_files()
    {
        $this->make_it_write_using('write', 'contents');
    }

    public function it_should_update_files()
    {
        $this->make_it_write_using('update', 'contents');
    }

    public function it_should_write_files_streamed()
    {
        $stream = tmpfile();
        $this->make_it_write_using('writeStream', $stream);
        fclose($stream);
    }

    public function it_should_update_files_streamed()
    {
        $stream = tmpfile();
        $this->make_it_write_using('updateStream', $stream);
        fclose($stream);
    }

    /**
     * @param \Aws\CommandInterface $command
     * @param \Aws\CommandInterface $headCommand
     * @param \Aws\CommandInterface $listCommand
     */
    public function it_should_delete_files($command, $headCommand, $listCommand)
    {
        $key = 'key.txt';
        $this->client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();
        $this->make_it_404_on_has_object($headCommand, $listCommand, $key);

        $this->delete($key)->shouldBe(true);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_read_a_file($command)
    {
        $this->make_it_read_a_file($command, 'read', 'contents');
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_read_a_file_stream($command)
    {
        $resource = tmpfile();
        $this->make_it_read_a_file($command, 'readStream', $resource);
        fclose($resource);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_return_when_trying_to_read_an_non_existing_file($command)
    {
        $key = 'key.txt';
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->willThrow(S3Exception::class);

        $this->read($key)->shouldBe(false);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_retrieve_all_file_metadata($command)
    {
        $this->make_it_retrieve_file_metadata('getMetadata', $command);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_timestamp_of_a_file($command)
    {
        $this->make_it_retrieve_file_metadata('getTimestamp', $command);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_mimetype_of_a_file($command)
    {
        $this->make_it_retrieve_file_metadata('getMimetype', $command);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_retrieve_the_size_of_a_file($command)
    {
        $this->make_it_retrieve_file_metadata('getSize', $command);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_return_true_when_object_exists($command)
    {
        $key = 'key.txt';
        $result = new Result();
        $this->client->doesObjectExist($this->bucket, self::PATH_PREFIX.'/'.$key, [])->willReturn(true);

        $this->has($key)->shouldBe(true);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_return_true_when_prefix_exists($command)
    {
        $key = 'directory';
        $result = new Result([
            'Contents' => [
                'Key' => 'directory/foo.txt',
            ],
        ]);
        $this->client->doesObjectExist($this->bucket, self::PATH_PREFIX.'/'.$key, [])->willReturn(false);

        $this->client->getCommand('listObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => self::PATH_PREFIX.'/'.$key.'/',
            'MaxKeys' => 1,
        ])->willReturn($command);
        $this->client->execute($command)->willReturn($result);

        $this->has($key)->shouldBe(true);
    }

    /**
     * @param \Aws\CommandInterface $command
     * @param \Aws\S3\Exception\S3Exception $exception
     */
    public function it_should_return_false_when_listing_objects_returns_a_403($command, $exception)
    {
        $key = 'directory';
        $this->client->doesObjectExist($this->bucket, self::PATH_PREFIX.'/'.$key, [])->willReturn(false);

        $this->client->getCommand('listObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => self::PATH_PREFIX.'/'.$key.'/',
            'MaxKeys' => 1,
        ])->willReturn($command);
        $response = new Psr7\Response(403);
        $exception = new S3Exception('Message', new Command('dummy'), [
            'response' => $response,
        ]);

        $this->client->execute($command)->willThrow($exception);

        $this->has($key)->shouldBe(false);
    }

    /**
     * @param \Aws\CommandInterface $command
     * @param \Aws\S3\Exception\S3Exception $exception
     */
    public function it_should_pass_through_when_listing_objects_throws_an_exception($command, $exception)
    {
        $key = 'directory';
        $this->client->doesObjectExist($this->bucket, self::PATH_PREFIX.'/'.$key, [])->willReturn(false);

        $this->client->getCommand('listObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => self::PATH_PREFIX.'/'.$key.'/',
            'MaxKeys' => 1,
        ])->willReturn($command);
        $response = new Psr7\Response(500);
        $exception = new S3Exception('Message', new Command('dummy'), [
            'response' => $response,
        ]);

        $this->client->execute($command)->willThrow($exception);

        $this->shouldThrow(S3Exception::class)->duringHas($key);
    }

    /**
     * @param \Aws\CommandInterface $command
     * @param \Aws\CommandInterface $aclCommand
     */
    public function it_should_copy_files($command, $aclCommand)
    {
        $sourceKey = 'key.txt';
        $key = 'newkey.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->make_it_copy_successfully($command, $key, $sourceKey, 'private');
        $this->copy($sourceKey, $key)->shouldBe(true);
    }

    /**
     * @param \Aws\CommandInterface $command
     * @param \Aws\CommandInterface $aclCommand
     */
    public function it_should_return_false_when_copy_fails($command, $aclCommand)
    {
        $sourceKey = 'key.txt';
        $key = 'newkey.txt';
        $this->make_it_fail_on_copy($command, $key, $sourceKey);
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->copy($sourceKey, $key)->shouldBe(false);
    }

    public function it_should_create_directories()
    {
        $config = new Config();
        $path = 'dir/name';
        $body = '';
        $this->client->upload(
            $this->bucket,
            self::PATH_PREFIX.'/'.$path.'/',
            $body,
            'private',
            Argument::type('array')
        )->shouldBeCalled();

        $this->createDir($path, $config)->shouldBeArray();
    }

    /**
     * @param \Aws\CommandInterface $command
     * @param \Aws\CommandInterface $aclCommand
     */
    public function it_should_return_false_during_rename_when_copy_fails($command, $aclCommand)
    {
        $sourceKey = 'key.txt';
        $key = 'newkey.txt';
        $this->make_it_fail_on_copy($command, $key, $sourceKey);
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->rename($sourceKey, $key)->shouldBe(false);
    }

    /**
     * @param \Aws\CommandInterface $copyCommand
     * @param \Aws\CommandInterface $deleteCommand
     * @param \Aws\CommandInterface $aclCommand
     * @param \Aws\CommandInterface $headCommand
     * @param \Aws\CommandInterface $listCommand
     */
    public function it_should_copy_and_delete_during_renames($copyCommand, $deleteCommand, $aclCommand, $headCommand, $listCommand)
    {
        $sourceKey = 'key.txt';
        $key = 'newkey.txt';

        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->make_it_copy_successfully($copyCommand, $key, $sourceKey, 'private');
        $this->make_it_delete_successfully($deleteCommand, $sourceKey);
        $this->make_it_404_on_has_object($headCommand, $listCommand, $sourceKey);
        $this->rename($sourceKey, $key)->shouldBe(true);
    }

    public function it_should_list_contents()
    {
        $prefix = 'prefix';
        $result = new Result([
            'Contents' => [
                ['Key' => self::PATH_PREFIX.'/prefix/filekey.txt'],
            ],
            'CommonPrefixes' => [
                ['Prefix' => self::PATH_PREFIX.'/prefix/dirname/']
            ]
        ]);

        $this->client->getPaginator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => self::PATH_PREFIX.'/'.$prefix.'/',
            'Delimiter' => '/'
        ])->shouldBeCalled()->willReturn(new ResultPaginator($result));

        $this->listContents($prefix);
    }

    public function it_should_catch_404s_when_fetching_metadata()
    {
        $key = 'haha.txt';
        $this->make_it_404_on_get_metadata($key);

        $this->getMetadata($key)->shouldBe(false);
    }

    public function it_should_rethrow_non_404_responses_when_fetching_metadata()
    {
        $key = 'haha.txt';
        $response = new Psr7\Response(500);
        $command = new Command('dummy');
        $exception = new S3Exception('Message', $command, [
            'response' => $response,
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->willThrow($exception);
        $this->shouldThrow($exception)->duringGetMetadata($key);
    }

    public function it_should_delete_directories()
    {
        $this->client->deleteMatchingObjects($this->bucket, self::PATH_PREFIX.'/prefix/')->willReturn(null);

        $this->deleteDir('prefix')->shouldBe(true);
    }

    public function it_should_return_false_when_deleting_a_directory_fails()
    {
        $this->client->deleteMatchingObjects($this->bucket, self::PATH_PREFIX.'/'.'prefix/')
            ->willThrow(new DeleteMultipleObjectsException([], []));

        $this->deleteDir('prefix')->shouldBe(false);
    }

    /**
     * @param \Aws\CommandInterface $aclCommand
     */
    public function it_should_get_the_visibility_of_a_public_file($aclCommand)
    {
        $key = 'key.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $key, 'public');
        $this->getVisibility($key)->shouldHaveKey('visibility');
        $this->getVisibility($key)->shouldHaveValue('public');
    }

    /**
     * @param \Aws\CommandInterface $aclCommand
     */
    public function it_should_get_the_visibility_of_a_private_file($aclCommand)
    {
        $key = 'key.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $key, 'private');
        $this->getVisibility($key)->shouldHaveKey('visibility');
        $this->getVisibility($key)->shouldHaveValue('private');
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_set_the_visibility_of_a_file_to_public($command)
    {
        $key = 'key.txt';
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
            'ACL' => 'public-read',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();

        $this->setVisibility($key, 'public')->shouldHaveValue('public');
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_set_the_visibility_of_a_file_to_private($command)
    {
        $key = 'key.txt';
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();

        $this->setVisibility($key, 'private')->shouldHaveValue('private');
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_return_false_when_failing_to_set_visibility($command)
    {
        $key = 'key.txt';
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow(S3Exception::class);

        $this->setVisibility($key, 'private')->shouldBe(false);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_return_false_when_failing_to_upload()
    {
        $config = new Config(['visibility' => 'public', 'mimetype' => 'plain/text', 'CacheControl' => 'value']);
        $key = 'key.txt';
        $this->client->upload(
            $this->bucket,
            self::PATH_PREFIX.'/'.$key,
            'contents',
            'public-read',
            Argument::type('array')
        )->willThrow(S3MultipartUploadException::class);


        $this->write($key, 'contents', $config)->shouldBe(false);
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_return_path_in_response_without_prefix($command)
    {
        $key = 'key.txt';
        $dir = 'dir';

        $this->writeStream($key, '', new Config())->shouldHaveKeyWithValue('path', $key);
        $this->updateStream($key, '', new Config())->shouldHaveKeyWithValue('path', $key);
        $this->write($key, '', new Config())->shouldHaveKeyWithValue('path', $key);
        $this->update($key, '', new Config())->shouldHaveKeyWithValue('path', $key);
        $this->createDir($dir, new Config())->shouldHaveKeyWithValue('path', $dir);

        $this->make_it_retrieve_file_metadata('getMetadata', $command);
        $this->getMetadata($key)->shouldHaveKeyWithValue('path', $key);

        $resource = tmpfile();
        $this->make_it_read_a_file($command, 'readStream', $resource);
        fclose($resource);
        $this->readStream($key)->shouldHaveKeyWithValue('path', $key);

        $this->make_it_read_a_file($command, 'read', '');
        $this->read($key)->shouldHaveKeyWithValue('path', $key);

        $this->client->getPaginator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => self::PATH_PREFIX.'/'.$dir.'/',
            'Delimiter' => '/'
        ])->shouldBeCalled()->willReturn(new ResultPaginator(new Result([
            'Contents' => [
                ['Key' => self::PATH_PREFIX . '/' . $dir . '/' . $key],
            ]
        ])));

        $this->listContents($dir)->shouldHaveItemWithKeyWithValue('path', $dir . '/' . $key);
    }

    private function make_it_retrieve_raw_visibility($command, $key, $visibility)
    {
        $options = [
            'private' => [
                'Grants' => [],
            ],
            'public' => [
                'Grants' => [[
                    'Grantee' => ['URI' => AwsS3Adapter::PUBLIC_GRANT_URI],
                    'Permission' => 'READ',
                ]],
            ],
        ];

        $result = new Result($options[$visibility]);

        $this->client->getCommand('getObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
    }

    private function make_it_retrieve_file_metadata($method, $command)
    {
        $timestamp = time();
        $key = 'key.txt';

        $result = new Result([
            'Key' => self::PATH_PREFIX.'/'.$key,
            'LastModified' => date('Y-m-d H:i:s', $timestamp),
            'ContentType' => 'plain/text',
            'ETag' => '1234612346',
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->{$method}($key)->shouldBeArray();
    }

    private function make_it_read_a_file($command, $method, $contents)
    {
        $key = 'key.txt';
        $stream = Psr7\stream_for($contents);
        $result = new Result([
            'Key' => self::PATH_PREFIX.'/'.$key,
            'LastModified' => $date = date('Y-m-d h:i:s'),
            'Body' => $stream,
        ]);
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->{$method}($key)->shouldBeArray();
    }

    /**
     * @param \Aws\CommandInterface $command
     */
    public function it_should_read_a_file_streaming($command)
    {
        $this->beConstructedWith($this->client, $this->bucket, self::PATH_PREFIX, [
            '@http' => ['stream' => true],
        ]);
        $key = 'key.txt';
        $stream = Psr7\stream_for('contents');
        $result = new Result([
            'Key' => self::PATH_PREFIX.'/'.$key,
            'LastModified' => $date = date('Y-m-d h:i:s'),
            'Body' => $stream,
        ]);
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
            '@http' => [
                'stream' => true,
            ],
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->readStream($key)->shouldBeArray();
    }

    private function make_it_write_using($method, $body)
    {
        $config = new Config(['visibility' => 'public', 'mimetype' => 'plain/text', 'CacheControl' => 'value']);
        $key = 'key.txt';
        $this->client->upload(
            $this->bucket,
            self::PATH_PREFIX.'/'.$key,
            $body,
            'public-read',
            Argument::type('array')
        )->shouldBeCalled();

        $this->{$method}($key, $body, $config)->shouldBeArray();
    }

    private function make_it_copy_successfully($copyCommand, $key, $sourceKey, $acl)
    {
        $this->client->getCommand('copyObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
            'CopySource' => S3Client::encodeKey($this->bucket.'/'.self::PATH_PREFIX.'/'.$sourceKey),
            'ACL' => $acl,
        ])->willReturn($copyCommand);

        $this->client->execute($copyCommand)->shouldBeCalled();
    }

    private function make_it_delete_successfully($deleteCommand, $sourceKey)
    {
        $deleteResult = new Result(['DeleteMarker' => true]);

        $this->client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$sourceKey,
        ])->willReturn($deleteCommand);

        $this->client->execute($deleteCommand)->willReturn($deleteResult);
    }

    private function make_it_fail_on_copy($command, $key, $sourceKey)
    {
        $this->client->getCommand('copyObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
            'CopySource' => S3Client::encodeKey($this->bucket.'/'.self::PATH_PREFIX.'/'.$sourceKey),
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow(S3Exception::class);
    }

    public function getMatchers()
    {
        return [
            'haveKey' => function ($subject, $key) {
                return array_key_exists($key, $subject);
            },
            'haveValue' => function ($subject, $value) {
                return in_array($value, $subject);
            },
            'haveItemWithKeyWithValue' => function($subject, $key, $value) {
                foreach ($subject as $item) {
                    if (isset($item[$key]) && $item[$key] === $value) {
                        return true;
                    }
                }

                return false;
            },
        ];
    }

    private function make_it_404_on_has_object($headCommand, $listCommand, $key)
    {
        $this->client->doesObjectExist($this->bucket, self::PATH_PREFIX.'/'.$key, [])->willReturn(false);

        $result = new Result();
        $this->client->getCommand('listObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => self::PATH_PREFIX.'/'.$key.'/',
            'MaxKeys' => 1,
        ])->willReturn($listCommand);
        $this->client->execute($listCommand)->willReturn($result);
    }

    private function make_it_404_on_get_metadata($key)
    {
        $response = new Psr7\Response(404);
        $command = new Command('dummy');
        $exception = new S3Exception('Message', $command, [
            'response' => $response,
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => self::PATH_PREFIX.'/'.$key,
        ])->willReturn($command);

        $this->client->execute($command)->willThrow($exception);
    }
}
