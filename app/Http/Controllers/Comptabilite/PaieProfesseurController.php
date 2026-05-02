<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Professeur;
use App\Models\Classe;
use App\Models\TauxHoraire;
use App\Models\CahierTexte;
use App\Models\PaiementProfesseur;
use Illuminate\Support\Facades\DB;

class PaieProfesseurController extends Controller
{
    // Récupérer la matrice de configuration pour un prof
    public function getConfiguration(Request $request)
    {
        $professeurs = Professeur::with(['classes', 'tauxHoraires'])->get();
        return response()->json([
            'success' => true,
            'professeurs' => $professeurs
        ]);
    }

    // Sauvegarder la configuration de paie d'un professeur
    public function saveConfiguration(Request $request)
    {
        $request->validate([
            'professeur_id' => 'required|exists:professeurs,id',
            'taux' => 'required|array', // Structure: [['classe_id' => X, 'taux_horaire' => Y, 'prime_mensuelle' => Z], ...]
        ]);

        DB::beginTransaction();
        try {
            $profId = $request->professeur_id;
            
            // Effacer les anciens taux pour les recréer proprement
            TauxHoraire::where('professeur_id', $profId)->delete();
            
            foreach($request->taux as $t) {
                TauxHoraire::create([
                    'professeur_id'   => $profId,
                    'classe_id'       => $t['classe_id'] ?? null, // Si null, c'est une prime globale ou un taux par défaut
                    'taux_horaire'    => $t['taux_horaire'] ?? 0,
                    'prime_mensuelle' => $t['prime_mensuelle'] ?? 0,
                ]);
            }
            
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Configuration sauvegardée.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur: '.$e->getMessage()], 500);
        }
    }

    // Simuler ou générer la fiche de paie d'un professeur pour un mois donné
    public function genererPaie(Request $request)
    {
        $request->validate([
            'mois' => 'required|integer|min:1|max:12',
            'annee' => 'required|integer',
            'professeur_id' => 'nullable|exists:professeurs,id'
        ]);

        $mois = $request->mois;
        $annee = $request->annee;
        $profId = $request->professeur_id;

        $query = Professeur::query();
        if ($profId) {
            $query->where('id', $profId);
        }

        $professeurs = $query->get();
        $resultats = [];

        foreach ($professeurs as $prof) {
            // 1. Récupérer toutes les heures de cours VALIDÉES/EFFECTUÉES ce mois-ci
            // On se base sur le Cahier de Texte
            $heuresEffectuees = CahierTexte::where('professeur_id', $prof->id)
                ->whereMonth('date_cours', $mois)
                ->whereYear('date_cours', $annee)
                // ->whereNull('paiement_id') // On peut filtrer si on ne veut pas payer deux fois
                ->get();

            $totalHeuresVol = 0;
            $montantHeures = 0;
            $montantPrimes = 0;
            
            // Grouper par classe pour appliquer le bon taux horaire
            $heuresParClasse = $heuresEffectuees->groupBy('classe_id');
            
            $tauxConfigures = TauxHoraire::where('professeur_id', $prof->id)->get();

            // S'il n'y a pas d'heures effectuées, on skip sauf si prime fixe importante
            if ($heuresEffectuees->isEmpty() && $tauxConfigures->isEmpty()) {
                continue; 
            }

            foreach($heuresParClasse as $classeId => $coursList) {
                $heures = $coursList->sum('duree_cours');
                $totalHeuresVol += $heures;

                // Chercher le taux spécifique pour cette classe et ce prof
                $tauxSpecifique = $tauxConfigures->firstWhere('classe_id', $classeId);
                
                // Sinon chercher le taux global (classe_id null)
                $tauxGlobal = $tauxConfigures->firstWhere('classe_id', null);

                $tauxApplique = $tauxSpecifique ? $tauxSpecifique->taux_horaire : ($tauxGlobal ? $tauxGlobal->taux_horaire : 0);
                
                $montantHeures += ($heures * $tauxApplique);
            }

            // 2. Additionner toutes les primes (Prime globale + prime par classe)
            foreach($tauxConfigures as $tc) {
                // Si la prime est liée à une classe, vérifier qu'il a bien enseigné dans cette classe ce mois-ci ?
                // Selon la règle utilisateur, on l'ajoute s'il a cette attribution.
                $montantPrimes += $tc->prime_mensuelle;
            }

            $montantTotal = $montantHeures + $montantPrimes;

            $resultats[] = [
                'professeur' => $prof,
                'total_heures' => $totalHeuresVol,
                'montant_heures' => $montantHeures,
                'montant_primes' => $montantPrimes,
                'montant_total'  => $montantTotal,
                'details_heures' => $heuresParClasse->map(function($cours) { return $cours->sum('duree_cours'); })
            ];
        }

        return response()->json([
            'success' => true,
            'mois' => $mois,
            'annee' => $annee,
            'paies' => $resultats
        ]);
    }

