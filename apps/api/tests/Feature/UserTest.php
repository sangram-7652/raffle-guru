<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_be_persisted(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'jane@example.com',
        ]);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'duplicate@example.com']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('users')->insert([
            'name' => 'Another User',
            'email' => 'duplicate@example.com',
            'password' => 'hashed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_soft_deleted_user_is_excluded_from_default_queries(): void
    {
        $user = User::factory()->create();

        $user->delete();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertNull(User::find($user->id));
        $this->assertNotNull(User::withTrashed()->find($user->id));
    }
}
