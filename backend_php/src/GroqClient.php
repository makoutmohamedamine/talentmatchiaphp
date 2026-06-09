<?php

declare(strict_types=1);

final class GroqClient
{
    private const ALLOWED_DOMAINS = [
        'Informatique & IT',
        'Ressources Humaines',
        'Finance & Comptabilite',
        'Marketing & Communication',
        'Commerce & Vente',
        'Production Industrielle',
        'Logistique',
        'Maintenance',
        'Qualite & Securite',
        'Administration',
    ];

    private static float $cooldownUntil = 0.0;

    public function __construct(private readonly Config $config)
    {
    }

    public function isAvailable(): bool
    {
        $key = $this->apiKey();
        if ($key === '') {
            return false;
        }
        if (str_starts_with($key, 'votre-cle-') || str_ends_with($key, '-ici')) {
            return false;
        }

        return true;
    }

    public function displayName(): string
    {
        return 'Grok';
    }

    /** Extraction OCR via modele vision Groq (PDF scannes ou texte non extractible). */
    public function extractCvTextFromPdfImages(array $base64PngImages): string
    {
        if (!$this->isAvailable() || $base64PngImages === []) {
            return '';
        }

        $content = [[
            'type' => 'text',
            'text' => 'Extrais TOUT le texte lisible de ce CV (toutes les pages fournies). '
                . 'Reponds UNIQUEMENT avec le texte brut du CV, sans commentaire, sans markdown, sans JSON.',
        ]];

        foreach ($base64PngImages as $image) {
            $image = trim($image);
            if ($image === '' || strlen($image) > 3_500_000) {
                continue;
            }
            $content[] = [
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,' . $image],
            ];
        }

        if (count($content) < 2) {
            return '';
        }

        $result = $this->callGroqVision($content, 4000);
        if (!$result['ok']) {
            return '';
        }

        return trim((string) ($result['raw'] ?? ''));
    }

    /** Repli texte brut (DOCX difficile) via Groq. */
    public function extractCvTextFromPlainContent(string $hint, string $rawBytes): string
    {
        if (!$this->isAvailable()) {
            return '';
        }

        $snippet = mb_substr($rawBytes, 0, 12000, 'UTF-8');
        $result = $this->callGroq(
            'Tu extrais le texte lisible d\'un document CV. Reponds UNIQUEMENT avec le texte brut du CV.',
            $hint . "\n\nDonnees brutes (extrait):\n" . $snippet,
            4000,
            false
        );

        if (!($result['ok'] ?? false)) {
            return '';
        }

        return trim((string) ($result['raw'] ?? ''));
    }

