<?php

namespace App\Http\Controllers;

use App\Models\Classe;
use App\Models\Conduite;
use App\Models\Professeur;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ConduiteController extends Controller
{
    /**
     * Display a listing of the conduites for a specific class and trimester.
     */
    public function index(Request $request, Classe $classe)
    {
        $professeur = Auth::user();

        // Ensure the logged-in user is a Professeur
        if (!$professeur instanceof Professeur) {
            return response()->json(['success' => false, 'message' => 'Non autorisé. Seuls les professeurs ont accès.'], 403);
        }

        // Ensure the professor is the main teacher of the class
        if ($classe->professeur_principal_id !== $professeur->id) {
            return response()->json(['success' => false, 'message' => 'Seul le professeur principal peut gérer les conduites de cette classe.'], 403);
        }

        $trimestre = $request->input('trimestre', 1);

        // Load students and their conduites for the given trimester
        $classe->load(['eleves' => function ($query) use ($trimestre) {
            $query->orderBy('nom')->orderBy('prenom')
                  ->with(['conduites' => function ($q) use ($trimestre) {
                      $q->where('trimestre', $trimestre);
                  }]);
        }]);

        // Transform the students list to map the conduite easily
        $eleves = $classe->eleves->map(function ($eleve) {
            $conduite = $eleve->conduites->first();
            return [
                'id' => $eleve->id,
                'matricule' => $eleve->matricule,
                'nom' => $eleve->nom,
                'prenom' => $eleve->prenom,
                'note' => $conduite ? $conduite->note : null,
                'appreciation' => $conduite ? $conduite->appreciation : '',
            ];
        });

        return response()->json([
            'success' => true,
            'classe' => $classe->nom,
            'trimestre' => $trimestre,
            'data' => $eleves
        ]);
    }

    /**
     * Store or update conduite grades for students in a class.
     */
    public function store(Request $request, Classe $classe)
    {
        $professeur = Auth::user();

        if (!$professeur instanceof Professeur) {
            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        if ($classe->professeur_principal_id !== $professeur->id) {
            return response()->json(['success' => false, 'message' => 'Seul le professeur principal peut gérer les conduites de cette classe.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'trimestre' => 'required|integer|in:1,2,3',
            'conduites' => 'required|array',
            'conduites.*.eleve_id' => 'required|exists:eleves,id',
            'conduites.*.note' => 'nullable|numeric|min:0|max:20',
            'conduites.*.appreciation' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation des données.',
                'errors' => $validator->errors()
            ], 422);
        }

        $trimestre = $request->trimestre;
        $conduitesData = $request->conduites;
        $savedCount = 0;

        foreach ($conduitesData as $data) {
            // Verify student belongs to class
            $belongsToClass = $classe->eleves()->where('id', $data['eleve_id'])->exists();
            if (!$belongsToClass) continue;

            // Only save if there's either a note or an appreciation
            if (!empty($data['note']) || !empty($data['appreciation'])) {
                // Check if a record already exists
                $existingConduite = Conduite::where('eleve_id', $data['eleve_id'])
                    ->where('classe_id', $classe->id)
                    ->where('trimestre', $trimestre)
                    ->first();

                // Only create if it does NOT exist (no modifications allowed)
                if (!$existingConduite) {
                    Conduite::create([
                        'eleve_id' => $data['eleve_id'],
                        'classe_id' => $classe->id,
                        'trimestre' => $trimestre,
                        'professeur_id' => $professeur->id,
                        'note' => $data['note'] ?? null,
                        'appreciation' => $data['appreciation'] ?? null,
                    ]);
                    $savedCount++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => "$savedCount notes de conduite enregistrées avec succès."
        ]);
    }

    /**
     * Génère une appréciation de discipline automatique avec Gemini IA
     */
    public function genererAppreciationIa(Request $request, Classe $classe)
    {
        $professeur = Auth::user();

        if (!$professeur instanceof Professeur) {
            return response()->json(['success' => false, 'message' => 'Non autorisé.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'eleve_id' => 'required|exists:eleves,id',
            'motifs' => 'required|array',
            'motifs.*' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation.',
                'errors' => $validator->errors()
            ], 422);
        }

        $eleve = \App\Models\Eleve::findOrFail($request->eleve_id);
        
        $aiService = app(\App\Services\AiService::class);
        $appreciation = $aiService->generateDisciplineAppreciation(
            "{$eleve->nom} {$eleve->prenom}", 
            $request->motifs
        );

        return response()->json([
            'success' => true,
            'appreciation' => $appreciation
        ]);
    }
}
