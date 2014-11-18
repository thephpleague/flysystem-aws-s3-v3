<?php

namespace spec\League\Flysystem\AwsS3v3;

use Aws\Common\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Stream\Stream;
use League\Flysystem\AwsS3v3\Adapter;
use League\Flysystem\Config;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

class AdapterSpec extends ObjectBehavior
{
    private $client;
    private $bucket;

    function let(S3Client $client)
    {
        $this->client = $client;
        $this->bucket = 'bucket';
        $this->beConstructedWith($this->client, $this->bucket);
    }

    function it_is_initializable()
    {
        $this->shouldHaveType('League\Flysystem\AwsS3v3\Adapter');
        $this->shouldHaveType('League\Flysystem\AdapterInterface');
    }

    function it_should_write_files()
    {
        $this->make_it_write_using('write', 'contents');
    }

    function it_should_update_files()
    {
        $this->make_it_write_using('update', 'contents');
    }

    function it_should_write_files_streamed()
    {
        $stream = tmpfile();
        $this->make_it_write_using('writeStream', $stream);
        fclose($stream);
    }

    function it_should_update_files_streamed()
    {
        $stream = tmpfile();
        $this->make_it_write_using('updateStream', $stream);
        fclose($stream);
    }

    public function it_should_delete_files(CommandInterface $command)
    {
        $result = new Result(['DeleteMarker' => true]);
        $this->client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->delete($key)->shouldBe(true);
    }

    public function it_should_read_a_file(CommandInterface $command)
    {
        $this->make_it_read_a_file($command, 'read', 'contents');
    }

    public function it_should_read_a_file_stream(CommandInterface $command)
    {
        $resource = tmpfile();
        $this->make_it_read_a_file($command, 'readStream', $resource);
        fclose($resource);
    }

    public function it_should_return_when_trying_to_read_an_non_existing_file(
        CommandInterface $command
    ) {
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow('GuzzleHttp\Exception\RequestException');

        $this->read($key)->shouldBe(false);
    }

    public function it_should_retrieve_all_file_metadata(CommandInterface $command)
    {
        $this->make_it_retrieve_file_metadata('getMetadata', $command);
    }

    public function it_should_retrieve_the_timestamp_of_a_file(CommandInterface $command)
    {
        $this->make_it_retrieve_file_metadata('getTimestamp', $command);
    }

    public function it_should_retrieve_the_mimetype_of_a_file(CommandInterface $command)
    {
        $this->make_it_retrieve_file_metadata('getMimetype', $command);
    }

    public function it_should_retrieve_the_size_of_a_file(CommandInterface $command)
    {
        $this->make_it_retrieve_file_metadata('getSize', $command);
    }

    public function it_should_retrieve_the_metadata_to_check_if_an_object_exists(CommandInterface $command)
    {
        $this->make_it_retrieve_file_metadata('has', $command);
    }

    public function it_should_copy_files(CommandInterface $command, CommandInterface $aclCommand)
    {
        $key = 'key.txt';
        $sourceKey = 'newkey.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->make_it_copy_successfully($command, $key, $sourceKey, 'private');
        $this->copy($sourceKey, $key)->shouldBe(true);
    }

    public function it_should_return_false_when_copy_fails(CommandInterface $command, CommandInterface $aclCommand)
    {
        $key = 'key.txt';
        $sourceKey = 'newkey.txt';
        $this->make_it_fail_on_copy($command, $key, $sourceKey);
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->copy($sourceKey, $key)->shouldBe(false);
    }

    public function it_should_create_directories()
    {
        $config = new Config;
        $path = 'dir/name';
        $body = '';
        $this->client->upload(
            $this->bucket,
            $path . '/',
            $body,
            Argument::type('array')
        )->shouldBeCalled();

        $this->createDir($path, $config)->shouldBeArray();
    }

    public function it_should_return_false_during_rename_when_copy_fails(CommandInterface $command, CommandInterface $aclCommand)
    {
        $key = 'key.txt';
        $sourceKey = 'newkey.txt';
        $this->make_it_fail_on_copy($command, $key, $sourceKey);
        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->rename($sourceKey, $key)->shouldBe(false);
    }

    public function it_should_copy_and_delete_during_renames(
        CommandInterface $copyCommand,
        CommandInterface $deleteCommand,
        CommandInterface $aclCommand
    ) {
        $sourceKey = 'newkey.txt';
        $key = 'key.txt';

        $this->make_it_retrieve_raw_visibility($aclCommand, $sourceKey, 'private');
        $this->make_it_copy_successfully($copyCommand, $key, $sourceKey, 'private');
        $this->make_it_delete_successfully($deleteCommand, $sourceKey);

        $this->rename($sourceKey, $key)->shouldBe(true);
    }

    public function it_should_list_contents(CommandInterface $command)
    {
        $prefix = 'prefix';

        $this->client->getCommand('listObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => $prefix . '/',
        ])->willReturn($command);

        $result = new Result([
            'Contents' => [
                ['Key' => 'prefix/filekey.txt'],
                ['Key' => 'prefix/dirname/'],
            ]
        ]);

        $this->client->execute($command)->willReturn($result);

