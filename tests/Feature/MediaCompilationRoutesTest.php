<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\Support\ActivityTestCase;

class MediaCompilationRoutesTest extends ActivityTestCase
{
    public function test_generation_and_progress_endpoints_require_authentication(): void
    {
        foreach (['activities.timelapse', 'activities.video-compilation', 'activities.video-compilation.progress'] as $name) {
            $this->getJson(route($name))->assertUnauthorized();
        }
    }

    public function test_empty_exports_return_not_found_without_compiling(): void
    {
        $user = new User;
        $user->id = 1;
        $this->actingAs($user);

        $this->getJson(route('activities.timelapse'))->assertNotFound();
        $this->getJson(route('activities.video-compilation'))->assertNotFound();
    }

    public function test_empty_progress_is_reported_without_starting_compilation(): void
    {
        $user = new User;
        $user->id = 1;
        $this->actingAs($user);

        $this->getJson(route('activities.video-compilation.progress'))
            ->assertOk()
            ->assertJson(['ready' => false, 'total_clips' => 0, 'percent' => 0]);
    }
}
