<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    /**
     * Save the store GCash QR code and phone number.
     */
    public function updateGcashQr(Request $request)
    {
        $request->validate([
            'gcash_qr' => [
                'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:5120',
            ],

            'gcash_phone' => [
                'nullable',
                'string',
                'max:20',
            ],
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save GCash Phone Number
        |--------------------------------------------------------------------------
        */
        Setting::updateOrCreate(
            [
                'key' => 'gcash_phone',
                'branch_id' => null,
            ],
            [
                'value' => $request->gcash_phone,
                'group' => 'payment',
                'label' => 'GCash Phone Number',
                'description' => 'GCash account phone number used for customer payments.',
                'type' => 'text',
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Save GCash QR Code
        |--------------------------------------------------------------------------
        */
        if ($request->hasFile('gcash_qr')) {

            $setting = Setting::where('key', 'gcash_qr')
                ->whereNull('branch_id')
                ->first();

            // Delete previous QR image
            if ($setting && $setting->value) {
                $oldPath = $setting->value;

                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            // Save new QR image
            $path = $request->file('gcash_qr')->store(
                'settings/gcash',
                'public'
            );

            // Create/update QR setting
            Setting::updateOrCreate(
                [
                    'key' => 'gcash_qr',
                    'branch_id' => null,
                ],
                [
                    'value' => $path,
                    'group' => 'payment',
                    'label' => 'GCash QR Code',
                    'description' => 'Store GCash QR code used for customer payments.',
                    'type' => 'image',
                ]
            );
        }

        return redirect()
            ->route('admin.account')
            ->with('success', 'GCash payment settings updated successfully.');
    }
}