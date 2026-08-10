<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuthenticatedLayoutNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_layout_renders_mobile_navigation_and_admin_menu(): void
    {
        $admin = $this->createUser(Role::ADMIN);

        $response = $this->actingAs($admin)->get(route('analisis.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('id="mobile-sidebar-open"', $html);
        $this->assertStringContainsString('aria-controls="mobile-sidebar"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('id="mobile-sidebar"', $html);
        $this->assertStringContainsString('id="mobile-sidebar-overlay"', $html);
        $this->assertSame(2, substr_count($html, '>📊 Dashboard</a>'));
        $this->assertSame(2, substr_count($html, '>📝 Input Data Harian</a>'));
        $this->assertSame(2, substr_count($html, '>🎯 Tetapan KPI</a>'));
    }

    public function test_existing_menu_permissions_are_preserved_for_read_only_role(): void
    {
        $user = $this->createUser(Role::PENGURUSAN);

        $response = $this->actingAs($user)->get(route('analisis.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertSame(2, substr_count($html, '>📊 Dashboard</a>'));
        $this->assertStringNotContainsString('Input Data Harian', $html);
        $this->assertStringNotContainsString('Tetapan KPI', $html);
    }

    public function test_dashboard_and_operation_page_use_the_authenticated_layout(): void
    {
        $this->assertStringStartsWith("@extends('layouts.app')", File::get(resource_path('views/dashboard/index.blade.php')));
        $this->assertStringStartsWith("@extends('layouts.app')", File::get(resource_path('views/analisis/index.blade.php')));
    }

    private function createUser(string $roleName): User
    {
        $role = Role::create([
            'name' => $roleName,
            'label' => ucfirst($roleName),
        ]);

        return User::create([
            'name' => 'Layout Tester',
            'email' => $roleName.'-layout@test.local',
            'password' => bcrypt('secret'),
            'role_id' => $role->id,
            'mill_id' => null,
            'is_active' => true,
        ]);
    }
}
