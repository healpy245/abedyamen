<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_welcome_page_is_public(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('auth.sign_in'));

        $this->get(route('chatbots.login'))
            ->assertOk()
            ->assertSee(__('auth.chatbots_sign_in'));
    }
}
