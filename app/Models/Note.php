<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $fillable = [
        'premier_interro',
        'deuxieme_interro',
        'troisieme_interro',
        'quatrieme_interro',
        'moyenne_interro',
        'premier_devoir',
        'deuxieme_devoir',
        'moyenne_trimestrielle',
        'coefficient',
        'moyenne_coefficientee',
        'trimestre',
        'commentaire',
        'eleve_id',
        'classe_id',
        'professeur_id',
        'matiere_id',
        'annee_scolaire'
    ];

    protected $casts = [
        'premier_interro' => 'decimal:2',
        'deuxieme_interro' => 'decimal:2',
        'troisieme_interro' => 'decimal:2',
        'quatrieme_interro' => 'decimal:2',
        'moyenne_interro' => 'decimal:2',
        'premier_devoir' => 'decimal:2',
        'deuxieme_devoir' => 'decimal:2',
        'moyenne_trimestrielle' => 'decimal:2',
        'coefficient' => 'decimal:1',
        'moyenne_coefficientee' => 'decimal:2',
    ];

    // Relations
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

    public function matiere()
    {
        return $this->belongsTo(Matiere::class);
    }

    // Méthodes de calcul
    public function calculerMoyenneInterro()
    {
        $notes = [
            $this->premier_interro,
            $this->deuxieme_interro,
            $this->troisieme_interro,
            $this->quatrieme_interro
        ];

        // Filtrer les notes non nulles
        $notesValides = array_filter($notes, function($note) {
            return !is_null($note);
        });

        if (count($notesValides) === 0) {
            return null;
        }

        return array_sum($notesValides) / count($notesValides);
    }

    public function calculerMoyenneTrimestrielle()
    {
        $moyenneInterro = $this->moyenne_interro;
        $devoir1 = $this->premier_devoir;
        $devoir2 = $this->deuxieme_devoir;

        // Vérifier si toutes les notes nécessaires sont présentes
        if (is_null($moyenneInterro) || is_null($devoir1) || is_null($devoir2)) {
            return null;
        }

        return ($moyenneInterro + $devoir1 + $devoir2) / 3;
    }

   public function calculerMoyenneCoefficientee()
{
    if (is_null($this->moyenne_trimestrielle)) {
        return null;
    }

    return $this->moyenne_trimestrielle * $this->coefficient;
}

protected static function boot()
{
    parent::boot();

    static::saving(function ($note) {
        // Calculer la moyenne des interros
        $note->moyenne_interro = $note->calculerMoyenneInterro();
        
        // Calculer la moyenne trimestrielle
        $note->moyenne_trimestrielle = $note->calculerMoyenneTrimestrielle();
        
        // Calculer la moyenne coefficientée
        $note->moyenne_coefficientee = $note->calculerMoyenneCoefficientee();
        
        // Générer le commentaire automatiquement
        $note->commentaire = $note->getCommentaireAttribute(null);

        // Imposer l'année scolaire active si non spécifiée
        if (empty($note->annee_scolaire)) {
            $note->annee_scolaire = \App\Models\Setting::getCurrentAnneeScolaire();
        }
    });
}

    public function getCommentaireAttribute($value)
    {
        if ($value) {
            return $value;
        }

        // Calcul automatique du commentaire basé sur la moyenne trimestrielle
        if (is_null($this->moyenne_trimestrielle)) {
            return null;
        }

        $moyenne = $this->moyenne_trimestrielle;

        if ($moyenne >= 16) return 'Excellent';
        if ($moyenne >= 14) return 'Très-bien';
        if ($moyenne >= 12) return 'Bien';
        if ($moyenne >= 10) return 'Assez-bien';
        if ($moyenne >= 8) return 'Passable';
        if ($moyenne >= 6) return 'Insuffisant';
        if ($moyenne >= 4) return 'Faible';
        return 'Médiocre';
    }

    // Événements
    
}