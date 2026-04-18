<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    /**
     * Display a listing of the settings.
     */
    public function index()
    {
        // Return key-value pairs
        $settings = Setting::all()->pluck('value', 'key');
        return response()->json($settings);
    }

    /**
     * Update the specified settings.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'annee_scolaire_debut' => 'nullable|date',
            'annee_scolaire_fin' => 'nullable|date|after:annee_scolaire_debut',
            'current_annee_scolaire' => 'nullable|string',
            'current_trimestre' => 'nullable|integer|between:1,3',
            'trimestre_1_debut' => 'nullable|date',
            'trimestre_1_fin' => 'nullable|date|after:trimestre_1_debut',
            'trimestre_2_debut' => 'nullable|date',
            'trimestre_2_fin' => 'nullable|date|after:trimestre_2_debut',
            'trimestre_3_debut' => 'nullable|date',
            'trimestre_3_fin' => 'nullable|date|after:trimestre_3_debut',
            'paiement_en_ligne_actif' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data as $key => $value) {
                if ($value !== null) {
                    // Convertir les booléens en chaînes "1" ou "0" pour éviter de stocker des chaînes vides
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    
                    Setting::updateOrCreate(
                        ['key' => $key],
                        ['value' => $value]
                    );
                }
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Paramètres mis à jour avec succès.',
            'settings' => Setting::all()->pluck('value', 'key')
        ]);
    }
}
