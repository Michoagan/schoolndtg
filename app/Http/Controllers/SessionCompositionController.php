<?php

namespace App\Http\Controllers;

use App\Models\Classe;
use App\Models\HoraireComposition;
use App\Models\SessionComposition;
use App\Services\WhatsAppService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SessionCompositionController extends Controller
{
    /**
     * Display a listing of sessions (with their schedules).
     */
    public function index()
    {
        try {
            $sessions = SessionComposition::with(['classe:id,nom', 'horaires.matiere:id,nom'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $sessions,
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de chargement: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store a newly created session and its schedule.
     * Envoie également une notification WhatsApp aux parents concernés.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'libelle'                      => 'required|string|max:255',
            'trimestre'                    => 'required|integer|in:1,2,3',
            'numero_devoir'                => 'required|integer|in:1,2',
            'cible'                        => 'required|string|in:toute_lecole,1er_cycle,2nd_cycle,classe',
            'classe_id'                    => 'required_if:cible,classe|nullable|exists:classes,id',
            'horaires'                     => 'required|array|min:1',
            'horaires.*.matiere_id'        => 'required|exists:matieres,id',
            'horaires.*.date_composition'  => 'required|date',
            'horaires.*.heure_debut'       => 'required|date_format:H:i',
            'horaires.*.heure_fin'         => 'required|date_format:H:i|after:horaires.*.heure_debut',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            $session = SessionComposition::create([
                'libelle'       => $request->libelle,
                'trimestre'     => $request->trimestre,
                'numero_devoir' => $request->numero_devoir,
                'cible'         => $request->cible,
                'classe_id'     => ($request->cible === 'classe') ? $request->classe_id : null,
            ]);

            foreach ($request->horaires as $horaire) {
                HoraireComposition::create([
                    'session_id'       => $session->id,
                    'matiere_id'       => $horaire['matiere_id'],
                    'date_composition' => $horaire['date_composition'],
                    'heure_debut'      => $horaire['heure_debut'],
                    'heure_fin'        => $horaire['heure_fin'],
                ]);
            }

            DB::commit();

            // ── Notifications WhatsApp aux parents ────────────────────────
            // Même URL que le bot configuré dans WHATSAPP_BOT_URL (.env)
            try {
                $sessionLoaded = $session->load(['classe', 'horaires.matiere']);
                $classeNom     = $sessionLoaded->classe?->nom ?? 'toutes les classes';

                foreach ($sessionLoaded->horaires as $horaire) {
                    $matiereNom = $horaire->matiere?->nom ?? 'Matière';
                    // date_composition est casté en Carbon par le modèle
                    $dateCarbon = ($horaire->date_composition instanceof Carbon)
                        ? $horaire->date_composition
                        : Carbon::parse((string) $horaire->date_composition);
                    $dateCompo  = $dateCarbon->format('d/m/Y');

                    $msg = WhatsAppService::msgComposition(
                        $classeNom,
                        $matiereNom,
                        $dateCompo,
                        $request->libelle
                    );

                    if ($request->cible === 'classe' && $request->classe_id) {
                        // Une seule classe ciblée
                        WhatsAppService::sendToParentsOfClasse($request->classe_id, $msg);
                    } else {
                        // Toute l'école ou un cycle — parents de toutes les classes
                        $classes = Classe::query()->pluck('id');
                        foreach ($classes as $classeId) {
                            WhatsAppService::sendToParentsOfClasse($classeId, $msg);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[WhatsApp] Composition notif : ' . $e->getMessage());
            }
            // ─────────────────────────────────────────────────────────────

            return response()->json([
                'success' => true,
                'message' => 'Session de composition programmée avec succès.',
                'data'    => $session->load('horaires'),
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Erreur de programmation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified session.
     */
    public function destroy($id)
    {
        try {
            $session = SessionComposition::findOrFail($id);
            $session->delete(); // Horaires will cascade delete

            return response()->json([
                'success' => true,
                'message' => 'Session supprimée.',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de suppression: ' . $e->getMessage(),
            ], 500);
        }
    }
}
