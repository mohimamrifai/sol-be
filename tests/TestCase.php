<?php

namespace Tests;

use App\Support\SystemConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SystemConfig memoizes settings in a static property that outlives a single
        // test, so values written by one test would leak into the next one.
        SystemConfig::flush();
    }
}
