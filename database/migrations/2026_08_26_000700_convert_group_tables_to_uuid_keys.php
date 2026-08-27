<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `group_members` DROP FOREIGN KEY `group_members_group_id_foreign`');
        DB::statement('ALTER TABLE `cycles` DROP FOREIGN KEY `cycles_group_id_foreign`');

        DB::statement('ALTER TABLE `groups` MODIFY `id` CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE `groups` DROP PRIMARY KEY');
        DB::statement('ALTER TABLE `groups` ADD PRIMARY KEY (`id`)');

        DB::statement('ALTER TABLE `group_members` MODIFY `group_id` CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE `cycles` MODIFY `group_id` CHAR(36) NOT NULL');

        DB::statement('ALTER TABLE `group_members` ADD CONSTRAINT `group_members_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `cycles` ADD CONSTRAINT `cycles_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `cycles` DROP FOREIGN KEY `cycles_group_id_foreign`');
        DB::statement('ALTER TABLE `group_members` DROP FOREIGN KEY `group_members_group_id_foreign`');

        DB::statement('ALTER TABLE `groups` DROP PRIMARY KEY');
        DB::statement('ALTER TABLE `groups` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        DB::statement('ALTER TABLE `groups` ADD PRIMARY KEY (`id`)');

        DB::statement('ALTER TABLE `group_members` MODIFY `group_id` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `cycles` MODIFY `group_id` BIGINT UNSIGNED NOT NULL');

        DB::statement('ALTER TABLE `group_members` ADD CONSTRAINT `group_members_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE');
        DB::statement('ALTER TABLE `cycles` ADD CONSTRAINT `cycles_group_id_foreign` FOREIGN KEY (`group_id`) REFERENCES `groups` (`id`) ON DELETE CASCADE');
    }
};
