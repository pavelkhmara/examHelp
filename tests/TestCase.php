<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', env('QUEUE_CONNECTION', 'sync'));
        config()->set('cache.default', env('CACHE_STORE', 'array'));
        config()->set('session.driver', env('SESSION_DRIVER', 'array'));
    }
}
