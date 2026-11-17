<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Create or update a verified admin account from configured environment variables';

    public function handle(): int
    {
        $email = trim((string) config('admin.email'));
        $password = (string) config('admin.password');

        if ($email === '') {
            $this->error('ADMIN_EMAIL is required.');

            return self::FAILURE;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('ADMIN_EMAIL must be a valid email address.');

            return self::FAILURE;
        }

        if ($password === '') {
            $this->error('ADMIN_PASSWORD is required.');

            return self::FAILURE;
        }

        if (mb_strlen($password) < 12) {
            $this->error('ADMIN_PASSWORD must be at least 12 characters.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = (string) config('admin.name', 'Admin');
        $user->password = $password;
        $user->is_admin = true;
        $user->email_verified_at ??= Carbon::now();
        $user->save();

        $this->info('Admin account ready: '.$email);

        return self::SUCCESS;
    }
}
