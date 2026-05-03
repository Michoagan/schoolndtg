<?php

namespace App\Http\Controllers;

use App\Models\Eleve;
use App\Models\Classe;
use App\Models\Bulletin;
use App\Models\Evenement;
use App\Models\Note;
use App\Models\Matiere;
use Illuminate\Http\Request;

class SecretaireController extends Controller
{
    /**
     * Tableau de bord du secrétariat
     */
    public function dashboard()
    {
        $totalEleves = Eleve::count();
        $totalClasses = Classe::count();
        $bulletinsGeneres = Bulletin::where('statut', 'généré')->count();
        $notesSaisies = Note::count();
        
        return response()->json([
            'success' => true,
            'stats' => [
                'total_eleves' => $totalEleves,
                'total_classes' => $totalClasses,
                'bulletins_generes' => $bulletinsGeneres,
                'notes_saisies' => $notesSaisies
            ]
        ]);
    }

    /**
     * Gestion des élèves
     */
    public function eleves()
    {
        $eleves = Eleve::with('classe')->orderBy('nom')->get();
        $classes = Classe::all();
        
        return response()->json([
            'success' => true,
            'eleves' => $eleves,
            'classes' => $classes
        ]);
    }

    /**
     * Gestion des bulletins
     */
    public function bulletins()
    {
        $bulletins = Bulletin::with(['eleve', 'classe'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'bulletins' => $bulletins
        ]);
    }

    /**
     * Gestion des notes
     */
    public function notes()
    {
        $notes = Note::with(['eleve', 'matiere'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        $eleves = Eleve::all();
        $matieres = Matiere::all();
        
        return response()->json([
            'success' => true,
            'notes' => $notes,
            'eleves' => $eleves,
            'matieres' => $matieres
        ]);
    }

    /**
     * Affichage des résultats
     */
    public function resultats()
    {
        $classes = Classe::with(['eleves', 'matieres'])->get();
        
        return response()->json([
            'success' => true,
            'classes' => $classes
        ]);
    }

    /**
     * Gestion des événements (Secrétariat)
     */
    public function evenements()
    {
        $evenements = Evenement::with('classes')->orderBy('date_debut', 'desc')->get();
        return response()->json($evenements);
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
        ]);

        if (!$evenement->pour_tous && $request->has('classes')) {
            $evenement->classes()->sync($request->classes);
        }

        // Notification d'événement
        $notification = new \App\Notifications\NouvelEvenementNotification($evenement);

        if ($evenement->pour_tous) {
            $parents = \App\Models\Tuteur::all();
            $professeurs = \App\Models\Professeur::all();
            
            \Illuminate\Support\Facades\Notification::send($parents, $notification);
            \Illuminate\Support\Facades\Notification::send($professeurs, $notification);
        } else {
            // Uniquement pour les classes sélectionnées
            if ($request->has('classes')) {
                $eleves = \App\Models\Eleve::whereIn('classe_id', $request->classes)->get();
                $parentsIds = $eleves->pluck('tuteur_id')->filter()->unique();
                $parents = \App\Models\Tuteur::whereIn('id', $parentsIds)->get();
                \Illuminate\Support\Facades\Notification::send($parents, $notification);

                $professeurs = \App\Models\Professeur::whereHas('classes', function($q) use ($request) {
                    $q->whereIn('classes.id', $request->classes);
                })->get();
                \Illuminate\Support\Facades\Notification::send($professeurs, $notification);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Événement créé avec succès',
            'evenement' => $evenement->load('classes')
        ]);
    }

    public function destroyEvenement($id)
    {
        try {
            $evenement = Evenement::findOrFail($id);
            $evenement->delete();
            return response()->json(['success' => true, 'message' => 'Événement supprimé.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()], 500);
        }
    }
}
