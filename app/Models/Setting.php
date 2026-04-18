<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value', 'group'];

    public static function getCurrentAnneeScolaire()
    {
        $setting = self::where('key', 'annee_scolaire_courante')->first();
        return $setting ? $setting->value : '2025-2026';
    }

    public static function setCurrentAnneeScolaire($annee)
    {
        return self::updateOrCreate(
            ['key' => 'annee_scolaire_courante'],
            ['value' => $annee, 'group' => 'general']
        );
    }
}
