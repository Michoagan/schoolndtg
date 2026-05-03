<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrimeMensuelle extends Model
{
    use HasFactory;

    protected $table = 'primes_mensuelles';

    protected $fillable = [
        'mois',
        'annee',
        'professeur_id',
        'direction_user_id',
        'type_prime',
        'montant',
        'motif'
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
