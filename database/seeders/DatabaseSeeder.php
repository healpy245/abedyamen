<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            WorkspaceUserSeeder::class,
            AppDevelopmentMemberSeeder::class,
            SallyMalanChatbotInstanceSeeder::class,
            SpeedcomChatbotInstanceSeeder::class,
            KamanWhatsappChatbotInstanceSeeder::class,
            MalanCompanyMemberSeeder::class,
            KamanCompanyMemberSeeder::class,
        ]);
    }
}
