<?php

namespace Database\Seeders;

use App\Models\Distribution;
use Illuminate\Database\Seeder;

class DistributionSeeder extends Seeder
{
    /**
     * Seed the canonical Enderman Grief Control distribution listings.
     */
    public function run(): void
    {
        $distributions = [
            [
                'provider' => 'modrinth',
                'name' => 'Modrinth',
                'loader' => 'combined',
                'project_identifier' => '6jCDxmNc',
                'listing_url' => 'https://modrinth.com/plugin/enderman-grief-control',
                'active' => true,
            ],
            [
                'provider' => 'curseforge',
                'name' => 'CurseForge Fabric',
                'loader' => 'fabric',
                'project_identifier' => '1686338',
                'listing_url' => 'https://www.curseforge.com/minecraft/mc-mods/enderman-grief-control',
                'active' => true,
            ],
            [
                'provider' => 'curseforge',
                'name' => 'CurseForge Paper',
                'loader' => 'paper',
                'project_identifier' => '1686360',
                'listing_url' => 'https://www.curseforge.com/minecraft/bukkit-plugins/enderman-grief-control',
                'active' => true,
            ],
        ];

        foreach ($distributions as $distribution) {
            Distribution::query()->updateOrCreate(
                [
                    'provider' => $distribution['provider'],
                    'loader' => $distribution['loader'],
                ],
                $distribution,
            );
        }
    }
}
