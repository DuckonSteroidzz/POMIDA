<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            // Business Info
            ['key' => 'business_name', 'value' => 'Peachy', 'group' => 'business', 'label' => 'Business Name', 'type' => 'text'],
            ['key' => 'business_tagline', 'value' => 'Cakes and Deli Cafe', 'group' => 'business', 'label' => 'Tagline', 'type' => 'text'],
            ['key' => 'business_logo', 'value' => null, 'group' => 'business', 'label' => 'Logo', 'type' => 'image'],
            ['key' => 'business_emoji', 'value' => '🍑', 'group' => 'business', 'label' => 'Logo Emoji (fallback)', 'type' => 'text'],

            // Colors
            ['key' => 'primary_color', 'value' => '#F4845F', 'group' => 'appearance', 'label' => 'Primary Color', 'type' => 'color'],
            ['key' => 'secondary_color', 'value' => '#C0392B', 'group' => 'appearance', 'label' => 'Secondary Color', 'type' => 'color'],

            // Contact
            ['key' => 'contact_phone', 'value' => '', 'group' => 'business', 'label' => 'Phone Number', 'type' => 'text'],
            ['key' => 'contact_email', 'value' => '', 'group' => 'business', 'label' => 'Email', 'type' => 'text'],
            ['key' => 'contact_address', 'value' => '', 'group' => 'business', 'label' => 'Address', 'type' => 'text'],
        ];

        foreach ($settings as $s) {
            Setting::updateOrCreate(
                ['key' => $s['key'], 'branch_id' => null],
                $s
            );
        }
    }
}