<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Salaire extends Model
{
    use HasFactory;

    protected $fillable = [
        'professeur_id', 'direction_user_id', 'mois', 'annee', 
        'heures_travaillees', 'taux_horaire', 
        'montant_base', 'primes', 'retenues', 
        'net_a_payer', 'statut', 'date_paiement',
        'accuse_reception', 'date_accuse'
    ];

    protected $casts = [
        'date_paiement' => 'date',
        'accuse_reception' => 'boolean',
        'date_accuse' => 'datetime',
    ];

    public function professeur()
    {
        return $this->belongsTo(Professeur::class);
    }
    
    public function directionUser()
    {
        return $this->belongsTo(Direction::class, 'direction_user_id');
    }
}
