<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Generation;
use App\Models\User;
use App\Policies\GenerationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class GenerationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_owner_can_view_a_generation_including_for_admins(): void
    {
        $generation = Generation::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();

        $this->assertInstanceOf(GenerationPolicy::class, Gate::getPolicyFor($generation));
        $this->assertTrue(Gate::forUser($generation->user)->allows('view', $generation));
        $this->assertFalse(Gate::forUser($other)->allows('view', $generation));
        $this->assertFalse(Gate::forUser($admin)->allows('view', $generation));
    }
}
