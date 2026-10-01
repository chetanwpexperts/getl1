<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_landing_page_loads(): void
    {
        $this->get('/')->assertOk()->assertSee('Make your suppliers compete.');
    }
}
