<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Paiement extends Model
{
    use HasFactory;

    /**
     * Les attributs qui sont mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'reference',
        'eleve_id',
        'contribution_id',
        'montant',
        'methode', // 'kkiapay', 'fedapay', 'especes'
        'statut',
        'reference_externe',
        'erreur',
        'details_paiement',
        'date_paiement',
        'auteur_id',
        'observation',
        'annee_scolaire'
    ];

    /**
     * Les attributs qui doivent être castés.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'montant' => 'decimal:2',
        'date_paiement' => 'datetime',
        'details_paiement' => 'array',
    ];

    /**
     * Statuts de paiement possibles.
     */
    const STATUT_EN_ATTENTE = 'pending';
    const STATUT_REUSSI = 'success';
    const STATUT_ECHOUE = 'failed';
    const STATUT_ANNULE = 'cancelled';

    /**
     * Méthodes de paiement possibles.
     */
    const METHODE_KKIAPAY = 'kkiapay';
    const METHODE_FEDAPAY = 'fedapay';
    const METHODE_ESPECES = 'especes';

    /**
     * Relation avec l'élève.
     */
    public function eleve(): BelongsTo
    {
        return $this->belongsTo(Eleve::class);
    }
    // Relationship removed as it relied on non-existent parent_id


    /**
     * Relation avec la contribution.
     */
    public function contribution(): BelongsTo
    {
        return $this->belongsTo(Contribution::class);
    }

    /**
     * Scope pour les paiements réussis.
     */
    public function scopeReussis($query)
    {
        return $query->where('statut', self::STATUT_REUSSI);
    }

    /**
     * Scope pour les paiements en attente.
     */
    public function scopeEnAttente($query)
    {
        return $query->where('statut', self::STATUT_EN_ATTENTE);
    }

    /**
     * Scope pour les paiements échoués.
     */
    public function scopeEchoues($query)
    {
        return $query->where('statut', self::STATUT_ECHOUE);
    }

    /**
     * Vérifie si le paiement est réussi.
     */
    public function estReussi(): bool
    {
        return $this->statut === self::STATUT_REUSSI;
    }

    /**
     * Vérifie si le paiement est en attente.
     */
    public function estEnAttente(): bool
    {
        return $this->statut === self::STATUT_EN_ATTENTE;
    }

    /**
     * Vérifie si le paiement a échoué.
     */
    public function aEchoue(): bool
    {
        return $this->statut === self::STATUT_ECHOUE;
    }

    /**
     * Marquer le paiement comme réussi.
     */
    public function marquerCommeReussi(string $referenceExterne = null, array $details = []): bool
    {
        return $this->update([
            'statut' => self::STATUT_REUSSI,
            'reference_externe' => $referenceExterne,
            'details_paiement' => $details,
            'date_paiement' => now(),
        ]);
    }

    /**
     * Marquer le paiement comme échoué.
     */
    public function marquerCommeEchoue(string $erreur = null): bool
    {
        return $this->update([
            'statut' => self::STATUT_ECHOUE,
            'erreur' => $erreur,
        ]);
    }

    /**
     * Marquer le paiement comme annulé.
     */
    public function marquerCommeAnnule(): bool
    {
        return $this->update([
            'statut' => self::STATUT_ANNULE,
        ]);
    }

    /**
     * Obtenir le libellé du statut.
     */
    public function getLibelleStatutAttribute(): string
    {
        return match($this->statut) {
            self::STATUT_EN_ATTENTE => 'En attente',
            self::STATUT_REUSSI => 'Réussi',
            self::STATUT_ECHOUE => 'Échoué',
            self::STATUT_ANNULE => 'Annulé',
            default => 'Inconnu',
        };
    }

    /**
     * Obtenir le libellé de la méthode.
     */
    public function getLibelleMethodeAttribute(): string
    {
        return match($this->methode) {
            self::METHODE_KKIAPAY => 'KKiaPay',
            self::METHODE_FEDAPAY => 'Fedapay',
            self::METHODE_ESPECES => 'Espèces',
            default => 'Inconnue',
        };
    }

    /**
     * Obtenir le montant formaté.
     */
    public function getMontantFormateAttribute(): string
    {
        return number_format($this->montant, 0, ',', ' ') . ' FCFA';
    }

    /**
     * Boot du modèle.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($paiement) {
            if (empty($paiement->reference)) {
                $paiement->reference = 'PYR-' . date('Y') . '-' . strtoupper(uniqid());
            }
            if (empty($paiement->annee_scolaire)) {
                $paiement->annee_scolaire = \App\Models\Setting::getCurrentAnneeScolaire();
            }
        });
    }
}