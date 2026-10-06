<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_welcome_page_is_public(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('features.hero_title'))
            ->assertSee(__('auth.sign_in'))
            ->assertDontSee('id="your-tools"', false);
    }
}
