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
        $this->apiKey = env('GEMINI_API_KEY');
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';
    }

    /**
     * Analyse les notes (et absences) d'un élève pour fournir un conseil pédagogique aux parents.
     */
    public function analyzeStudentGrades(float $moyenneGenerale, array $performancesMatieres, int $absences = 0): string
    {
        if (empty($this->apiKey)) {
            Log::warning("GEMINI_API_KEY non configurée. Impossible d'analyser le bulletin.");
            return "Conseil non disponible (Clé IA manquante).";
        }

        $matieresString = "L'élève a une moyenne générale de {$moyenneGenerale}/20 avec {$absences} absences signalées. Voici le détail :\n";
        foreach ($performancesMatieres as $perf) {
            $matieresString .= "- {$perf['matiere']}: Moyenne: " . ($perf['moyenne_trimestrielle'] ?? 'N/A') . "/20";
            if (isset($perf['moyenne_interros'])) $matieresString .= " (Interros: {$perf['moyenne_interros']})";
            if (isset($perf['moyenne_devoirs'])) $matieresString .= " (Devoirs: {$perf['moyenne_devoirs']})";
            $matieresString .= "\n";
        }

        $prompt = "En tant que conseiller académique empathique dans un collège/lycée (Notre Dame Pro), rédige un bilan pédagogique court (3 à 4 phrases maximum, pas de tirets ni de listes). Analyse les notes suivantes pour en tirer les faiblesses principales, les points forts, et donne une recommandation claire (ex: suggérer s'il faut un répétiteur dans une matière spécifique si les résultats y sont alarmants, ou alerter sur les absences). Parle au parent de l'élève.\n\nDonnées de l'élève :\n" . $matieresString;

        return $this->callGemini($prompt);
    }

    /**
     * Analyse courte à intégrer directement dans un événement Push.
     */
    public function generatePushAlertForNewGrade(string $matiere, float $nouvelleNote, float $moyenneAncienne = null, string $role = 'parent'): string
    {
        if (empty($this->apiKey)) return "Une nouvelle note a été enregistrée en {$matiere}.";

        $prompt = "Un élève vient d'avoir une nouvelle note de {$nouvelleNote}/20 en {$matiere}. ";
        if ($moyenneAncienne !== null) {
            $prompt .= "Sa moyenne précédente dans cette matière était de {$moyenneAncienne}/20. ";
        }
        
        if ($role === 'professeur') {
            $prompt .= "Texte destiné au Professeur. Rédige une analyse stricte de 15 mots pour alerter sur le suivi de cet élève.";
        } else {
            $prompt .= "Rédige en une seule phrase courte (max 15 mots) destinée à la notification du Parent. Fais un commentaire bienveillant si la note est bonne, ou une alerte encourageante/suggestion d'efforts.";
        }

        $aiComment = $this->callGemini($prompt);
        return "Nouvelle note en {$matiere} : {$nouvelleNote}/20. " . $aiComment;
    }

    /**
     * [NOUVEAU - Phase 2] Analyse l'évolution d'une classe entière pour le Professeur
     */
    public function analyzeClassGrades(array $moyennesTrimestrielles, string $matiereNom, string $classeNom): string
    {
        if (empty($this->apiKey)) return "Analyse globale IA indisponible (Vérifiez la clé).";

        $dataString = "Matière enseignés : {$matiereNom}\nClasse : {$classeNom}\nMoyennes globales trimestrielles de la classe : " . implode(', ', $moyennesTrimestrielles);
        
        $prompt = "En tant que conseiller pédagogique expert, analyse le comportement de cette classe à partir des moyennes. {$dataString}. Rédige 2 phrases directes très professionnelles (sans bonjour ni fioritures) adressées au professeur pour le conseiller ou l'alerter sur la dynamique de sa classe (progression, régression ou avertissement).";

        return $this->callGemini($prompt);
    }

    /**
     * [NOUVEAU - Phase 2] Assistant Censeur : Rédige une appréciation disciplinaire
     */
    public function generateDisciplineAppreciation(string $eleveNom, array $motifs): string
    {
        if (empty($this->apiKey)) return "Appréciation IA indisponible. Veuillez saisir manuellement.";

        $motifsList = implode(', ', $motifs);
        $prompt = "En tant que Censeur adjoint IA (assistant de discipline), rédige une très courte appréciation disciplinaire (1 à 2 phrases strictes mais professionnelles) pour le bulletin de l'élève {$eleveNom}. Les problèmes ou remarques signalés par le professeur sont : {$motifsList}. Ne mets pas de salutations ni de guillemets. Sois direct, formel et académique.";

        return $this->callGemini($prompt);
    }

    /**
     * [NOUVEAU - Phase 3] Direction : Audit Qualité IA d'un professeur
     */
    public function evaluateTeacherPerformance(string $profNom, array $stats): string
    {
        if (empty($this->apiKey)) return "Aperçu IA désactivé. Veuillez configurer GEMINI_API_KEY.";

        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);

        $prompt = "Tu es un Consultant Exécutif en Stratégie RH Scolaire ET un Coach Pédagogique. Ton rôle est d'analyser les statistiques d'un enseignant ({$profNom}) pour le Directeur et le Censeur.
Voici les données : {$statsJson}.
Rédige ton audit en DEUX courts paragraphes :
1. Stratégie RH (Ton formel et directif) : Identifie les forces (ex: taux de réussite) et les points critiques nécessitant une vigilance (ex: assiduité faible, retards cahiers).
2. Coaching & Amélioration (Ton bienveillant et orienté solution) : Propose 1 ou 2 axes d'amélioration précis et constructifs pour aider le professeur à progresser.
N'utilise pas de salutation, pas de guillemets, vas droit au but.";

        return $this->callGemini($prompt);
    }

    /**
     * Méthode générique d'appel à l'API Gemini
     */
    private function callGemini(string $prompt): string
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}?key={$this->apiKey}", [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                    $text = $data['candidates'][0]['content']['parts'][0]['text'];
                    return trim(str_replace(['**', '*'], '', $text));
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
