<?php

namespace League\Flysystem\AwsS3v3\Stub;

use GuzzleHttp\Promise;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;

class ResultPaginator
{
    /**
     * @var Result
     */
    private $result;

    public function __construct(Result $result)
    {
        $this->result = $result;
    }

    /**
     * @param callable $callback
     *
     * @return PromiseInterface
     */
    public function each(callable $callback)
    {
        return Promise\promise_for($callback($this->result));
    }
}