        $this->listContents($prefix)->shouldHaveCount(2);
    }

    public function it_should_delete_directories(CommandInterface $command)
    {
        $iterator = new \ArrayIterator($batch = [
            ['Key' => 'key.txt'],
        ]);

        $this->client->getIterator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => 'prefix/',
        ])->willReturn($iterator);

        $this->client->getCommand('DeleteObjects', [
            'Bucket' => $this->bucket,
            'Delete' => [
                'Objects' => $batch
            ]
        ])->willReturn($command);

        $this->client->execute($command)->willReturn(new Result([
            'Errors' => [],
        ]));

        $this->deleteDir('prefix')->shouldBe(true);
    }

    public function it_should_return_false_when_deleting_a_directory_fails(CommandInterface $command)
    {
        $iterator = new \ArrayIterator($batch = [
            ['Key' => 'key.txt'],
        ]);

        $this->client->getIterator('ListObjects', [
            'Bucket' => $this->bucket,
            'Prefix' => 'prefix/',
        ])->willReturn($iterator);

        $this->client->getCommand('DeleteObjects', [
            'Bucket' => $this->bucket,
            'Delete' => [
                'Objects' => $batch
            ]
        ])->willReturn($command);

        $this->client->execute($command)->willReturn(new Result([
            'Errors' => ['Error'],
        ]));

        $this->deleteDir('prefix')->shouldBe(false);
    }

    public function it_should_get_the_visibility_of_a_public_file(CommandInterface $aclCommand)
    {
        $key = 'key.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $key, 'public');
        $this->getVisibility($key)->shouldHaveKey('visibility');
        $this->getVisibility($key)->shouldHaveValue('public');
    }

    public function it_should_get_the_visibility_of_a_private_file(CommandInterface $aclCommand)
    {
        $key = 'key.txt';
        $this->make_it_retrieve_raw_visibility($aclCommand, $key, 'private');
        $this->getVisibility($key)->shouldHaveKey('visibility');
        $this->getVisibility($key)->shouldHaveValue('private');
    }

    public function it_should_set_the_visibility_of_a_file_to_public(CommandInterface $command)
    {
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
            'ACL' => 'public-read',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();

        $this->setVisibility($key, 'public')->shouldHaveValue('public');
    }

    public function it_should_set_the_visibility_of_a_file_to_private(CommandInterface $command)
    {
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->shouldBeCalled();

        $this->setVisibility($key, 'private')->shouldHaveValue('private');
    }

    public function it_should_return_false_when_failing_to_set_visibility(CommandInterface $command)
    {
        $this->client->getCommand('putObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key = 'key.txt',
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow('Aws\S3\Exception\S3Exception');

        $this->setVisibility($key, 'private')->shouldBe(false);
    }

    private function make_it_retrieve_raw_visibility(CommandInterface $command, $key, $visibility)
    {
        $options = [
            'private' => [
                'Grants' => [],
            ],
            'public' => [
                'Grants' => [[
                    'Grantee' => ['URI' => Adapter::PUBLIC_GRANT_URI],
                    'Permission' => 'READ',
                ]]
            ]
        ];

        $result = new Result($options[$visibility]);

        $this->client->getCommand('getObjectAcl', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
    }

    private function make_it_retrieve_file_metadata($method, CommandInterface $command)
    {
        $timestamp = time();

        $result = new Result([
            'Key' => $key = 'key.txt',
            'LastModified' => date('Y-m-d H:i:s', $timestamp),
            'ContentType' => 'plain/text',
        ]);

        $this->client->getCommand('headObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->{$method}($key)->shouldBeArray();
    }

    private function make_it_read_a_file(CommandInterface $command, $method, $contents)
    {
        $key = 'key.txt';
        $stream = Stream::factory($contents);
        $result = new Result([
            'Key' => $key,
            'LastModified' => $date = date('Y-m-d h:i:s'),
            'Body' => $stream,
        ]);
        $this->client->getCommand('getObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])->willReturn($command);

        $this->client->execute($command)->willReturn($result);
        $this->{$method}($key)->shouldBeArray();
    }

    private function make_it_write_using($method, $body)
    {
        $config = new Config(['visibility' => 'public', 'mimetype' => 'plain/text', 'CacheControl' => 'value']);
        $path = 'key.txt';
        $this->client->upload(
            $this->bucket,
            $path,
            $body,
            Argument::type('array')
        )->shouldBeCalled();

        $this->{$method}($path, $body, $config)->shouldBeArray();
    }

    /**
     * @param CommandInterface $copyCommand
     * @param                  $key
     * @param                  $sourceKey
     */
    private function make_it_copy_successfully(CommandInterface $copyCommand, $key, $sourceKey, $acl)
    {
        $this->client->getCommand('copyObject', [
            'Bucket'     => $this->bucket,
            'Key'        => $key,
            'CopySource' => $this->bucket . '/' . $sourceKey,
            'ACL'        => $acl,
        ])->willReturn($copyCommand);

        $this->client->execute($copyCommand)->shouldBeCalled();
    }

    /**
     * @param CommandInterface $deleteCommand
     * @param                  $sourceKey
     */
    private function make_it_delete_successfully(CommandInterface $deleteCommand, $sourceKey)
    {
        $deleteResult = new Result(['DeleteMarker' => true]);

        $this->client->getCommand('deleteObject', [
            'Bucket' => $this->bucket,
            'Key'    => $sourceKey,
        ])->willReturn($deleteCommand);

        $this->client->execute($deleteCommand)->willReturn($deleteResult);
    }

    /**
     * @param CommandInterface $command
     * @param                  $key
     * @param                  $sourceKey
     */
    private function make_it_fail_on_copy(CommandInterface $command, $key, $sourceKey)
    {
        $this->client->getCommand('copyObject', [
            'Bucket'     => $this->bucket,
            'Key'        => $key,
            'CopySource' => $this->bucket . '/' . $sourceKey,
            'ACL' => 'private',
        ])->willReturn($command);

        $this->client->execute($command)->willThrow('Aws\S3\Exception\S3Exception');
    }

    public function getMatchers()
    {
        return [
            'haveKey' => function($subject, $key) {
                return array_key_exists($key, $subject);
            },
            'haveValue' => function($subject, $value) {
                return in_array($value, $subject);
            },
        ];
    }
}
