<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', 'http://localhost');
    }

    public function be(Authenticatable $user, $guard = null)
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        return parent::be($user, $guard);
    }
}
