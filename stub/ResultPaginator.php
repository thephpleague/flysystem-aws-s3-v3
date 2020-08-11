<?php

namespace League\Flysystem\AwsS3v3\Stub;

use GuzzleHttp\Promise;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;

class ResultPaginator implements \Iterator
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

    public function valid()
    {
        return $this->result ? true : false;
    }

    public function current()
    {
        return $this->valid() ? $this->result : false;
    }

    public function next()
    {
        $this->result = null;
    }

    public function key()
    {
    }

    public function rewind()
    {
    }
}