    public function analyseCv(string $cvText, string $jobDescription = '', string $jobTitle = ''): array
    {
        $system = 'Tu es un expert senior en ressources humaines et recrutement avec 15 ans d\'experience. '
            . 'Ton role est d\'analyser des CV de facon precise, objective et professionnelle. '
            . 'Tu DOIS extraire uniquement les informations REELLEMENT presentes dans le CV - ne jamais inventer ou supposer. '
            . 'Si une information n\'est pas dans le CV, utilise une chaine vide ou 0. '
            . 'Reponds UNIQUEMENT en JSON valide strict, sans markdown, sans commentaire, sans texte supplementaire.';

        $jobContext = '';
        if ($jobTitle !== '' || $jobDescription !== '') {
            $jobContext = "\nPOSTE CIBLE:\n"
                . '- Titre: ' . ($jobTitle !== '' ? $jobTitle : 'Non specifie') . "\n"
                . '- Description: ' . substr($jobDescription, 0, 2000) . "\n\n"
                . "Instructions scoring: Evalue la compatibilite du candidat avec CE poste specifique.\n"
                . "- score_global = ponderation: competences techniques 40% + experience 25% + formation 20% + langues 10% + soft skills 5%\n"
                . "- Sois realiste: un candidat junior obtient 40-60, intermediaire 60-75, senior qualifie 75-90, expert parfait 90+\n";
        } else {
            $jobContext = "\nPOSTE: Non specifie. Evalue le profil global du candidat objectivement.\n";
        }

        $user = 'Analyse ce CV de facon professionnelle et retourne EXACTEMENT ce JSON (toutes les valeurs extraites du CV reel):' . "\n"
            . "{\n"
            . '  "nom": "nom de famille exact du CV",' . "\n"
            . '  "prenom": "prenom exact du CV",' . "\n"
            . '  "email": "email exact du CV ou vide",' . "\n"
            . '  "telephone": "telephone exact du CV ou vide",' . "\n"
            . '  "adresse": "ville/pays si mentionnes ou vide",' . "\n"
            . '  "niveau_etudes": "Bac+2/Bac+3/Bac+5/Master/Doctorat/Bac ou equivalent",' . "\n"
            . '  "annees_experience": 0,' . "\n"
            . '  "langues": ["langue1 (niveau)", "langue2 (niveau)"],' . "\n"
            . '  "competences_techniques": ["competence1", "competence2"],' . "\n"
            . '  "competences_soft": ["soft skill1", "soft skill2"],' . "\n"
            . '  "formations": ["Diplome - Institution - Annee"],' . "\n"
            . '  "experiences": ["Poste - Entreprise - Duree - Missions principales"],' . "\n"
            . '  "resume_profil": "Resume professionnel objectif en 2-3 phrases base sur le contenu reel du CV",' . "\n"
            . '  "points_forts": ["point fort 1 concret", "point fort 2 concret", "point fort 3 concret"],' . "\n"
            . '  "points_faibles": ["point faible 1 constructif", "point faible 2 constructif"],' . "\n"
            . '  "score_global": 72,' . "\n"
            . '  "recommandation": "A retenir",' . "\n"
            . '  "justification_score": "Explication detaillee et argumentee du score avec reference aux elements du CV"' . "\n"
            . "}\n\n"
            . "Regles strictes:\n"
            . "- score_global: nombre entier entre 0 et 100, CALCULE rigoureusement selon l'experience et les competences reelles\n"
            . "- recommandation: exactement \"A retenir\" si score>=75, \"Interessant\" si score>=50, \"Insuffisant\" si score<50\n"
            . "- competences_techniques: liste les technologies/logiciels/frameworks EXACTEMENT mentionnes dans le CV\n"
            . "- annees_experience: calcule le total reel des annees travaillees\n"
            . "- formations et experiences: extrais les elements REELS du CV, ne pas inventer\n"
            . "- justification_score: doit expliquer le score avec des arguments concrets tires du CV\n"
            . $jobContext . "\n"
            . "CV A ANALYSER:\n"
            . substr($cvText, 0, 12000);

        $result = $this->callGroq($system, $user, 2400);
        if (!$result['ok']) {
            return [
                'ia_disponible' => false,
                'error' => $result['error'],
                'ai_provider' => $result['provider'] ?? $this->displayName(),
                'groq_error' => $result['error'],
            ];
        }

        $data = $result['data'];
        $data['ia_disponible'] = true;
        $data['methode'] = $result['provider'] ?? $this->displayName();
        $data['ai_provider'] = $result['provider'] ?? $this->displayName();

        return $data;
    }

    public function scoreCvContrePoste(string $cvText, string $jobTitle, string $jobDescription): array
    {
        $system = 'Tu es un expert en matching RH avec une approche analytique rigoureuse. '
            . 'Tu evalues objectivement la compatibilite entre un CV et un poste. '
            . 'Tes scores sont bases sur les elements REELS du CV vs les exigences du poste. '
            . 'Reponds UNIQUEMENT en JSON valide strict, sans markdown ni texte supplementaire.';

        $user = 'Evalue la compatibilite entre ce CV et ce poste. Retourne EXACTEMENT ce JSON:' . "\n"
            . "{\n"
            . '  "score": 68,' . "\n"
            . '  "score_competences": 70,' . "\n"
            . '  "score_experience": 65,' . "\n"
            . '  "score_formation": 75,' . "\n"
            . '  "score_langues": 80,' . "\n"
            . '  "score_domaine": 60,' . "\n"
            . '  "niveau": "Bon",' . "\n"
            . '  "competences_matchees": ["competence presente dans CV ET requise par le poste"],' . "\n"
            . '  "competences_manquantes": ["competence requise par le poste ABSENTE du CV"],' . "\n"
            . '  "justification": "Analyse detaillee du matching avec references aux elements concrets du CV et du poste"' . "\n"
            . "}\n\n"
            . "Methode de calcul rigoureuse:\n"
            . "- score_competences (poids 40%): % des competences cles du poste presentes dans le CV\n"
            . "- score_experience (poids 25%): adequation duree et nature experience vs exigences poste\n"
            . "- score_formation (poids 20%): adequation niveau et domaine formation vs exigences\n"
            . "- score_langues (poids 10%): langues requises presentes dans le CV\n"
            . "- score_domaine (poids 5%): coherence secteur/domaine candidat vs poste\n"
            . "- score final = somme ponderee des sous-scores (0-100, entier)\n"
            . "- niveau: \"Excellent\" si score>=85, \"Bon\" si score>=65, \"Moyen\" si score>=45, \"Faible\" si score<45\n"
            . "- competences_matchees: UNIQUEMENT les competences reellement presentes dans les deux\n"
            . "- competences_manquantes: competences cles du poste absentes du CV (max 5)\n"
            . "- justification: 2-3 phrases argumentant le score avec elements concrets\n\n"
            . "POSTE:\nTitre: {$jobTitle}\nDescription: " . substr($jobDescription, 0, 2500) . "\n\n"
            . "CV:\n" . substr($cvText, 0, 9000);

        $result = $this->callGroq($system, $user, 1400);
        if (!$result['ok']) {
            $provider = $result['provider'] ?? $this->displayName();

            return [
                'score' => 0,
                'niveau' => 'N/A',
                'competences_matchees' => [],
                'competences_manquantes' => [],
                'justification' => $provider . ' indisponible',
                'ia_disponible' => false,
                'ai_provider' => $provider,
            ];
        }

        $data = $result['data'];
        $data['ia_disponible'] = true;
        $data['methode'] = $result['provider'] ?? $this->displayName();
        $data['ai_provider'] = $result['provider'] ?? $this->displayName();
        $score = (float) ($data['score'] ?? 0);
        $data['score'] = max(0.0, min(100.0, round($score, 1)));

        return $data;
    }

