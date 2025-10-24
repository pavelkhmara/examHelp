<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnvIsTestingTest extends TestCase
{
    public function test_env_is_testing(): void
    {
        $this->assertTrue(app()->environment('testing'), 'APP_ENV is not "testing".');
        $this->assertSame('sqlite', config('database.default'), 'DB should be sqlite in testing.');
        $this->assertSame('sync', config('queue.default'), 'QUEUE should be sync in testing.');
    }
}
