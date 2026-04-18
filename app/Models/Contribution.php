<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Contribution extends Model
{
    use HasFactory;

    /**
     * Les attributs qui sont mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'classe_id',
        'annee_scolaire',
        'type',
        'montant_total',
        'montant_paye',
        'description',
        'date_limite',
        'est_obligatoire',
    ];

    /**
     * Les attributs qui doivent être castés.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'montant_total' => 'decimal:2',
        'montant_paye' => 'decimal:2',
        'date_limite' => 'date',
        'est_obligatoire' => 'boolean',
    ];

    /**
     * Types de contribution possibles.
     */
    const TYPE_SCOLARITE = 'scolarite';
    const TYPE_FRAIS_INSCRIPTION = 'inscription';
    const TYPE_FRAIS_DOSSIER = 'dossier';
    const TYPE_FRAIS_UNIFORME = 'uniforme';
    const TYPE_FRAIS_DIVERS = 'divers';

    /**
     * Relation avec la classe.
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Relation avec les paiements.
     */
    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class)->reussis();
    }

    /**
     * Calculer le solde restant.
     */
    protected function soldeRestant(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->montant_total - $this->montant_paye
        );
    }

    /**
     * Calculer le pourcentage payé.
     */
    protected function pourcentagePaye(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->montant_total > 0 
                ? round(($this->montant_paye / $this->montant_total) * 100, 2) 
                : 0
        );
    }

    /**
     * Vérifier si la contribution est complètement payée.
     */
    protected function estComplete(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->solde_restant <= 0
        );
    }

    /**
     * Vérifier si la contribution est en retard.
     */
    protected function estEnRetard(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->date_limite && now()->gt($this->date_limite)
        );
    }

    /**
     * Obtenir le libellé du type.
     */
    protected function libelleType(): Attribute
    {
        return Attribute::make(
            get: fn () => match($this->type) {
                self::TYPE_SCOLARITE => 'Frais de scolarité',
                self::TYPE_FRAIS_INSCRIPTION => 'Frais d\'inscription',
                self::TYPE_FRAIS_DOSSIER => 'Frais de dossier',
                self::TYPE_FRAIS_UNIFORME => 'Frais d\'uniforme',
                self::TYPE_FRAIS_DIVERS => 'Frais divers',
                default => 'Type inconnu',
            }
        );
    }

    /**
     * Obtenir le montant total formaté.
     */
    protected function montantTotalFormate(): Attribute
    {
        return Attribute::make(
            get: fn () => number_format($this->montant_total, 0, ',', ' ') . ' FCFA'
        );
    }

    /**
     * Obtenir le montant payé formaté.
     */
    protected function montantPayeFormate(): Attribute
    {
        return Attribute::make(
            get: fn () => number_format($this->montant_paye, 0, ',', ' ') . ' FCFA'
        );
    }

    /**
     * Obtenir le solde restant formaté.
     */
    protected function soldeRestantFormate(): Attribute
    {
        return Attribute::make(
            get: fn () => number_format($this->solde_restant, 0, ',', ' ') . ' FCFA'
        );
    }

    /**
     * Ajouter un paiement à la contribution.
     */
    public function ajouterPaiement(float $montant): bool
    {
        if ($montant <= 0 || $montant > $this->solde_restant) {
            return false;
        }

        $this->increment('montant_paye', $montant);
        return true;
    }

    /**
     * Scope pour les contributions obligatoires.
     */
    public function scopeObligatoires($query)
    {
        return $query->where('est_obligatoire', true);
    }

    /**
     * Scope pour les contributions de l'année en cours.
     */
    public function scopeAnneeCourante($query)
    {
        return $query->where('annee_scolaire', self::getAnneeScolaireCourante());
    }

    /**
     * Obtenir l'année scolaire courante depuis les paramètres globaux.
     */
    public static function getAnneeScolaireCourante(): string
    {
        return \App\Models\Setting::getCurrentAnneeScolaire();
    }

    /**
     * Boot du modèle.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($contribution) {
            if (empty($contribution->annee_scolaire)) {
                $contribution->annee_scolaire = self::getAnneeScolaireCourante();
            }
        });
    }
}