    public function recommanderRepartition(string $cvText, array $postes, array $domaines): array
    {
        $system = 'Tu es un expert en orientation et matching RH. '
            . 'Tu analyses les profils CV et identifies le meilleur poste et domaine correspondant. '
            . 'Tu bases ton choix sur les competences reelles, l\'experience et la formation du candidat. '
            . 'Reponds UNIQUEMENT en JSON valide strict.';

        $user = 'Analyse ce CV et identifie le poste et domaine les plus adaptes au profil du candidat.' . "\n\n"
            . "Retourne EXACTEMENT ce JSON:\n"
            . "{\n"
            . '  "poste_titre": "titre exact de la liste des postes",' . "\n"
            . '  "domaine": "domaine exact de la liste",' . "\n"
            . '  "confiance": 78,' . "\n"
            . '  "justification": "Explication concrete basee sur les competences et experience du candidat"' . "\n"
            . "}\n\n"
            . "Contraintes absolues:\n"
            . "- poste_titre DOIT etre un titre EXACT de la liste POSTES (copie exacte, meme casse)\n"
            . "- domaine DOIT etre un element EXACT de la liste DOMAINES (copie exacte)\n"
            . "- confiance entre 0 et 100 (reflete le niveau de certitude du matching)\n"
            . "- justification: 1-2 phrases expliquant pourquoi ce poste/domaine correspond au profil\n\n"
            . "POSTES DISPONIBLES:\n"
            . json_encode($postes, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "DOMAINES DISPONIBLES:\n"
            . json_encode($domaines, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "CV DU CANDIDAT:\n"
            . substr($cvText, 0, 10000);

        $result = $this->callGroq($system, $user, 1000);
        if (!$result['ok']) {
            return [
                'ia_disponible' => false,
                'error' => $result['error'],
                'ai_provider' => $result['provider'] ?? $this->displayName(),
            ];
        }

        $data = $result['data'];
        $data['ia_disponible'] = true;
        $data['ai_provider'] = $result['provider'] ?? $this->displayName();
        $data['domaine'] = $this->normalizeDomain((string) ($data['domaine'] ?? ''));

        return $data;
    }

    public function chatResponse(string $question, string $historyBlock, array $candidateRows, array $posteRows, array $domainRows): array
    {
        $system = 'Tu es TalentMatch IA, un assistant RH expert integre a un systeme ATS professionnel. '
            . 'Tu aides les recruteurs a gerer leurs candidats, postes et processus de recrutement. '
            . 'REGLES ABSOLUES: '
            . '1) Reponds TOUJOURS en francais, de facon claire, structuree et professionnelle. '
            . '2) Pour toute question sur candidats/postes/domaines: utilise EXCLUSIVEMENT les donnees JSON du contexte. '
            . '3) Cite les informations exactes (nom, email, telephone, score) telles qu\'elles apparaissent dans le contexte. '
            . '4) Si une information est absente du contexte, dis-le clairement - NE JAMAIS inventer. '
            . '5) Pour les questions generales RH, reponds avec expertise et precision. '
            . '6) Reponds uniquement en JSON valide strict (pas de markdown, pas de texte autour).';

        $user = "Reponds a la question RH. Retourne EXACTEMENT ce JSON:\n"
            . "{\n"
            . '  "answer": "reponse detaillee et professionnelle en francais",' . "\n"
            . '  "highlights": ["point cle 1", "point cle 2"],' . "\n"
            . '  "suggestedActions": ["action concrete 1", "action concrete 2"]' . "\n"
            . "}\n\n"
            . "QUESTION: {$question}\n\n"
            . "HISTORIQUE (coherence conversation):\n{$historyBlock}\n\n"
            . 'BASE DE DONNEES - ' . count($candidateRows) . " CANDIDATS:\n"
            . json_encode($candidateRows, JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'BASE DE DONNEES - ' . count($posteRows) . " POSTES:\n"
            . json_encode($posteRows, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "DOMAINES ACTIFS:\n"
            . json_encode($domainRows, JSON_UNESCAPED_UNICODE) . "\n\n"
            . 'Instructions: Si liste demandee, utilise des tirets. '
            . 'Cite coordonnees exactes du contexte. '
            . 'highlights=informations cles, suggestedActions=actions concretes.';

        return $this->callGroq($system, $user, 2000, true);
    }

    /** @param list<array<string, mixed>> $userContent */
    private function callGroqVision(array $userContent, int $maxTokens = 3000): array
    {
        if (!$this->isAvailable()) {
            return [
                'ok' => false,
                'error' => 'GROQ_API_KEY non configuree',
                'provider' => $this->displayName(),
            ];
        }

        $now = microtime(true);
        if (self::$cooldownUntil > $now) {
            return $this->rateLimitError((int) ceil(self::$cooldownUntil - $now));
        }

        $payload = [
            'model' => $this->visionModel(),
            'messages' => [
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => 0.0,
            'max_tokens' => $maxTokens,
        ];

        return $this->postChatCompletion($payload, true);
    }

    private function callGroq(string $systemPrompt, string $userPrompt, int $maxTokens = 1800, bool $forceJson = false): array
    {
        if (!$this->isAvailable()) {
            return [
                'ok' => false,
                'error' => 'GROQ_API_KEY non configuree',
                'provider' => $this->displayName(),
            ];
        }

        $now = microtime(true);
        if (self::$cooldownUntil > $now) {
            return $this->rateLimitError((int) ceil(self::$cooldownUntil - $now));
        }

        $payload = [
            'model' => $this->model(),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => $maxTokens,
        ];
        if ($forceJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $this->postChatCompletion($payload, false);
    }

    /** @param array<string, mixed> $payload */
    private function postChatCompletion(array $payload, bool $plainTextOnly): array
    {
        $bodyJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($bodyJson)) {
            return ['ok' => false, 'error' => 'Payload Groq invalide', 'provider' => $this->displayName()];
        }

        $http = $this->httpPost($bodyJson);
        if (!$http['ok']) {
            return [
                'ok' => false,
                'error' => $http['error'],
                'provider' => $this->displayName(),
            ];
        }

        $status = $http['status'];
        $headers = $http['headers'];
        $body = $http['body'];

        if ($status === 400) {
            return [
                'ok' => false,
                'error' => 'Requete invalide vers ' . $this->displayName() . ': ' . substr($body, 0, 200),
                'provider' => $this->displayName(),
            ];
        }

        if ($status === 401) {
            return [
                'ok' => false,
                'error' => 'Cle API Groq invalide ou expiree. Rendez-vous sur https://console.groq.com/keys pour obtenir une nouvelle cle, puis mettez a jour GROQ_API_KEY dans backend_php/.env',
                'provider' => $this->displayName(),
                'error_code' => 'invalid_api_key',
            ];
        }

        if ($status === 429) {
            $retryAfter = 30;
            if (preg_match('/retry-after:\s*(\d+)/i', $headers, $m)) {
                $retryAfter = max(5, (int) $m[1]);
            }
            self::$cooldownUntil = max(self::$cooldownUntil, microtime(true) + max(5, $retryAfter));

            return $this->rateLimitError(max(5, $retryAfter));
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'error' => 'Echec service ' . $this->displayName() . ' (HTTP ' . $status . ')',
                'provider' => $this->displayName(),
            ];
        }

        $decoded = json_decode($body, true);
        $raw = (string) ($decoded['choices'][0]['message']['content'] ?? '');
        if ($plainTextOnly) {
            return ['ok' => true, 'raw' => $raw, 'provider' => $this->displayName()];
        }

        try {
            $data = $this->extractJson($raw);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Reponse ' . $this->displayName() . ' non JSON: ' . substr($e->getMessage(), 0, 120),
                'provider' => $this->displayName(),
                'error_code' => 'json_parse_error',
                'raw' => $raw,
            ];
        }

        return ['ok' => true, 'data' => $data, 'raw' => $raw, 'provider' => $this->displayName()];
    }

    /** @return array{ok:bool,status?:int,headers?:string,body?:string,error?:string} */
    private function httpPost(string $bodyJson): array
    {
        if (function_exists('curl_init')) {
            return $this->httpPostCurl($bodyJson);
        }

        return $this->httpPostStream($bodyJson);
    }

    /** @return array{ok:bool,status?:int,headers?:string,body?:string,error?:string} */
    private function httpPostCurl(string $bodyJson): array
    {
        $ch = curl_init($this->apiUrl());
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Impossible d\'initialiser cURL'];
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey(),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $bodyJson,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => 'Echec service ' . $this->displayName() . ': ' . $curlError];
        }

        return [
            'ok' => true,
            'status' => $status,
            'headers' => substr($response, 0, $headerSize),
            'body' => substr($response, $headerSize),
        ];
    }

