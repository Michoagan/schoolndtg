<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Eleve extends Authenticatable
{
    use HasFactory, \Laravel\Sanctum\HasApiTokens, Notifiable;

    protected $fillable = [
        'matricule',
        'nom',
        'prenom',
        'date_naissance',
        'lieu_naissance',
        'sexe',
        'adresse',
        'telephone',
        'email',
        'nom_parent',
        'telephone_parent',
        'repetiteur_whatsapp',
        'repetiteurs',
        'photo',
        'classe_id',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'date_naissance' => 'date',
        'repetiteurs' => 'array',
    ];

    /**
     * Relation avec la classe
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Accessor pour le nom complet
     */
    public function getNomCompletAttribute()
    {
        return $this->nom.' '.$this->prenom;
    }

    /**
     * Scope pour filtrer par classe
     */
    public function scopeByClasse($query, $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    public function presences()
    {
        return $this->hasMany(Presence::class);
    }

    public function notes()
    {
        return $this->hasMany(Note::class);
    }

    public function conduites()
    {
        return $this->hasMany(Conduite::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /**
     * Relation avec les tuteurs (Parents)
     */
    public function tuteurs()
    {
        return $this->belongsToMany(Tuteur::class, 'eleve_tuteur')
            ->withPivot('lien_tuteur')
            ->withTimestamps();
    }

    /**
     * Relation avec les contributions via la classe.
     */
    public function contributions()
    {
        return $this->hasManyThrough(
            Contribution::class,
            Classe::class,
            'id', // Clé étrangère sur la table classes
            'classe_id', // Clé étrangère sur la table contributions
            'classe_id', // Clé locale sur la table eleves
            'id' // Clé locale sur la table classes
        );
    }

    /**
     * Obtenir les paiements réussis.
     */
    public function paiementsReussis(): HasMany
    {
        return $this->paiements()->reussis();
    }

    /**
     * Obtenir le total des paiements réussis.
     */
    protected function totalPaiements(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->paiementsReussis()->sum('montant')
        );
    }

    /**
     * Vérifier si l'élève a une contribution complètement payée.
     */
    public function aContributionPayee($contributionId): bool
    {
        $contribution = Contribution::find($contributionId);
        if (! $contribution) {
            return false;
        }

        $totalPaye = $this->paiementsReussis()
            ->where('contribution_id', $contributionId)
            ->sum('montant');

        return $totalPaye >= $contribution->montant_total;
    }

    /**
     * Obtenir le solde restant pour une contribution.
     */
    public function soldeRestantContribution($contributionId): float
    {
        $contribution = Contribution::find($contributionId);
        if (! $contribution) {
            return 0;
        }

        $totalPaye = $this->paiementsReussis()
            ->where('contribution_id', $contributionId)
            ->sum('montant');

        return max(0, $contribution->montant_total - $totalPaye);
    }

    public function notesExamens()
    {
        return $this->hasMany(NoteExamen::class);
    }
}
