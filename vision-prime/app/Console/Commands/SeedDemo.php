<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\ContentStandardsSeeder;
use Database\Seeders\DemoWorkspaceSeeder;
use Database\Seeders\PromptTemplateSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * ساخت محیط دمو در یک فرمان — برای جلسات فروش و پیش‌نمایش مشتری.
 *
 *   php artisan demo:seed            # روی دیتابیس فعلی (idempotent)
 *   php artisan demo:seed --fresh    # دیتابیس را از نو می‌سازد (محیط دمو/تست)
 *
 * حساب‌های دمو (همگی با سازمان «Vision Prime Demo Agency»):
 *   demo@visionprime.test      / DemoAdmin2024!Secure#      (agency-admin)
 *   marketing@visionprime.test / Marketing2026!Secure#      (marketing-manager)
 */
class SeedDemo extends Command
{
    protected $signature = 'demo:seed {--fresh : دیتابیس را از نو بساز (فقط محیط دمو!)}';

    protected $description = 'ساخت محیط دموی کامل: سازمان، کاربران، سایت، داده GSC، فرصت‌ها، بازبینی‌ها و لیدها';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            if (! app()->environment(['local', 'testing', 'staging'])) {
                $this->error('‌--fresh فقط در محیط local/testing/staging مجاز است.');

                return self::FAILURE;
            }
            $this->warn('بازسازی دیتابیس از نو...');
            Artisan::call('migrate:fresh', ['--force' => true]);
            $this->line(Artisan::output());
        }

        foreach (
            [
                RolePermissionSeeder::class => 'نقش‌ها و مجوزها',
                ContentStandardsSeeder::class => 'استانداردهای محتوا',
                PromptTemplateSeeder::class => 'کتابخانه پرامپت‌ها',
                DemoWorkspaceSeeder::class => 'فضای کاری دمو',
            ] as $seeder => $label
        ) {
            $this->info("▸ {$label}...");
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        $this->newLine();
        $this->info('✅ محیط دمو آماده است.');
        $this->table(
            ['نقش', 'ایمیل', 'رمز عبور'],
            [
                ['مدیر آژانس', 'demo@visionprime.test', 'DemoAdmin2024!Secure#'],
                ['مدیر بازاریابی', 'marketing@visionprime.test', 'Marketing2026!Secure#'],
            ],
        );
        $this->line('سازمان دمو: «Vision Prime Demo Agency» — شامل سایت، داده GSC، فرصت‌ها، صف بازبینی و لیدها.');

        return self::SUCCESS;
    }
}
