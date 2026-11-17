<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class MakeAdminCommand extends Command
{
    protected $signature = 'users:make-admin {email : Email of an already registered user}';

    protected $description = 'Promote a registered user to admin.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with email {$email}.");

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("{$email} is already an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();

        Log::info('User promoted to admin', ['user_id' => $user->id]);
        $this->info("{$email} is now an admin.");

        return self::SUCCESS;
    }
}
