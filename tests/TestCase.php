<?php

namespace Tests;

use App\Services\LanguageApp\AiProvider;
use App\Services\LanguageApp\Providers\MockAiProvider;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(AiProvider::class, function () {
            return new MockAiProvider;
        });
    }
}
