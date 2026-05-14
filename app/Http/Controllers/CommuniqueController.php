<?php

namespace App\Http\Controllers;

use App\Models\Communique;
use App\Models\Professeur;
use App\Models\Tuteur;
use App\Notifications\NouveauCommuniqueNotification;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;

class CommuniqueController extends Controller
{
    public function index()
    {
        $communiques = Communique::orderBy('created_at', 'desc')->get();
        return response()->json([
            'success'      => true,
            'communiques'  => $communiques,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'titre'   => 'required|string|max:255',
            'contenu' => 'required|string',
            'type'    => 'required|string|in:general,professeurs,eleves,parents',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $communique = Communique::create([
            'titre'        => $request->titre,
            'contenu'      => $request->contenu,
            'type'         => $request->type,
            'is_published' => true,
            'published_at' => now(),
        ]);

        // Préparer notification Firebase et message WhatsApp
        $notification = new NouveauCommuniqueNotification($communique);
        $waMsg        = WhatsAppService::msgCommunique($communique->titre, $communique->contenu);

        // ── Parents ──────────────────────────────────────────────────────
        if (in_array($request->type, ['parents', 'general'])) {
            $parents = Tuteur::all();
            Notification::send($parents, $notification);

            foreach ($parents as $parent) {
                $phone = $parent->telephone ?? '';
                if (!empty($phone)) {
                    WhatsAppService::send($phone, $waMsg);
                }
            }
        }

        // ── Professeurs ──────────────────────────────────────────────────
        if (in_array($request->type, ['professeurs', 'general'])) {
            $professeurs = Professeur::where('is_active', true)->get();
            Notification::send($professeurs, $notification);

            foreach ($professeurs as $prof) {
                $phone = $prof->phone ?? '';
                if (!empty($phone)) {
                    WhatsAppService::send($phone, $waMsg);
                }
            }
        }

        return response()->json([
            'success'    => true,
            'communique' => $communique,
            'message'    => 'Communiqué publié avec succès',
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $communique = Communique::find($id);
        if (!$communique) {
            return response()->json(['success' => false, 'message' => 'Communiqué non trouvé'], 404);
        }

        $validator = Validator::make($request->all(), [
            'titre'   => 'required|string|max:255',
            'contenu' => 'required|string',
            'type'    => 'required|string|in:general,professeurs,eleves,parents',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $communique->update([
            'titre'   => $request->titre,
            'contenu' => $request->contenu,
            'type'    => $request->type,
        ]);

        return response()->json([
            'success'    => true,
            'communique' => $communique,
            'message'    => 'Communiqué mis à jour avec succès',
        ]);
    }

    public function destroy($id)
    {
        $communique = Communique::find($id);
        if (!$communique) {
            return response()->json(['success' => false, 'message' => 'Communiqué non trouvé'], 404);
        }

        $communique->delete();
        return response()->json(['success' => true, 'message' => 'Communiqué supprimé']);
    }
}
