<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Professeur;
use App\Models\Presence;
use App\Models\CahierTexte;
use App\Models\Note;

class PerformanceController extends Controller
{
    /**
     * Obtenir les statistiques de performance d'un professeur spécifique
     */
    public function getPerformanceStats(Request $request, $id)
    {
        $professeur = Professeur::findOrFail($id);
        $anneeActive = $request->query('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire());

        $toutesAnnees = \App\Models\HistoriqueEleve::distinct()->pluck('annee_scolaire')->toArray();
        if (!in_array($anneeActive, $toutesAnnees)) {
            $toutesAnnees[] = $anneeActive;
        }
        sort($toutesAnnees);

        // 1. Assiduité et Ponctualité (Basé sur les présences)
        // Note: Nous comptabilisons dans la table 'presences' où 'professeur_id' n'est pas nul
        // S'il n'y a pas de professeur_id sur Presence, on pourrait le lier par Classe ou Cours.
        // Adaptons selon ce qui est dans le modèle Presence.
        $totalHeuresPrevu = Presence::where('professeur_id', $id)->where('annee_scolaire', $anneeActive)->count();
        $heuresAssurees = Presence::where('professeur_id', $id)->where('annee_scolaire', $anneeActive)->where('present', true)->count();
        $tauxAssiduite = $totalHeuresPrevu > 0 ? round(($heuresAssurees / $totalHeuresPrevu) * 100, 2) : 100;

        // 2. Exécution du Programme (Basé sur Cahier de Texte)
        // On somme les durées de cours déclarées
        $heuresEffectueesReelles = CahierTexte::where('professeur_id', $id)->where('annee_scolaire', $anneeActive)->sum('duree_cours');
        $totalCahiersRemplis = CahierTexte::where('professeur_id', $id)->where('annee_scolaire', $anneeActive)->count();

        // Objectif fictif de 40 heures pour calculer un pourcentage moyen d'avancement,
        // (A adapter dynamiquement si le prof a un quota d'heures)
        $objectifMoyenHeure = 40; 
        $tauxProgression = round(($heuresEffectueesReelles / $objectifMoyenHeure) * 100, 2);

        // 3. Impact Pédagogique (Basé sur les Notes)
        // Récupérer les notes attribuées par ce professeur
        $notes = Note::where('professeur_id', $id)->where('annee_scolaire', $anneeActive)->get();
        
        $moyenneGlobale = 0;
        $tauxReussite = 0;
        
        if ($notes->count() > 0) {
            $moyenneGlobale = round($notes->avg('valeur'), 2);
            
            // Calculer le taux de réussite (notes >= 10/20)
            $notesAuDessusMoyenne = $notes->filter(function($note) {
                $noteSur = $note->note_sur ?? 20;
                return $noteSur > 0 && ($note->valeur / $noteSur) >= 0.5;
            })->count();

            $tauxReussite = round(($notesAuDessusMoyenne / $notes->count()) * 100, 2);
        }

        $result = [
            'professeur' => [
                'id' => $professeur->id,
                'nom_complet' => $professeur->full_name,
                'matiere' => $professeur->matiere->nom ?? 'Non assignée',
            ],
            'assiduite' => [
                'taux' => $tauxAssiduite,
                'heures_prevues' => $totalHeuresPrevu,
                'heures_assurees' => $heuresAssurees
            ],
            'programme' => [
                'cahiers_remplis' => $totalCahiersRemplis,
                'heures_enseignees' => $heuresEffectueesReelles,
                'taux_progression' => $tauxProgression > 100 ? 100 : $tauxProgression // Plafonner à 100%
            ],
            'impact_pedagogique' => [
                'moyenne_globale' => $moyenneGlobale,
                'taux_reussite' => $tauxReussite,
                'total_evaluations' => $notes->count()
            ]
        ];

        return response()->json($result);
    }

    public function getPerformanceAuditIa($id)
    {
        // Réutiliser la logique de statistiques
        $statsResponse = $this->getPerformanceStats(new Request(), $id);
        $data = json_decode($statsResponse->getContent(), true);

        $profNom = $data['professeur']['nom_complet'];
        
        $statsArray = [
            'assiduite' => $data['assiduite'],
            'programme' => $data['programme'],
            'impact_pedagogique' => $data['impact_pedagogique']
        ];

        $aiService = app(\App\Services\AiService::class);
        $auditIa = $aiService->evaluateTeacherPerformance($profNom, $statsArray);

        return response()->json([
            'success' => true,
            'audit_ia' => $auditIa
        ]);
    }

    /**
     * Obtenir les performances de tous les professeurs pour le Directeur
     */
    public function getGlobalPerformance(Request $request)
    {
        $anneeActive = $request->query('annee_scolaire', \App\Models\Setting::getCurrentAnneeScolaire());
        $professeurs = Professeur::where('is_active', true)->with('matiere')->get();

        $stats = $professeurs->map(function($prof) use ($anneeActive) {
            // Assiduité
            $totalHeuresPrevu = Presence::where('professeur_id', $prof->id)->where('annee_scolaire', $anneeActive)->count();
            $heuresAssurees = Presence::where('professeur_id', $prof->id)->where('annee_scolaire', $anneeActive)->where('present', true)->count();
            $tauxAssiduite = $totalHeuresPrevu > 0 ? round(($heuresAssurees / $totalHeuresPrevu) * 100, 1) : 100;

            // Programme
            $heuresEffectueesReelles = CahierTexte::where('professeur_id', $prof->id)->where('annee_scolaire', $anneeActive)->sum('duree_cours');
            $objectifMoyenHeure = 40; 
            $tauxProgression = round(($heuresEffectueesReelles / $objectifMoyenHeure) * 100, 1);

            // Impact (Notes)
            $notes = Note::where('professeur_id', $prof->id)->where('annee_scolaire', $anneeActive)->get();
            $tauxReussite = 0;
            if ($notes->count() > 0) {
                $notesAuDessusMoyenne = $notes->filter(function($note) {
                    $noteSur = $note->note_sur ?? 20;
                    return $noteSur > 0 && ($note->valeur / $noteSur) >= 0.5;
                })->count();
                $tauxReussite = round(($notesAuDessusMoyenne / $notes->count()) * 100, 1);
            }

            return [
                'id' => $prof->id,
                'nom_complet' => $prof->full_name,
                'matiere' => $prof->matiere->nom ?? 'N/A',
                'assiduite' => $tauxAssiduite,
                'programme' => $tauxProgression > 100 ? 100 : $tauxProgression,
                'impact' => $tauxReussite
            ];
        });

        return response()->json([
            'success' => true,
            'stats' => $stats
        ]);
    }
}