    // Valider et envoyer les fiches de paie dans les comptes des professeurs
    public function validerPaies(Request $request)
    {
        $request->validate([
            'mois' => 'required|integer|min:1|max:12',
            'annee' => 'required|integer'
        ]);

        $mois = $request->mois;
        $annee = $request->annee;

        DB::beginTransaction();
        try {
            // On réutilise la logique de génération pour avoir les montants exacts
            $professeurs = Professeur::all();
            $paiesEnvoyees = 0;

            foreach ($professeurs as $prof) {
                // Heures non encore payées pour ce mois
                $heuresEffectuees = CahierTexte::where('professeur_id', $prof->id)
                    ->whereMonth('date_cours', $mois)
                    ->whereYear('date_cours', $annee)
                    ->whereNull('paiement_id') // Seulement celles non payées !
                    ->get();

                $totalHeuresVol = 0;
                $montantHeures = 0;
                $montantPrimes = 0;
                
                $heuresParClasse = $heuresEffectuees->groupBy('classe_id');
                $tauxConfigures = TauxHoraire::where('professeur_id', $prof->id)->get();

                if ($heuresEffectuees->isEmpty() && $tauxConfigures->isEmpty()) {
                    continue; 
                }

                foreach($heuresParClasse as $classeId => $coursList) {
                    $heures = $coursList->sum('duree_cours');
                    $totalHeuresVol += $heures;

                    $tauxSpecifique = $tauxConfigures->firstWhere('classe_id', $classeId);
                    $tauxGlobal = $tauxConfigures->firstWhere('classe_id', null);
                    $tauxApplique = $tauxSpecifique ? $tauxSpecifique->taux_horaire : ($tauxGlobal ? $tauxGlobal->taux_horaire : 0);
                    
                    $montantHeures += ($heures * $tauxApplique);
                }

                foreach($tauxConfigures as $tc) {
                    $montantPrimes += $tc->prime_mensuelle;
                }

                $montantTotal = $montantHeures + $montantPrimes;

                if ($montantTotal > 0 || $totalHeuresVol > 0) {
                    // Créer l'enregistrement de paiement
                    $paiement = PaiementProfesseur::updateOrCreate(
                        ['professeur_id' => $prof->id, 'mois' => $mois, 'annee' => $annee],
                        [
                            'total_heures' => $totalHeuresVol,
                            'montant_heures' => $montantHeures,
                            'montant_primes' => $montantPrimes,
                            'montant_total' => $montantTotal,
                            'statut' => 'paye', // ou 'en_attente' selon le workflow. "paye" affichera "Payé" sur le mobile
                            'date_paiement' => now()
                        ]
                    );

                    // Lier les heures du cahier de texte à ce paiement
                    CahierTexte::whereIn('id', $heuresEffectuees->pluck('id'))->update([
                        'paiement_id' => $paiement->id
                    ]);

                    $paiesEnvoyees++;
                }
            }

            DB::commit();
            return response()->json([
                'success' => true, 
                'message' => "$paiesEnvoyees fiches de paie validées et envoyées aux professeurs."
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur de validation: '.$e->getMessage()], 500);
        }
    }
}

