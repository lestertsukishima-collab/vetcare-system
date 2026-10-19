<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_account_settings_links_load_the_flux_layout_in_a_new_document(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $links = $this->linksTo($response->getContent(), route('profile.edit'));

        $this->assertCount(2, $links);
        foreach ($links as $link) {
            $this->assertFalse($link->hasAttribute('wire:navigate'));
            $this->assertFalse($link->hasAttribute('target'));
        }

        // Pages sharing the clinic layout still use Livewire navigation.
        $clinicLinks = $this->linksTo($response->getContent(), route('clinic.index', 'pets'));
        $this->assertNotEmpty($clinicLinks);
        foreach ($clinicLinks as $link) {
            $this->assertTrue($link->hasAttribute('wire:navigate'));
        }
    }

    public function test_leaving_settings_loads_the_clinic_layout_in_a_new_document(): void
    {
        $user = User::factory()->create(['role' => 'owner']);

        $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        foreach ([route('dashboard'), route('clinic.index', 'appointments'), route('clinic.index', 'pets')] as $url) {
            $links = $this->linksTo($response->getContent(), $url);
            $this->assertNotEmpty($links);
            foreach ($links as $link) {
                $this->assertFalse($link->hasAttribute('wire:navigate'));
                $this->assertFalse($link->hasAttribute('target'));
            }
        }

        // Settings tabs share their assets, so their navigation stays reactive.
        foreach (['profile.edit', 'security.edit', 'appearance.edit'] as $route) {
            $links = $this->linksTo($response->getContent(), route($route));
            $this->assertNotEmpty($links);
            foreach ($links as $link) {
                $this->assertTrue($link->hasAttribute('wire:navigate'));
            }
        }
    }

    /** @return list<DOMElement> */
    private function linksTo(string $html, string $url): array
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $links = [];
        foreach ($document->getElementsByTagName('a') as $link) {
            if ($link->getAttribute('href') === $url) {
                $links[] = $link;
            }
        }

        return $links;
    }
}
