<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAdmins;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use CreatesAdmins, RefreshDatabase;

    public function test_a_customer_can_update_their_own_name_email_and_phone(): void
    {
        $user = $this->actingAsToken($this->customer());

        $this->putJson('/api/account/profile', [
            'name' => 'New Name',
            'email' => 'newmail@example.com',
            'phone' => '9876543210',
        ])->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email', 'newmail@example.com')
            ->assertJsonPath('data.phone', '9876543210');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'newmail@example.com']);
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->putJson('/api/account/profile', ['name' => 'X'])->assertUnauthorized();
    }

    public function test_profile_update_cannot_change_protected_fields(): void
    {
        $user = $this->actingAsToken($this->customer());
        $otherStaffId = $this->admin()->id;

        // None of these are validated fields on UpdateProfileRequest, so even
        // though they're sent, they can never be mass-assigned.
        $this->putJson('/api/account/profile', [
            'name' => 'Still Me',
            'type' => User::TYPE_STAFF,
            'status' => User::STATUS_INACTIVE,
            'id' => $otherStaffId,
            'google_id' => 'hijacked-google-id',
        ])->assertOk();

        $user->refresh();
        $this->assertSame(User::TYPE_CUSTOMER, $user->type);
        $this->assertSame(User::STATUS_ACTIVE, $user->status);
        $this->assertNull($user->google_id);
        $this->assertNotEquals($otherStaffId, $user->id);
    }

    public function test_profile_update_rejects_an_email_already_used_by_another_account(): void
    {
        $this->customer(['email' => 'taken@example.com']);
        $this->actingAsToken($this->customer());

        $this->putJson('/api/account/profile', ['email' => 'taken@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_customer_can_change_their_password_with_the_correct_current_password(): void
    {
        $user = $this->actingAsToken($this->customer(['password' => Hash::make('old-password')]));

        $this->putJson('/api/account/password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $this->actingAsToken($this->customer(['password' => Hash::make('old-password')]));

        $this->putJson('/api/account/password', [
            'current_password' => 'totally-wrong',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }
}
