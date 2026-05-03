<?php

namespace App\Http\Controllers;

use App\Models\Classe;
use App\Models\Professeur;
use App\Models\Matiere;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClasseController extends Controller
{
    /**
     * Affiche la liste des classes
     */
    public function index()
    {
        try {
           $classes = Classe::with(['professeurPrincipal', 'matieres.professeur', 'eleves'])
    ->withCount('eleves')
    ->orderByNiveau()  // Maintenant cette méthode existe
    ->orderBy('nom')
    ->get();

            return response()->json([
                'success' => true,
                'classes' => $classes
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des classes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des classes.'
            ], 500);
        }
    }

    /**
     * Affiche le formulaire de création d'une classe
     */
    public function create()
    {
        try {
            // Récupérer tous les professeurs actifs
            $professeurs = Professeur::where('is_active', true)
                ->orderBy('last_name')
                ->get();
            
            // Récupérer toutes les matières actives
            $matieres = Matiere::where('is_active', true)
                ->orderBy('nom')
                ->get();
            
            return response()->json([
                'success' => true,
                'professeurs' => $professeurs,
                'matieres' => $matieres
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur lors du chargement des données de création: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des données.'
            ], 500);
        }
    }

    /**
     * Enregistre une nouvelle classe dans la base de données
     */
   public function store(Request $request)
{
    // Validation des données
    $validated = $request->validate([
        'nom' => 'required|string|max:255|unique:classes,nom',
        'niveau' => 'required|string|max:50',
        'professeur_principal_id' => 'required|exists:professeurs,id',
        'cout_contribution' => 'required|numeric|min:0',
        'capacite_max' => 'nullable|integer|min:10|max:60',
        'is_active' => 'boolean',
        'matieres' => 'required|array|min:1',
        'matieres.*.nom' => 'required|string|max:255',
        'matieres.*.coefficient' => 'required|integer|min:1|max:10',
        'matieres.*.volume_horaire' => 'required|integer|min:1',
        'matieres.*.professeur_id' => 'nullable|exists:professeurs,id'
    ]);

    DB::beginTransaction();

    try {
        // Création de la classe
        $classe = Classe::create([
            'nom' => $validated['nom'],
            'niveau' => $validated['niveau'],
            'professeur_principal_id' => $validated['professeur_principal_id'],
            'cout_contribution' => $validated['cout_contribution'],
            'capacite_max' => $validated['capacite_max'] ?? 40,
            'is_active' => $request->has('is_active'),
        ]);

        // Traitement des matières avec leurs professeurs et coefficients
        $ordreAffichage = 1;
        $matieresData = [];
        $professeursIds = [];

        foreach ($validated['matieres'] as $matiereData) {
            // Rechercher la matière par son nom
            $matiere = Matiere::where('nom', $matiereData['nom'])->first();
            
            if ($matiere) {
                $matieresData[$matiere->id] = [
                    'coefficient' => $matiereData['coefficient'],
                    'volume_horaire' => $matiereData['volume_horaire'] ?? 0,
                    'professeur_id' => $matiereData['professeur_id'] ?? null,
                    'ordre_affichage' => $ordreAffichage++
                ];

                // Ajouter le professeur à la liste s'il est spécifié
                if (!empty($matiereData['professeur_id'])) {
                    $professeursIds[] = $matiereData['professeur_id'];
                }
            }
        }

        // Attacher les matières à la classe avec les données supplémentaires
        $classe->matieres()->attach($matieresData);

        // Ajouter le professeur principal à la liste des professeurs
        $professeursIds[] = $validated['professeur_principal_id'];

        // Éliminer les doublons
        $professeursIds = array_unique($professeursIds);

        // Attacher tous les professeurs à la classe dans la table pivot classe_professeur
        $classe->professeurs()->attach($professeursIds);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'La classe "' . $classe->nom . '" a été créée avec succès.',
            'classe' => $classe
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur lors de la création de la classe: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Une erreur est survenue lors de la création de la classe: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Affiche les détails d'une classe
     */
    public function show(Classe $classe)
    {
        try {
            $classe->load(['professeurPrincipal', 'matieres' => function($query) {
                $query->orderBy('ordre_affichage');
            }]);
            
            return response()->json([
                'success' => true,
                'classe' => $classe
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'affichage de la classe: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des détails de la classe.'
            ], 500);
        }
    }

    /**
     * Affiche le formulaire de modification d'une classe
     */
    public function edit(Classe $classe) // Injection de modèle
{
    try {
        // Récupérer tous les professeurs actifs
        $professeurs = Professeur::where('is_active', true)
            ->orderBy('last_name')
            ->get();
        
        // Récupérer toutes les matières actives
        $allMatieres = Matiere::where('is_active', true)
            ->orderBy('nom')
            ->get();
        
        // Charger les matières avec les données de la table pivot
        $classe->load('matieres');
        
        return response()->json([
            'success' => true,
            'classe' => $classe,
            'professeurs' => $professeurs,
            'allMatieres' => $allMatieres
        ]);
        
    } catch (\Exception $e) {
        Log::error('Erreur lors du chargement des données de modification: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Erreur lors du chargement des données de modification.'
        ], 500);
    }
}

    /**
     * Met à jour une classe dans la base de données
     */
    public function update(Request $request, $id)
{
    // Récupérer la classe par son ID
    $classe = Classe::findOrFail($id);
    
    // Validation des données
    $validated = $request->validate([
        'nom' => 'required|string|max:255|unique:classes,nom,' . $classe->id,
        'niveau' => 'required|string|max:50',
        'professeur_principal_id' => 'required|exists:professeurs,id',
        'cout_contribution' => 'required|numeric|min:0',
        'capacite_max' => 'nullable|integer|min:10|max:60',
        'is_active' => 'boolean',
        'matieres' => 'required|array|min:1',
        'matieres.*.nom' => 'required|string|max:255',
        'matieres.*.coefficient' => 'required|integer|min:1|max:10',
        'matieres.*.volume_horaire' => 'required|integer|min:1',
        'matieres.*.professeur_id' => 'nullable|exists:professeurs,id'
    ]);

    DB::beginTransaction();

    try {
        // Mise à jour des informations de base de la classe
        $classe->update([
            'nom' => $validated['nom'],
            'niveau' => $validated['niveau'],
            'professeur_principal_id' => $validated['professeur_principal_id'],
            'cout_contribution' => $validated['cout_contribution'],
            'capacite_max' => $validated['capacite_max'] ?? 40,
            'is_active' => $request->has('is_active'),
        ]);

        // Traitement des matières avec leurs professeurs et coefficients
        $ordreAffichage = 1;
        $matieresData = [];
        $professeursIds = [];

        foreach ($validated['matieres'] as $matiereData) {
            // Rechercher la matière par son nom
            $matiere = Matiere::where('nom', $matiereData['nom'])->first();
            
            if ($matiere) {
                $matieresData[$matiere->id] = [
                    'coefficient' => $matiereData['coefficient'],
                    'volume_horaire' => $matiereData['volume_horaire'] ?? 0, // New field
                    'professeur_id' => $matiereData['professeur_id'] ?? null,
                    'ordre_affichage' => $ordreAffichage++
                ];

                // Ajouter le professeur à la liste s'il est spécifié
                if (!empty($matiereData['professeur_id'])) {
                    $professeursIds[] = $matiereData['professeur_id'];
                }
            }
        }

        // Synchroniser les matières avec la classe
        $classe->matieres()->sync($matieresData);

        // Ajouter le professeur principal à la liste des professeurs
        $professeursIds[] = $validated['professeur_principal_id'];

        // Éliminer les doublons
        $professeursIds = array_unique($professeursIds);

        // Synchroniser tous les professeurs avec la classe dans la table pivot classe_professeur
        $classe->professeurs()->sync($professeursIds);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => 'La classe "' . $classe->nom . '" a été modifiée avec succès.',
            'classe' => $classe
        ]);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Erreur lors de la modification de la classe: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Une erreur est survenue lors de la modification de la classe: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Supprime une classe
     */
    public function destroy(Classe $classe)
    {
        DB::beginTransaction();

        try {
            $nomClasse = $classe->nom;
            
            // Détacher d'abord toutes les matières (pour respecter les contraintes de clé étrangère)
            $classe->matieres()->detach();
            
            // Puis supprimer la classe
            $classe->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'La classe "' . $nomClasse . '" a été supprimée avec succès.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erreur lors de la suppression de la classe: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la suppression de la classe.'
            ], 500);
        }
    }
}