    /** @return array{ok:bool,status?:int,headers?:string,body?:string,error?:string} */
    private function httpPostStream(string $bodyJson): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->apiKey(),
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($bodyJson),
                ]),
                'content' => $bodyJson,
                'timeout' => 120,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($this->apiUrl(), false, $context);
        if ($response === false) {
            return [
                'ok' => false,
                'error' => 'Extension PHP curl absente et requete HTTP stream echouee. Activez extension=curl dans php.ini.',
            ];
        }

        $status = 0;
        $headers = '';
        if (isset($http_response_header) && is_array($http_response_header)) {
            $headers = implode("\r\n", $http_response_header);
            if (preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
        }

        return [
            'ok' => true,
            'status' => $status,
            'headers' => $headers,
            'body' => $response,
        ];
    }

    private function extractJson(string $raw): array
    {
        $raw = trim($raw);
        if (str_contains($raw, '```')) {
            foreach (explode('```', $raw) as $part) {
                $part = trim($part);
                if (str_starts_with($part, 'json')) {
                    $part = trim(substr($part, 4));
                }
                if ($part !== '' && ($part[0] === '{' || $part[0] === '[')) {
                    $decoded = json_decode($part, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON invalide');
        }

        return $decoded;
    }

    private function normalizeDomain(string $value): string
    {
        $domain = trim($value);
        if (in_array($domain, self::ALLOWED_DOMAINS, true)) {
            return $domain;
        }

        $lower = strtolower($domain);
        $mapping = [
            'it' => 'Informatique & IT',
            'informatique' => 'Informatique & IT',
            'rh' => 'Ressources Humaines',
            'hr' => 'Ressources Humaines',
            'finance' => 'Finance & Comptabilite',
            'comptabilite' => 'Finance & Comptabilite',
            'marketing' => 'Marketing & Communication',
            'communication' => 'Marketing & Communication',
            'commerce' => 'Commerce & Vente',
            'vente' => 'Commerce & Vente',
            'production' => 'Production Industrielle',
            'industrie' => 'Production Industrielle',
            'logistique' => 'Logistique',
            'maintenance' => 'Maintenance',
            'qualite' => 'Qualite & Securite',
            'securite' => 'Qualite & Securite',
            'administration' => 'Administration',
        ];

        foreach ($mapping as $key => $canonical) {
            if (str_contains($lower, $key)) {
                return $canonical;
            }
        }

        return 'Administration';
    }

    private function rateLimitError(int $waitSeconds): array
    {
        $waitSeconds = max(1, $waitSeconds);

        return [
            'ok' => false,
            'error' => $this->displayName() . " limite temporairement les requetes. Reessayez dans ~{$waitSeconds}s.",
            'provider' => $this->displayName(),
            'error_code' => 'rate_limited',
            'retry_after_seconds' => $waitSeconds,
        ];
    }

    private function apiKey(): string
    {
        return trim((string) $this->config->get('GROQ_API_KEY', ''));
    }

    private function apiUrl(): string
    {
        return (string) ($this->config->get('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions') ?? '');
    }

    private function model(): string
    {
        return (string) ($this->config->get('GROQ_MODEL', 'llama-3.1-8b-instant') ?? '');
    }

    private function visionModel(): string
    {
        return (string) ($this->config->get('GROQ_VISION_MODEL', 'meta-llama/llama-4-scout-17b-16e-instruct') ?? '');
    }
}
