<?php

namespace App\Console\Commands;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateDashboardDemo extends Command
{
    protected $signature = 'kijumbe:create-dashboard-demo';

    protected $description = 'Create one local demo administrator without sample business data.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('This command is only available in local and testing environments.');

            return self::FAILURE;
        }
        if (User::where('email', 'demo@kijumbe.local')->exists()) {
            $this->info('Demo account already exists. Its password has not been changed.');

            return self::SUCCESS;
        }
        $password = Str::password(24);
        User::create(['name' => 'Demo Administrator', 'email' => 'demo@kijumbe.local', 'password' => $password, 'system_role' => SystemRole::Admin, 'is_active' => true]);
        $path = storage_path('app/private/dashboard-demo.txt');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $previousMask = umask(0077);
        try {
            file_put_contents($path, "Kijumbe local dashboard\nEmail: demo@kijumbe.local\nPassword: {$password}\n");
        } finally {
            umask($previousMask);
        }
        $this->info('Demo administrator created. Credentials: '.$path);

        return self::SUCCESS;
    }
}
