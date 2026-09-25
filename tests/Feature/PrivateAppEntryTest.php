<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivateAppEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_the_dashboard(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('dashboard'));
    }

    public function test_guests_visiting_the_root_end_at_the_login_page(): void
    {
        $response = $this->followingRedirects()->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
        $this->assertGuest();
    }

    public function test_authenticated_users_visiting_the_root_reach_the_dashboard(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->followingRedirects()->get(route('home'));

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('dashboard'));
    }

    public function test_login_page_shares_the_public_site_url(): void
    {
        config(['app.public_site_url' => 'https://public.example.test']);

        $response = $this->get(route('login'));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('publicSiteUrl', 'https://public.example.test'));
    }
}
