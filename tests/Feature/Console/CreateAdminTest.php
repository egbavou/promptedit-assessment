<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('admin', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'test-admin-password',
        ]);
    }

    public function test_it_creates_a_verified_admin_using_configuration_without_printing_the_password(): void
    {
        $this->artisan('app:create-admin')
            ->expectsOutput('Admin account ready: admin@example.com')
            ->doesntExpectOutputToContain('test-admin-password')
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('Admin', $user->name);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue($user->is_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('test-admin-password', $user->password));
        $this->assertNotSame('test-admin-password', $user->password);
    }

    public function test_it_updates_an_existing_user_and_can_be_repeated_without_duplicates(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'admin@example.com']);
        config()->set('admin.name', 'Assessment Admin');
        config()->set('admin.password', 'updated-test-password');

        $this->artisan('app:create-admin')->assertSuccessful();
        $verifiedAt = $user->refresh()->email_verified_at;
        $this->travel(1)->hour();
        $this->artisan('app:create-admin')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $user->refresh();
        $this->assertSame('Assessment Admin', $user->name);
        $this->assertTrue($user->is_admin);
        $this->assertEquals($verifiedAt, $user->email_verified_at);
        $this->assertTrue(Hash::check('updated-test-password', $user->password));
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_fails_without_changing_users(string $key, ?string $value, string $message): void
    {
        $user = User::factory()->unverified()->create(['email' => 'admin@example.com']);
        $original = $user->refresh()->getAttributes();
        config()->set('admin.'.$key, $value);

        $this->artisan('app:create-admin')
            ->expectsOutputToContain($message)
            ->doesntExpectOutputToContain('test-admin-password')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($original, $user->refresh()->getAttributes());
    }

    /** @return list<array{string, string|null, string}> */
    public static function invalidConfiguration(): array
    {
        return [
            ['email', null, 'ADMIN_EMAIL is required.'],
            ['email', '', 'ADMIN_EMAIL is required.'],
            ['email', '   ', 'ADMIN_EMAIL is required.'],
            ['email', 'invalid-email', 'ADMIN_EMAIL must be a valid email address.'],
            ['password', null, 'ADMIN_PASSWORD is required.'],
            ['password', '', 'ADMIN_PASSWORD is required.'],
            ['password', 'short-value', 'ADMIN_PASSWORD must be at least 12 characters.'],
        ];
    }

    public function test_a_twelve_character_password_is_accepted(): void
    {
        config()->set('admin.password', 'twelve-chars!');

        $this->artisan('app:create-admin')->assertSuccessful();

        $this->assertTrue(Hash::check('twelve-chars!', User::query()->sole()->password));
    }
}
