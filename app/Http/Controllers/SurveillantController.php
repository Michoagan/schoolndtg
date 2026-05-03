<?php

namespace App\Http\Controllers;

use App\Models\Plainte;
use App\Models\Evenement;
use App\Models\Classe;
use App\Models\Eleve;
use App\Models\Professeur;
use App\Models\Direction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SurveillantController extends Controller
{
     public function dashboard(Request $request) // Ajoutez Request $request ici
    {
        $stats = [
            'plaintes_semaine' => Plainte::where('created_at', '>=', now()->subWeek())->count(),
            'eleves_total' => Eleve::count(),
            'professeurs_total' => Professeur::count(),
            'classes_total' => Classe::count()
        ];

        $plaintes = Plainte::with(['eleve', 'classe'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        $classes = Classe::withCount('eleves')->get();

        // Récupérer tous les élèves groupés par classe
        $elevesParClasse = [];
        foreach ($classes as $classe) {
            $elevesParClasse[$classe->id] = Eleve::where('classe_id', $classe->id)
                ->orderBy('nom')
                ->orderBy('prenom')
                ->get();
        }

        // Événements à venir (prochains 5 événements)
        $evenements = Evenement::where('date_debut', '>=', now())
            ->orderBy('date_debut', 'asc')
            ->take(5)
            ->get();

        // Tous les événements
        $tous_evenements = Evenement::orderBy('date_debut', 'desc')->get();

        // Pour les autres sections
        $professeurs = Professeur::with('classes')->get();
        $all_plaintes = Plainte::with(['eleve', 'classe'])->orderBy('date_plainte', 'desc')->get();

        // Gérer la sélection de classe (optionnel - si vous utilisez l'approche avec formulaire)
        $classeSelectionnee = null;
        $elevesClasseSelectionnee = collect();
        
        if ($request->has('classe_id')) {
            $classeId = $request->get('classe_id');
            $classeSelectionnee = $classes->firstWhere('id', $classeId);
            $elevesClasseSelectionnee = $elevesParClasse[$classeId] ?? collect();
        }

        return response()->json([
            'success' => true,
            'stats' => $stats, 
            'plaintes' => $plaintes, 
            'classes' => $classes, 
            'evenements' => $evenements,
            'tous_evenements' => $tous_evenements,
            'professeurs' => $professeurs,
            'all_plaintes' => $all_plaintes,
            'elevesParClasse' => $elevesParClasse,
            'classeSelectionnee' => $classeSelectionnee,
            'elevesClasseSelectionnee' => $elevesClasseSelectionnee
        ]);
    }

    public function stats()
    {
        $plaintes_semaine = Plainte::where('created_at', '>=', now()->subWeek())->count();
        $eleves_total = Eleve::count();
        $professeurs_total = Professeur::count();
        $classes_total = Classe::count();

        return response()->json([
            'plaintes_semaine' => $plaintes_semaine,
            'eleves_total' => $eleves_total,
            'professeurs_total' => $professeurs_total,
            'classes_total' => $classes_total
        ]);
    }

    public function plaintesRecent()
    {
        $plaintes = Plainte::with(['eleve', 'classe'])
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        return response()->json($plaintes);
    }

    public function storePlainte(Request $request)
    {
        $request->validate([
            'eleve_id' => 'required|exists:eleves,id',
            'classe_id' => 'required|exists:classes,id',
            'type_plainte' => 'required|in:retard,absence,bavardage,exercice,distraction,pagaille,autre',
            'date_plainte' => 'required|date',
            'details' => 'required|string',
            'sanction' => 'nullable|string'
        ]);

        $plainte = Plainte::create([
            'eleve_id' => $request->eleve_id,
            'classe_id' => $request->classe_id,
            'surveillant_id' => Auth::guard('direction')->id(),
            'type_plainte' => $request->type_plainte,
            'date_plainte' => $request->date_plainte,
            'details' => $request->details,
            'sanction' => $request->sanction,
            'statut' => 'enregistrée'
        ]);

        // --- ENVOI NOTIFICATION PUSH AUX PARENTS ---
        try {
            $plainte->load(['eleve', 'eleve.tuteurs']);
            $tuteurs = $plainte->eleve->tuteurs;

            if ($tuteurs && $tuteurs->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send($tuteurs, new \App\Notifications\NouvellePlainteNotification($plainte));
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Erreur Push Notification Nouvelle Plainte : ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Plainte enregistrée avec succès',
            'plainte' => $plainte
        ]);
    }

    public function historiquePlaintes(Request $request)
    {
        $query = Plainte::with(['eleve', 'classe']);

        if ($request->has('classe_id') && $request->classe_id) {
            $query->where('classe_id', $request->classe_id);
        }

        if ($request->has('type') && $request->type) {
            $query->where('type_plainte', $request->type);
        }

        if ($request->has('date') && $request->date) {
            $query->where('date_plainte', $request->date);
        }

        $plaintes = $query->orderBy('date_plainte', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($plaintes);
    }

    public function storeEvenement(Request $request)
    {
        $request->validate([
            'titre' => 'required|string|max:255',
            'description' => 'required|string',
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after:date_debut',
            'lieu' => 'nullable|string|max:255',
            'type' => 'required|string',
            'pour_tous' => 'boolean',
            'classes' => 'nullable|array',
            'classes.*' => 'exists:classes,id'
        ]);

        $evenement = Evenement::create([
            'titre' => $request->titre,
            'description' => $request->description,
            'date_debut' => $request->date_debut,
            'date_fin' => $request->date_fin,
            'lieu' => $request->lieu,
            'type' => $request->type,
            'pour_tous' => $request->pour_tous ?? false,
            // 'createur_id' => Auth::guard('direction')->id(), // Champs non présents dans la migration originale, à vérifier si nécessaire ou utiliser Auth
            // 'createur_type' => Direction::class
        ]);

        if (!$evenement->pour_tous && $request->has('classes')) {
            $evenement->classes()->sync($request->classes);
        }

        return response()->json([
            'success' => true,
            'message' => 'Événement créé avec succès',
            'evenement' => $evenement->load('classes')
        ]);
    }

    public function evenements()
    {
        $evenements = Evenement::with('classes')->orderBy('date_debut', 'desc')->get();
        return response()->json($evenements);
    }

    public function evenementsProchains()
    {
        $evenements = Evenement::where('date_debut', '>=', now())
            ->orderBy('date_debut', 'asc')
            ->take(5)
            ->get();

        return response()->json($evenements);
    }

    public function getPresencesEleves(Request $request)
    {
        $date = $request->get('date', now()->format('Y-m-d'));
        $classeId = $request->get('classe_id');

        $query = \App\Models\Presence::with(['eleve', 'classe'])
            ->whereDate('date', $date);

        if ($classeId) {
            $query->where('classe_id', $classeId);
        }

        $presences = $query->get();

        return response()->json($presences);
    }

    public function getPresencesProfesseurs(Request $request)
    {
        $date = $request->get('date', now()->format('Y-m-d'));

        $presences = \App\Models\PresenceProfesseur::with('professeur')
            ->whereDate('date', $date)
            ->get();

        return response()->json($presences);

    }
}
