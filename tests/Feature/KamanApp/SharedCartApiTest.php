<?php

declare(strict_types=1);

namespace Tests\Feature\KamanApp;

use Tests\TestCase;

class SharedCartApiTest extends TestCase
{
    private string $code = 'kaman88';

    protected function tearDown(): void
    {
        $path = storage_path('app/private/shared-carts/'.$this->code.'.json');
        if (is_file($path)) {
            unlink($path);
        }

        parent::tearDown();
    }

    public function test_a_shared_cart_can_be_created_read_and_updated(): void
    {
        $draft = [
            'code' => $this->code,
            'host_id' => 'host-1',
            'host_name' => 'Yamen',
            'company_id' => '12',
            'company_name' => 'Kaman',
            'status' => 'open',
            'revision' => 1,
            'participants' => [
                ['id' => 'host-1', 'name' => 'Yamen', 'is_host' => true, 'submitted' => false],
            ],
            'lines' => [
                ['company_id' => '12', 'quantity' => 1],
            ],
        ];

        $this->postJson('/api/shared-carts', $draft)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', $this->code)
            ->assertJsonPath('data.lines.0.quantity', 1);

        $this->getJson('/api/shared-carts/'.$this->code)
            ->assertOk()
            ->assertJsonPath('data.host_name', 'Yamen');

        $draft['revision'] = 2;
        $draft['lines'][0]['quantity'] = 3;

        $this->putJson('/api/shared-carts/'.$this->code, $draft)
            ->assertOk()
            ->assertJsonPath('data.revision', 2)
            ->assertJsonPath('data.lines.0.quantity', 3);
    }

    public function test_unknown_and_unsafe_codes_are_rejected(): void
    {
        $this->getJson('/api/shared-carts/missing1')->assertNotFound();
        $this->getJson('/api/shared-carts/'.rawurlencode('../etc'))->assertNotFound();
        $this->postJson('/api/shared-carts', ['code' => '../etc'])->assertStatus(422);
    }
}
