<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Presence extends Model
{
    use HasFactory;

    protected $fillable = ['eleve_id', 'classe_id', 'date', 'present', 'professeur_id', 'cours_id', 'annee_scolaire'];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    public function professeur()
    {
        return $this->belongsTo(Professeur::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($presence) {
            if (empty($presence->annee_scolaire)) {
                $presence->annee_scolaire = \App\Models\Setting::getCurrentAnneeScolaire();
            }
        });
    }
}