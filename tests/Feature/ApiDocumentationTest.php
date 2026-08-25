<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('scramble.auth.username', 'docs-user');
        config()->set('scramble.auth.password', 'docs-password');
    }

    public function test_documentation_requires_basic_authentication(): void
    {
        $this->get('/docs/api')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
    }

    public function test_authenticated_user_can_view_documentation(): void
    {
        $this->withBasicAuth('docs-user', 'docs-password')
            ->get('/docs/api')
            ->assertOk()
            ->assertSee('Kijumbe API');
    }

    public function test_openapi_document_contains_kijumbe_routes_and_bearer_auth(): void
    {
        $response = $this->withBasicAuth('docs-user', 'docs-password')
            ->getJson('/docs/api.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('info.title', 'Kijumbe API');

        $document = $response->json();

        $this->assertArrayHasKey('/auth/login', $document['paths']);
        $this->assertArrayHasKey('/groups', $document['paths']);
        $this->assertArrayHasKey('/webhooks/whatsapp', $document['paths']);
        $this->assertArrayHasKey('http', $document['components']['securitySchemes']);
        $this->assertSame('bearer', $document['components']['securitySchemes']['http']['scheme']);
    }
}
