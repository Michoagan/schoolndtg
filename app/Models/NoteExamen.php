<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NoteExamen extends Model
{
    protected $fillable = [
        'eleve_id',
        'type_examen',
        'annee_scolaire',
        'matiere_id',
        'classe_id',
        'valeur',
    ];

    public function eleve()
    {
        return $this->belongsTo(Eleve::class);
    }

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    public function classe()
    {
        return $this->belongsTo(Classe::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($examen) {
            if (empty($examen->annee_scolaire)) {
                $examen->annee_scolaire = \App\Models\Setting::getCurrentAnneeScolaire();
            }
        });
    }
}
