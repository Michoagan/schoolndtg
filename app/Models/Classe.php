<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Model;


class Classe extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'nom',
        'niveau',
        'professeur_principal_id',
        'cout_contribution',
        'capacite_max',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'cout_contribution' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Get the professeur principal for the class.
     */
   public function professeurPrincipal(): BelongsTo
    {
        return $this->belongsTo(Professeur::class, 'professeur_principal_id');
    }


    /**
     * Get the matieres for the class.
     */
    public function matieres(): BelongsToMany
    {
        return $this->belongsToMany(Matiere::class)
            ->withPivot('coefficient', 'professeur_id', 'ordre_affichage')
            ->with(['professeur']) // Charger automatiquement le professeur
            ->orderBy('ordre_affichage')
            ->withTimestamps();
    }

    /**
     * Get the eleves for the class.
     */
    public function eleves(): HasMany
    {
        return $this->hasMany(Eleve::class);
    }

    /**
     * Scope a query to only include active classes.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to order by niveau and nom.
     */
   // Dans app/Models/Classe.php

/**
 * Scope pour ordonner par niveau
 */
public function scopeOrderByNiveau($query)
{
    // Définir l'ordre personnalisé des niveaux
    $niveauxOrder = [
        '6ème', '5ème', '4ème', '3ème', 
        'Seconde', 'Première', 'Terminale'
    ];
    
    $caseString = "CASE niveau";
    foreach ($niveauxOrder as $index => $niveau) {
        $caseString .= " WHEN '" . $niveau . "' THEN " . ($index + 1);
    }
    $caseString .= " ELSE 99 END";
    
    return $query->orderByRaw($caseString);
}

    /**
     * Get the nombre of eleves in the class.
     */
    public function getElevesCountAttribute()
    {
        return $this->eleves()->count();
    }

    /**
     * Check if the class has available places.
     */
    public function hasAvailablePlaces()
    {
        return $this->eleves_count < $this->capacite_max;
    }

    /**
     * Get the available places in the class.
     */
    public function getAvailablePlacesAttribute()
    {
        return $this->capacite_max - $this->eleves_count;
    }

     public function getAllProfesseursAttribute()
    {
        $professeurs = collect();
        
        // Ajouter le professeur principal
        if ($this->professeurPrincipal) {
            $professeurs->push($this->professeurPrincipal);
        }
        
        // Ajouter les professeurs des matières
        foreach ($this->matieres as $matiere) {
            if ($matiere->pivot->professeur_id && $matiere->professeur) {
                $professeurs->push($matiere->professeur);
            }
        }
        
        return $professeurs->unique('id');
    }

    public function getProfesseurPrincipalAttribute()
    {
        return $this->belongsTo(Professeur::class, 'professeur_principal_id');
    }   

     public function professeurs()
    {
        return $this->belongsToMany(Professeur::class, 'classe_professeur');
    }

      public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function cahierTextes()
    {
        return $this->hasMany(CahierTexte::class);
    }

    public function evenements(): BelongsToMany
    {
        return $this->belongsToMany(Evenement::class, 'classe_evenement');
    }

    public function notesExamens()
    {
        return $this->hasMany(NoteExamen::class);
    }
       public function contributions(): HasMany
    {
        return $this->hasMany(Contribution::class);
    }

    /**
     * Obtenir la contribution active pour l'année en cours.
     */
    public function contributionActive()
    {
        return $this->contributions()
            ->where('annee_scolaire', Contribution::getAnneeScolaireCourante())
            ->first();
    }

    /**
     * Obtenir le total des paiements pour la classe.
     */
    public function getTotalPaiementsAttribute()
    {
        $total = 0;
        foreach ($this->eleves as $eleve) {
            $total += $eleve->total_paiements;
        }
        return $total;
    }
  
}