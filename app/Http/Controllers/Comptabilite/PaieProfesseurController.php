<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Professeur;
use App\Models\Classe;
use App\Models\Direction;
use App\Models\PrimeMensuelle;
use App\Models\CahierTexte;
use App\Models\PaiementProfesseur;
use Illuminate\Support\Facades\DB;

class PaieProfesseurController extends Controller
{
    // Récupérer les paramètres fixes (Taux Horaires Classes, Salaire Base Personnel)
    public function getConfiguration(Request $request)
    {
        $classes = Classe::select('id', 'nom', 'niveau', 'taux_horaire', 'professeur_principal_id')
            ->with('professeurPrincipal:id,last_name,first_name')
            ->get();
            
        $professeurs = Professeur::select('id', 'last_name', 'first_name', 'phone')
            ->with('classes:id,nom,niveau,taux_horaire')
            ->get();
        
        $personnel = Direction::select('id', 'last_name', 'first_name', 'role', 'salaire_base')
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'classes' => $classes,
            'professeurs' => $professeurs,
            'personnel' => $personnel,
        ]);
    }

    // Sauvegarder les paramètres fixes
    public function saveConfiguration(Request $request)
    {
        $request->validate([
            'classes' => 'nullable|array',
            'personnel' => 'nullable|array'
        ]);

        DB::beginTransaction();
        try {
            // Mettre à jour le taux horaire des classes
            if ($request->has('classes')) {
                foreach($request->classes as $c) {
                    Classe::where('id', $c['id'])->update(['taux_horaire' => $c['taux_horaire']]);
                }
            }

            if ($request->has('personnel')) {
                foreach($request->personnel as $p) {
                    Direction::where('id', $p['id'])->update(['salaire_base' => $p['salaire_base']]);
                }
            }
            
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Configuration sauvegardée.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Erreur: '.$e->getMessage()], 500);
        }
    }

    // Récupérer les primes du mois
    public function getPrimesMensuelles(Request $request)
    {
        $request->validate([
            'mois' => 'required|integer|min:1|max:12',
            'annee' => 'required|integer',
        ]);

        $primes = PrimeMensuelle::where('mois', $request->mois)
            ->where('annee', $request->annee)
            ->with(['professeur:id,last_name,first_name', 'directionUser:id,last_name,first_name,role'])
            ->get();

        return response()->json([
            'success' => true,
            'primes' => $primes
        ]);
    }

    // Sauvegarder les primes d'un mois
    public function savePrimesMensuelles(Request $request)
    {
        $request->validate([
            'mois' => 'required|integer|min:1|max:12',
            'annee' => 'required|integer',
            'primes' => 'required|array',
        ]);

        DB::beginTransaction();
        try {
            // Effacer les primes existantes pour ce mois pour tout recréer
            PrimeMensuelle::where('mois', $request->mois)->where('annee', $request->annee)->delete();

            foreach($request->primes as $p) {
                PrimeMensuelle::create([
                    'mois' => $request->mois,
                    'annee' => $request->annee,
                    'professeur_id' => $p['professeur_id'] ?? null,
                    'direction_user_id' => $p['direction_user_id'] ?? null,
                    'type_prime' => $p['type_prime'],
                    'montant' => $p['montant'],
                    'motif' => $p['motif'] ?? null
                ]);
            }

            DB::commit();
            return response()->json(['success' => true, 'message' => 'Primes enregistrées.']);
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
        // Les taux horaires sont fixés par classe
        $classes = Classe::all()->keyBy('id');
        $resultats = [];

        foreach ($professeurs as $prof) {
            // Heures non payées du mois (paiement_id IS NULL)
            $heuresEffectuees = CahierTexte::where('professeur_id', $prof->id)
                ->whereMonth('date_cours', $mois)
                ->whereYear('date_cours', $annee)
                ->whereNull('paiement_id')
                ->get();

            $totalHeuresVol = 0;
            $montantHeures = 0;
            $montantPrimes = 0;

            // Primes mensuelles du professeur pour ce mois
            $primes = PrimeMensuelle::where('professeur_id', $prof->id)
                ->where('mois', $mois)
                ->where('annee', $annee)
                ->get();

            if ($heuresEffectuees->isEmpty() && $primes->isEmpty()) {
                continue;
            }

            $heuresParClasse = $heuresEffectuees->groupBy('classe_id');
            $details_heures = [];

            foreach ($heuresParClasse as $classeId => $coursList) {
                $heures = $coursList->sum('duree_cours');
                $totalHeuresVol += $heures;

                // Taux fixé sur la classe
                $classe = $classes->get($classeId);
                $tauxApplique = $classe ? $classe->taux_horaire : 0;
                $nomClasse = $classe ? $classe->nom : 'Inconnue';

                $montant = $heures * $tauxApplique;
                $montantHeures += $montant;

                $details_heures[] = [
                    'classe'  => $nomClasse,
                    'heures'  => $heures,
                    'taux'    => $tauxApplique,
                    'montant' => $montant,
                ];
            }

            // Somme des primes du professeur
            foreach ($primes as $prime) {
                $montantPrimes += $prime->montant;
            }

            $montantTotal = $montantHeures + $montantPrimes;

            $resultats[] = [
                'professeur'    => $prof,
                'total_heures'  => $totalHeuresVol,
                'montant_heures'=> $montantHeures,
                'montant_primes'=> $montantPrimes,
                'montant_total' => $montantTotal,
                'details_heures'=> $details_heures,
                'primes_list'   => $primes,
            ];
        }

        return response()->json([
            'success' => true,
            'mois'    => $mois,
            'annee'   => $annee,
            'paies'   => $resultats,
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
            $professeurs = Professeur::all();
            // Taux horaires fixés par classe
            $classes = Classe::all()->keyBy('id');
            $paiesEnvoyees = 0;

            foreach ($professeurs as $prof) {
                // Uniquement les heures non encore payées ce mois
                $heuresEffectuees = CahierTexte::where('professeur_id', $prof->id)
                    ->whereMonth('date_cours', $mois)
                    ->whereYear('date_cours', $annee)
                    ->whereNull('paiement_id')
                    ->get();

                $totalHeuresVol = 0;
                $montantHeures  = 0;
                $montantPrimes  = 0;

                // Primes mensuelles du professeur
                $primes = PrimeMensuelle::where('professeur_id', $prof->id)
                    ->where('mois', $mois)
                    ->where('annee', $annee)
                    ->get();

                if ($heuresEffectuees->isEmpty() && $primes->isEmpty()) {
                    continue;
                }

                $heuresParClasse = $heuresEffectuees->groupBy('classe_id');

                foreach ($heuresParClasse as $classeId => $coursList) {
                    $heures = $coursList->sum('duree_cours');
                    $totalHeuresVol += $heures;

                    // Taux fixé sur la classe
                    $tauxApplique = $classes->has($classeId) ? $classes->get($classeId)->taux_horaire : 0;
                    $montantHeures += $heures * $tauxApplique;
                }

                foreach ($primes as $prime) {
                    $montantPrimes += $prime->montant;
                }

                $montantTotal = $montantHeures + $montantPrimes;

                if ($montantTotal > 0 || $totalHeuresVol > 0) {
                    $paiement = PaiementProfesseur::updateOrCreate(
                        ['professeur_id' => $prof->id, 'mois' => $mois, 'annee' => $annee],
                        [
                            'total_heures'   => $totalHeuresVol,
                            'montant_heures' => $montantHeures,
                            'montant_primes' => $montantPrimes,
                            'montant_total'  => $montantTotal,
                            'statut'         => 'paye',
                            'date_paiement'  => now(),
                        ]
                    );

                    // Marquer les heures comme payées
                    CahierTexte::whereIn('id', $heuresEffectuees->pluck('id'))->update([
                        'paiement_id' => $paiement->id,
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

    // Télécharger la fiche de paie d'un professeur
    public function downloadFichePaie($id)
    {
        $paiement = PaiementProfesseur::with('professeur')->findOrFail($id);

        // On crée un objet similaire au modèle Salaire pour réutiliser la vue PDF existante
        $salaire = (object) [
            'annee' => $paiement->annee,
            'mois' => $paiement->mois,
            'professeur_id' => $paiement->professeur_id,
            'direction_user_id' => null,
            'directionUser' => null,
            'professeur' => $paiement->professeur,
            'statut' => $paiement->statut,
            'date_paiement' => $paiement->date_paiement,
            'taux_horaire' => $paiement->total_heures > 0 ? round($paiement->montant_heures / $paiement->total_heures) : 0,
            'montant_base' => $paiement->montant_heures,
            'heures_travaillees' => $paiement->total_heures,
            'primes' => $paiement->montant_primes,
            'retenues' => 0,
            'net_a_payer' => $paiement->montant_total
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.fiche_paie', [
            'salaire' => $salaire
        ]);
        
        $nom = $paiement->professeur ? str_replace(' ', '_', $paiement->professeur->last_name . '_' . $paiement->professeur->first_name) : 'Anonyme';
        $filename = "fiche_paie_professeur_{$paiement->mois}_{$paiement->annee}_{$nom}.pdf";

        return $pdf->download($filename);
    }
}
