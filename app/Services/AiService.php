<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected $apiKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey  = env('GEMINI_API_KEY');
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. ANALYSE ÉLÈVE (fiche de bilan individuel pour le professeur)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Analyse enrichie d'un élève : progression par trimestre,
     * écart vs classe, taux de réussite, tendance.
     *
     * @param array $stats  Résultat de buildStudentStats()
     */
    public function analyzeStudentGrades(float $moyenneGenerale, array $performancesMatieres, int $absences = 0): string
    {
        if (empty($this->apiKey)) {
            Log::warning("GEMINI_API_KEY non configurée.");
            return "Conseil non disponible (Clé IA manquante).";
        }

        // Compatibilité avec l'ancien appel simple
        $matieresString = "Moyenne générale : {$moyenneGenerale}/20 | Absences : {$absences}\n";
        foreach ($performancesMatieres as $perf) {
            $matieresString .= "- {$perf['matiere']}: " . ($perf['moyenne_trimestrielle'] ?? 'N/A') . "/20";
            if (isset($perf['moyenne_interros']))  $matieresString .= " (Interros: {$perf['moyenne_interros']})";
            if (isset($perf['moyenne_devoirs']))   $matieresString .= " (Devoirs: {$perf['moyenne_devoirs']})";
            $matieresString .= "\n";
        }

        $prompt = <<<PROMPT
Tu es un conseiller pédagogique (Notre Dame Pro). Analyse les données de cet élève.
Rédige exactement 3 phrases complètes (sans tirets), destinées au professeur :
1. Forces et faiblesses. 2. Tendance (hausse/baisse). 3. Une recommandation concrète.

Données :
$matieresString
PROMPT;

        return $this->callGemini($prompt);
    }

    /**
     * Analyse enrichie avec contexte complet : par trimestre, rang, écart-type.
     * À appeler depuis getAnalyseNotesEleve() avec le tableau $stats enrichi.
     */
    public function analyzeStudentFull(array $stats): string
    {
        if (empty($this->apiKey)) return "Conseil non disponible (Clé IA manquante).";

        $nom       = $stats['nom_eleve']      ?? 'l\'élève';
        $classe    = $stats['classe']         ?? '';
        $matiere   = $stats['matiere']        ?? '';
        $moy       = $stats['moyenne_generale'] ?? 0;
        $rang      = $stats['rang']           ?? 'N/A';
        $effectif  = $stats['effectif_classe'] ?? '?';
        $absences  = $stats['absences']       ?? 0;
        $tauxReuss = $stats['taux_reussite']  ?? 0;
        $ecartMoy  = $stats['ecart_vs_classe'] ?? 0;
        $signe     = $ecartMoy >= 0 ? '+' : '';

        // Progression par trimestre
        $progressionTxt = '';
        foreach ($stats['par_trimestre'] ?? [] as $t) {
            $progressionTxt .= "  T{$t['trimestre']}: Moy={$t['moyenne_trimestrielle']}/20";
            if (isset($t['moyenne_classe'])) $progressionTxt .= " (Classe: {$t['moyenne_classe']}/20)";
            if (isset($t['rang_trimestre'])) $progressionTxt .= " [Rang {$t['rang_trimestre']}/{$effectif}]";
            $progressionTxt .= "\n";
        }

        // Notes individuelles
        $notesTxt = '';
        foreach ($stats['notes_detail'] ?? [] as $label => $val) {
            $notesTxt .= "  $label: $val/20\n";
        }

        $prompt = <<<PROMPT
Conseiller pédagogique (Notre Dame Pro, {$classe}). Bilan de {$nom} en {$matiere} pour le professeur.
Rédige 3 phrases complètes, sans tirets. Couvre : tendance (interros vs devoirs), rang ({$rang}/{$effectif}), écart classe ({$signe}{$ecartMoy}pts), et 1 recommandation précise.

Données clés :
- Moyenne : {$moy}/20 | Taux réussite : {$tauxReuss}% | Absences : {$absences}
Progression : {$progressionTxt}Notes : {$notesTxt}
PROMPT;

        return $this->callGemini($prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. ANALYSE CLASSE (vision globale pour le professeur)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Analyse enrichie d'une classe entière avec distribution et tendance.
     */
    public function analyzeClassGrades(array $moyennesTrimestrielles, string $matiereNom, string $classeNom): string
    {
        if (empty($this->apiKey)) return "Analyse globale IA indisponible.";

        $dataString = implode(', ', array_map(
            fn($i, $v) => "T" . ($i + 1) . ": {$v}/20",
            array_keys($moyennesTrimestrielles),
            $moyennesTrimestrielles
        ));

        $prompt = "En tant que conseiller pédagogique expert, analyse le comportement de la classe **{$classeNom}** en **{$matiereNom}**.\nMoyennes trimestrielles : {$dataString}.\nRédige 2 phrases directes (sans salutation, sans guillemets) adressées au professeur : identifie la tendance (progression/régression/stagnation) et propose 1 action pédagogique concrète.";

        return $this->callGemini($prompt);
    }

    /**
     * Analyse enrichie classe avec distribution complète des résultats.
     */
    public function analyzeClassFull(array $stats): string
    {
        if (empty($this->apiKey)) return "Analyse globale IA indisponible.";

        $classe   = $stats['classe']   ?? '';
        $matiere  = $stats['matiere']  ?? '';
        $effectif = $stats['effectif'] ?? '?';

        // Progression
        $progressionTxt = '';
        foreach ($stats['par_trimestre'] ?? [] as $t) {
            $progressionTxt .= "  T{$t['trimestre']}: Moy={$t['moyenne']}/20";
            $progressionTxt .= " | Taux réussite: {$t['taux_reussite']}%";
            $progressionTxt .= " | Min: {$t['min']}/20 Max: {$t['max']}/20";
            $progressionTxt .= " | Écart-type: {$t['ecart_type']}\n";
        }

        // Distribution des moyennes annuelles
        $distTxt = '';
        foreach ($stats['distribution'] ?? [] as $tranche => $nb) {
            $distTxt .= "  {$tranche}: {$nb} élèves\n";
        }

        $prompt = <<<PROMPT
Conseiller pédagogique (Notre Dame Pro). Classe {$classe} ({$effectif} élèves) en {$matiere}.
Rédige 4 phrases complètes, sans tirets, pour le professeur.
Couvre : évolution trimestrielle, taux d'échec, points critiques, 2 recommandations concrètes.

Résultats : {$progressionTxt}Distribution : {$distTxt}
PROMPT;

        return $this->callGemini($prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. AUTRES FONCTIONS IA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Commentaire court pour notification push lors d'une nouvelle note.
     */
    public function generatePushAlertForNewGrade(string $matiere, float $nouvelleNote, float $moyenneAncienne = null, string $role = 'parent'): string
    {
        if (empty($this->apiKey)) return "Une nouvelle note a été enregistrée en {$matiere}.";

        $prompt = "Un élève vient d'avoir une nouvelle note de {$nouvelleNote}/20 en {$matiere}. ";
        if ($moyenneAncienne !== null) {
            $prompt .= "Sa moyenne précédente était de {$moyenneAncienne}/20. ";
        }

        if ($role === 'professeur') {
            $prompt .= "Texte destiné au Professeur. Rédige une analyse stricte de 15 mots pour alerter sur le suivi de cet élève.";
        } else {
            $prompt .= "Rédige en une seule phrase (max 15 mots) pour la notification du Parent. Bienveillant si bonne note, alerte encourageante sinon.";
        }

        return "Nouvelle note en {$matiere} : {$nouvelleNote}/20. " . $this->callGemini($prompt);
    }

    /**
     * Appréciation disciplinaire (Censeur).
     */
    public function generateDisciplineAppreciation(string $eleveNom, array $motifs): string
    {
        if (empty($this->apiKey)) return "Appréciation IA indisponible. Veuillez saisir manuellement.";

        $motifsList = implode(', ', $motifs);
        $prompt = "En tant que Censeur adjoint IA, rédige une appréciation disciplinaire (1 à 2 phrases strictes) pour le bulletin de l'élève {$eleveNom}. Problèmes signalés : {$motifsList}. Sans salutation ni guillemets. Direct, formel, académique.";

        return $this->callGemini($prompt);
    }

    /**
     * Audit qualité professeur (Direction).
     */
    public function evaluateTeacherPerformance(string $profNom, array $stats): string
    {
        if (empty($this->apiKey)) return "Aperçu IA désactivé. Veuillez configurer GEMINI_API_KEY.";

        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);

        $prompt = "Tu es un Consultant Exécutif en Stratégie RH Scolaire ET un Coach Pédagogique. Analyse les statistiques de l'enseignant ({$profNom}) pour le Directeur.\nDonnées : {$statsJson}.\nRédige DEUX courts paragraphes :\n1. Stratégie RH (formel) : forces et points critiques.\n2. Coaching (bienveillant) : 1 à 2 axes d'amélioration précis.\nSans salutation, sans guillemets.";

        return $this->callGemini($prompt);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // APPEL API GEMINI
    // ─────────────────────────────────────────────────────────────────────────

    private function callGemini(string $prompt): string
    {
        try {
            $response = Http::withoutVerifying()
                ->timeout(25)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("{$this->baseUrl}?key={$this->apiKey}", [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature'     => 0.35,
                        'maxOutputTokens' => 700,
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $text = $data['candidates'][0]['content']['parts'][0]['text'];
                    return trim(str_replace(['**', '*', '#'], '', $text));
                }
            }

            Log::error('Erreur API Gemini : ' . $response->body());
            return "L'analyse pédagogique n'a pas pu être générée.";

        } catch (\Exception $e) {
            Log::error('Exception API Gemini : ' . $e->getMessage());
            return "Service d'analyse indisponible pour le moment.";
        }
    }
}
