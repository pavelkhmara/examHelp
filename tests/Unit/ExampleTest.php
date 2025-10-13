<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_basic_math(): void
    {
        $this->assertSame(4, 2 + 2);
    }
}
