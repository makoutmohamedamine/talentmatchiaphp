<?php

declare(strict_types=1);

final class App
{
    private const STATUS_LABELS = [
        'nouveau' => 'Nouveau',
        'prequalifie' => 'Pre-qualifie',
        'shortlist' => 'Shortlist',
        'entretien_rh' => 'Entretien RH',
        'entretien_technique' => 'Entretien Technique',
        'validation_manager' => 'Validation Manager',
        'accepte' => 'Accepte',
        'refuse' => 'Refuse',
        'entretien' => 'Entretien',
        'finaliste' => 'Finaliste',
        'offre' => 'Offre',
        'en_cours' => 'En cours',
        'archive' => 'Archive',
    ];

    private const STATUS_FLOW = [
        'nouveau',
        'prequalifie',
        'shortlist',
        'entretien_rh',
        'entretien_technique',
        'validation_manager',
        'accepte',
        'refuse',
    ];

    private const WORKFLOW_STATUS_META = [
        ['value' => 'nouveau', 'label' => 'Nouveau', 'color' => '#b42318'],
        ['value' => 'prequalifie', 'label' => 'Pre-qualifie', 'color' => '#ea580c'],
        ['value' => 'shortlist', 'label' => 'Shortlist', 'color' => '#0f766e'],
        ['value' => 'entretien_rh', 'label' => 'Entretien RH', 'color' => '#1d4ed8'],
        ['value' => 'entretien_technique', 'label' => 'Entretien Technique', 'color' => '#4f46e5'],
        ['value' => 'validation_manager', 'label' => 'Validation Manager', 'color' => '#7c3aed'],
        ['value' => 'accepte', 'label' => 'Accepte', 'color' => '#15803d'],
        ['value' => 'refuse', 'label' => 'Refuse', 'color' => '#6b7280'],
    ];

    private const GENERAL_JOB_TITLE = 'Analyse generale';

    private const DEFAULT_DOMAINS = [
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

    private const JOB_FIELDS = [
        'titre', 'description', 'competences_requises', 'competences_optionnelles',
        'langues_requises', 'departement', 'localisation', 'type_contrat',
        'experience_min_annees', 'niveau_etudes_requis', 'quota_cible',
        'workflow_actif', 'score_qualification', 'niveau_priorite',
        'poids_competences', 'poids_experience', 'poids_formation',
        'poids_langues', 'poids_localisation', 'poids_soft_skills',
    ];

    private const CANDIDATE_FIELDS = [
        'nom', 'prenom', 'email', 'telephone', 'localisation', 'source',
        'source_detail', 'current_title', 'niveau_etudes', 'annees_experience',
        'competences', 'langues', 'soft_skills', 'resume_profil',
        'domaine_id', 'consentement_rgpd',
    ];

    private const APPLICATION_FIELDS = [
        'candidat_id', 'poste_id', 'cv_id', 'statut', 'score', 'recommandation',
        'workflow_step', 'source_channel', 'explication_score',
        'score_details_json', 'decision_comment', 'sla_due_at',
        'assigned_to_id',
    ];

    private const INTERVIEW_FIELDS = [
        'candidature_id', 'titre', 'type_entretien', 'debut', 'fin', 'lieu', 'notes',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly Jwt $jwt,
        private readonly GroqClient $groq,
        private readonly CvTextExtractor $cvExtractor
    ) {
    }

    public function handle(): void
    {
        $this->cors();

        if ($this->method() === 'OPTIONS') {
            Response::noContent();
            return;
        }

        try {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

            if (str_starts_with($path, '/media/')) {
                $this->serveMedia($path);
                return;
            }

            if (!str_starts_with($path, '/api')) {
                Response::json(['status' => 'ok', 'service' => 'NEWPRO PHP backend']);
                return;
            }

            $apiPath = '/' . trim(substr($path, 4), '/');
            if ($apiPath === '/') {
                Response::json(['status' => 'ok', 'service' => 'NEWPRO PHP API']);
                return;
            }

            [$payload, $status] = $this->route($this->method(), rtrim($apiPath, '/') ?: '/');
            Response::json($payload, $status);
        } catch (HttpException $e) {
            Response::json($e->payload ?: ['error' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            $debug = $this->config->bool('DEBUG', false);
            Response::json([
                'error' => $e->getMessage(),
                'detail' => $debug ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    private function route(string $method, string $path): array
    {
        if ($method === 'GET' && $path === '/auth/check-setup') {
            return [$this->checkSetup(), 200];
        }
        if ($method === 'POST' && $path === '/auth/setup') {
            return [$this->setupSuperuser(), 201];
        }
        if ($method === 'POST' && $path === '/auth/login') {
            return [$this->login(), 200];
        }
        if ($method === 'POST' && $path === '/auth/refresh') {
            return [$this->refresh(), 200];
        }
        if ($method === 'POST' && $path === '/auth/logout') {
            $this->auth->currentUser(true);
            return [['message' => 'Deconnexion effectuee.'], 200];
        }
        if ($method === 'GET' && $path === '/auth/me') {
            $user = $this->auth->currentUser(true);
            return [['user' => $this->userPayload($user)], 200];
        }
        if ($method === 'POST' && $path === '/auth/register') {
            return [$this->register(), 201];
        }

        if ($path === '/users' && $method === 'GET') {
            return [$this->usersList(), 200];
        }
        if ($path === '/users/create' && $method === 'POST') {
            return [$this->userCreate(), 201];
        }
        if ($path === '/users/stats' && $method === 'GET') {
            return [$this->adminStats(), 200];
        }
        if (preg_match('#^/users/(\d+)$#', $path, $m) && in_array($method, ['GET', 'PUT', 'PATCH'], true)) {
            return [$this->userDetail((int) $m[1], $method), 200];
        }
        if (preg_match('#^/users/(\d+)/delete$#', $path, $m) && $method === 'DELETE') {
            return [$this->userDelete((int) $m[1]), 200];
        }
        if (preg_match('#^/users/(\d+)/toggle$#', $path, $m) && $method === 'PATCH') {
            return [$this->userToggle((int) $m[1]), 200];
        }

        if ($path === '/dashboard' && $method === 'GET') {
            return [$this->dashboard(), 200];
        }
        if ($path === '/candidates' && $method === 'GET') {
            return [$this->candidatesList(), 200];
        }
        if ($path === '/candidates/upload' && $method === 'POST') {
            return [$this->candidateUpload(), 201];
        }
        if (preg_match('#^/candidates/(\d+)$#', $path, $m) && $method === 'GET') {
            return [$this->candidateDetail((int) $m[1]), 200];
        }
        if (preg_match('#^/candidates/(\d+)/delete$#', $path, $m) && $method === 'DELETE') {
            return [$this->candidateDelete((int) $m[1]), 200];
        }
        if (preg_match('#^/candidates/(\d+)/update$#', $path, $m) && $method === 'PATCH') {
            return [$this->candidateUpdate((int) $m[1]), 200];
        }
        if (preg_match('#^/candidates/(\d+)/history$#', $path, $m) && $method === 'GET') {
            return [$this->candidateHistory((int) $m[1]), 200];
        }
        if (preg_match('#^/candidates/(\d+)/move-domain$#', $path, $m) && $method === 'PATCH') {
            return [$this->candidateMoveDomain((int) $m[1]), 200];
        }

        if ($path === '/workflow/statuses' && $method === 'GET') {
            $this->auth->currentUser(true);
            return [['statuses' => self::WORKFLOW_STATUS_META], 200];
        }
        if ($path === '/domains' && $method === 'GET') {
            return [$this->domainsList(), 200];
        }
        if ($path === '/domains/create' && $method === 'POST') {
            return [$this->domainCreate(), 201];
        }
        if (preg_match('#^/domains/(\d+)/candidates$#', $path, $m) && $method === 'GET') {
            return [$this->domainCandidates((int) $m[1]), 200];
        }

        if ($path === '/dossiers' && $method === 'GET') {
            return [$this->dossiers(), 200];
        }

        if ($path === '/postes' || preg_match('#^/postes/(\d+)$#', $path)) {
            return $this->jobsRoute($method, $path);
        }
        if ($path === '/candidats' || preg_match('#^/candidats/(\d+)$#', $path)) {
            return $this->rawCandidatesRoute($method, $path);
        }
        if ($path === '/cvs' || preg_match('#^/cvs/(\d+)$#', $path)) {
            return $this->cvsRoute($method, $path);
        }
        if ($path === '/candidatures' || preg_match('#^/candidatures/(\d+)$#', $path)) {
            return $this->applicationsRoute($method, $path);
        }
        if ($path === '/entretiens' || preg_match('#^/entretiens/(\d+)$#', $path)) {
            return $this->interviewsRoute($method, $path);
        }

        if ($path === '/ai/analyse' && $method === 'POST') {
            return [$this->aiAnalyse(), 200];
        }
        if ($path === '/ai/score' && $method === 'POST') {
            return [$this->aiScore(), 200];
        }
        if ($path === '/ml/analyse' && $method === 'POST') {
            return [$this->aiAnalyse(), 200];
        }
        if ($path === '/gmail/debug' && $method === 'GET') {
            return [$this->mailPlaceholder('gmail'), 200];
        }
        if ($path === '/gmail/status' && $method === 'GET') {
            return [$this->mailStatusPlaceholder('gmail'), 200];
        }
        if ($path === '/gmail/sync' && $method === 'POST') {
            return [$this->syncPlaceholder('Gmail'), 501];
        }
        if ($path === '/outlook/status' && $method === 'GET') {
            return [$this->outlookStatus(), 200];
        }
        if ($path === '/outlook/sync' && $method === 'POST') {
            return [$this->outlookSync(), 200];
        }

        if ($path === '/chat/conversations' && in_array($method, ['GET', 'POST'], true)) {
            return [$this->chatConversations($method), $method === 'POST' ? 201 : 200];
        }
        if (preg_match('#^/chat/conversations/(\d+)$#', $path, $m) && $method === 'DELETE') {
            return [$this->chatConversationDelete((int) $m[1]), 200];
        }
        if ($path === '/chat/history' && $method === 'GET') {
            return [$this->chatHistory(), 200];
        }
        if ($path === '/chat/history/clear' && in_array($method, ['DELETE', 'POST'], true)) {
            return [$this->chatClear(), 200];
        }
        if ($path === '/chat/ask' && $method === 'POST') {
            return [$this->chatAsk(), 200];
        }

        throw new HttpException(404, 'Route PHP introuvable: ' . $path);
    }

    private function checkSetup(): array
    {
        return ['needs_setup' => ((int) $this->db->value('SELECT COUNT(*) FROM users')) === 0];
    }

    private function setupSuperuser(): array
    {
        if ((int) $this->db->value('SELECT COUNT(*) FROM users') > 0) {
            throw new HttpException(403, 'Le setup initial est deja termine.');
        }

        $data = $this->input();
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || $email === '' || strlen($password) < 6) {
            throw new HttpException(400, 'Username, email et mot de passe valide requis.');
        }

        $user = $this->db->insert('users', [
            'password' => $this->auth->makeDjangoPassword($password),
            'last_login' => $this->now(),
            'is_superuser' => true,
            'username' => $username,
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'email' => $email,
            'is_staff' => true,
            'is_active' => true,
            'date_joined' => $this->now(),
            'role' => 'admin',
        ]);

        return [
            'message' => sprintf('Compte administrateur "%s" cree.', $user['username']),
            ...$this->auth->tokensFor($user),
            'user' => $this->userPayload($user),
        ];
    }

    private function login(): array
    {
        $data = $this->input();
        $username = trim((string) ($data['username'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));

        if ($username === '' || $password === '') {
            throw new HttpException(400, 'Identifiants requis.');
        }

        $user = $this->db->one('SELECT * FROM users WHERE username = :username', ['username' => $username]);
        if (!$user || !$this->auth->verifyPassword($password, (string) $user['password'])) {
            throw new HttpException(401, 'Identifiants incorrects.');
        }
        if (!$this->bool($user['is_active'] ?? false)) {
            throw new HttpException(403, 'Compte desactive.');
        }

        $this->db->run('UPDATE users SET last_login = :now WHERE id = :id', ['now' => $this->now(), 'id' => $user['id']]);
        $user['last_login'] = $this->now();

        return [
            ...$this->auth->tokensFor($user),
            'user' => $this->userPayload($user),
        ];
    }

    private function refresh(): array
    {
        $data = $this->input();
        $token = (string) ($data['refresh'] ?? '');
        if ($token === '') {
            throw new HttpException(400, 'Refresh token requis.');
        }

        $payload = $this->jwt->decode($token, 'refresh');
        $user = $this->db->one('SELECT * FROM users WHERE id = :id', ['id' => (int) ($payload['user_id'] ?? 0)]);
        if (!$user || !$this->bool($user['is_active'] ?? false)) {
            throw new HttpException(401, 'Utilisateur introuvable ou inactif.');
        }

        return $this->auth->tokensFor($user);
    }

    private function register(): array
    {
        $data = $this->input();
        $data['role'] = 'recruteur';
        $user = $this->saveUser($data);
        return [
            'message' => sprintf('Compte "%s" cree.', $user['username']),
            ...$this->auth->tokensFor($user),
            'user' => $this->userPayload($user),
        ];
    }

    private function usersList(): array
    {
        $this->auth->requireAdmin();
        $users = $this->db->all('SELECT * FROM users ORDER BY date_joined DESC');
        return ['users' => array_map(fn (array $u): array => $this->userPayload($u), $users)];
    }

    private function userCreate(): array
    {
        $this->auth->requireAdmin();
        $user = $this->saveUser($this->input());
        return ['message' => sprintf('Compte "%s" cree.', $user['username']), 'user' => $this->userPayload($user)];
    }

    private function userDetail(int $id, string $method): array
    {
        $this->auth->requireAdmin();
        $user = $this->findOr404('users', $id, 'Utilisateur non trouve.');

        if ($method === 'GET') {
            return ['user' => $this->userPayload($user)];
        }

        $updated = $this->saveUser($this->input(), $id);
        return ['message' => 'Utilisateur mis a jour.', 'user' => $this->userPayload($updated)];
    }

    private function userDelete(int $id): array
    {
        $current = $this->auth->requireAdmin();
        if ((int) $current['id'] === $id) {
            throw new HttpException(400, 'Suppression de votre propre compte interdite.');
        }
        $user = $this->findOr404('users', $id, 'Utilisateur non trouve.');
        $this->db->run('DELETE FROM users WHERE id = :id', ['id' => $id]);
        return ['message' => sprintf('Compte "%s" supprime.', $user['username'])];
    }

    private function userToggle(int $id): array
    {
        $current = $this->auth->requireAdmin();
        if ((int) $current['id'] === $id) {
            throw new HttpException(400, 'Action interdite sur votre propre compte.');
        }

        $user = $this->findOr404('users', $id, 'Utilisateur non trouve.');
        $updated = $this->db->update('users', $id, ['is_active' => !$this->bool($user['is_active'])]);
        return ['message' => 'Statut mis a jour.', 'user' => $this->userPayload($updated ?? $user)];
    }

    private function saveUser(array $input, ?int $id = null): array
    {
        $data = [];
        foreach (['username', 'email', 'first_name', 'last_name', 'role', 'is_active'] as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $field === 'is_active' ? $this->bool($input[$field]) : trim((string) $input[$field]);
            }
        }

        if ($id === null) {
            if (($data['username'] ?? '') === '' || ($data['email'] ?? '') === '') {
                throw new HttpException(400, 'Username et email requis.', ['errors' => ['username' => ['Username requis.'], 'email' => ['Email requis.']]]);
            }
            $data += [
                'password' => '!',
                'first_name' => '',
                'last_name' => '',
                'role' => 'recruteur',
                'is_staff' => false,
                'is_superuser' => false,
                'is_active' => true,
                'date_joined' => $this->now(),
            ];
        }

        $password = (string) ($input['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 6) {
                throw new HttpException(400, 'Mot de passe trop court.', ['errors' => ['password' => ['Minimum 6 caracteres.']]]);
            }
            $data['password'] = $this->auth->makeDjangoPassword($password);
        }

        if (($data['role'] ?? null) === 'admin') {
            $data['is_staff'] = true;
        }

        if ($id === null) {
            return $this->db->insert('users', $data);
        }

        $updated = $this->db->update('users', $id, $data);
        if (!$updated) {
            throw new HttpException(404, 'Utilisateur non trouve.');
        }
        return $updated;
    }

    private function adminStats(): array
    {
        $this->auth->requireAdmin();

        $users = $this->db->all('SELECT * FROM users ORDER BY date_joined DESC');
        $total = count($users);
        $active = count(array_filter($users, fn ($u) => $this->bool($u['is_active'] ?? false)));

        $byRole = [];
        foreach ([
            'admin' => 'Administrateurs',
            'rh' => 'Resp. RH',
            'recruteur' => 'Recruteurs',
            'manager' => 'Managers',
        ] as $role => $label) {
            $byRole[$role] = [
                'label' => $label,
                'count' => count(array_filter($users, fn ($u) => ($u['role'] ?? '') === $role)),
            ];
        }

        $activity = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $activity[] = [
                'id' => $uid,
                'username' => $u['username'],
                'full_name' => trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username'],
                'email' => $u['email'] ?? '',
                'role' => $u['role'] ?? 'recruteur',
                'is_active' => $this->bool($u['is_active'] ?? false),
                'date_joined' => $this->formatDate($u['date_joined'] ?? null, 'd/m/Y'),
                'last_login' => $this->formatDate($u['last_login'] ?? null, 'd/m/Y H:i'),
                'candidates_total' => (int) $this->db->value('SELECT COUNT(*) FROM candidates WHERE created_by_id = :id', ['id' => $uid]),
                'postes_total' => (int) $this->db->value('SELECT COUNT(*) FROM job_positions WHERE created_by_id = :id', ['id' => $uid]),
                'candidatures_total' => (int) $this->db->value('SELECT COUNT(*) FROM applications WHERE created_by_id = :id', ['id' => $uid]),
                'candidates_30d' => (int) $this->db->value('SELECT COUNT(*) FROM candidates WHERE created_by_id = :id AND created_at >= NOW() - INTERVAL 30 DAY', ['id' => $uid]),
            ];
        }

        return [
            'users' => [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'new_30d' => (int) $this->db->value('SELECT COUNT(*) FROM users WHERE date_joined >= NOW() - INTERVAL 30 DAY'),
                'new_7d' => (int) $this->db->value('SELECT COUNT(*) FROM users WHERE date_joined >= NOW() - INTERVAL 7 DAY'),
                'by_role' => $byRole,
            ],
            'activity' => $activity,
            'system' => [
                'total_candidates' => (int) $this->db->value('SELECT COUNT(*) FROM candidates'),
                'total_postes' => (int) $this->db->value('SELECT COUNT(*) FROM job_positions'),
                'total_candidatures' => (int) $this->db->value('SELECT COUNT(*) FROM applications'),
                'candidates_30d' => (int) $this->db->value('SELECT COUNT(*) FROM candidates WHERE created_at >= NOW() - INTERVAL 30 DAY'),
                'candidatures_30d' => (int) $this->db->value('SELECT COUNT(*) FROM applications WHERE created_at >= NOW() - INTERVAL 30 DAY'),
            ],
        ];
    }

    private function dashboard(): array
    {
        $user = $this->auth->currentUser(false);
        $items = array_map(fn (array $c): array => $this->candidateSummaryPayload($c, $user), $this->candidateRows($user));
        $items = $this->dedupeCandidates($items);

        $q = strtolower(trim((string) ($_GET['q'] ?? '')));
        $status = trim((string) ($_GET['status'] ?? ''));
        $profile = trim((string) ($_GET['profile'] ?? ''));

        if ($q !== '') {
            $items = array_values(array_filter($items, static function (array $item) use ($q): bool {
                foreach (['fullName', 'email', 'targetJob', 'currentTitle'] as $field) {
                    if (str_contains(strtolower((string) ($item[$field] ?? '')), $q)) {
                        return true;
                    }
                }
                return false;
            }));
        }
        if ($status !== '') {
            $items = array_values(array_filter($items, fn (array $i): bool => ($i['status'] ?? '') === $status));
        }
        if ($profile !== '') {
            $items = array_values(array_filter($items, fn (array $i): bool => ($i['targetJob'] ?? '') === $profile));
        }

        usort($items, fn ($a, $b) => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));
        $scores = array_values(array_filter(array_map(fn ($i) => $i['matchScore'] ?? null, $items), fn ($s) => $s !== null));

        $statusDistribution = [];
        $profileDistribution = [];
        foreach ($items as $item) {
            $st = $item['status'] ?? 'nouveau';
            $statusDistribution[$st] = ($statusDistribution[$st] ?? 0) + 1;
            $profileKey = $item['targetJob'] ?: 'Non classe';
            $profileDistribution[$profileKey] = ($profileDistribution[$profileKey] ?? 0) + 1;
        }

        $funnel = array_map(fn (string $key): array => [
            'key' => $key,
            'label' => self::STATUS_LABELS[$key] ?? $key,
            'count' => $statusDistribution[$key] ?? 0,
        ], self::STATUS_FLOW);

        $jobs = $this->jobRows($user);
        $jobsOverview = array_map(fn (array $job): array => $this->jobOverviewPayload($job, $user), $jobs);

        return [
            'stats' => [
                'totalApplications' => $this->countScoped('applications', 'created_by_id', $user),
                'openJobs' => count(array_filter($jobs, fn ($j) => $this->bool($j['workflow_actif'] ?? false))),
                'totalCandidates' => $this->countScoped('candidates', 'created_by_id', $user),
                'averageScore' => $scores ? round(array_sum($scores) / count($scores), 1) : 0,
                'bestScore' => $scores ? round(max($scores), 1) : 0,
                'newCandidates' => $statusDistribution['nouveau'] ?? 0,
                'qualifiedCandidates' => count(array_filter($scores, fn ($s) => (float) $s >= 70)),
                'interviewsCount' => array_sum(array_map(fn ($k) => $statusDistribution[$k] ?? 0, ['entretien', 'finaliste', 'entretien_rh', 'entretien_technique', 'validation_manager'])),
                'acceptedCandidates' => $statusDistribution['accepte'] ?? 0,
                'refusedCandidates' => $statusDistribution['refuse'] ?? 0,
                'overdueActions' => $this->overdueCount($user),
                'processingDelayHours' => 0,
            ],
            'candidates' => $items,
            'recentCandidates' => array_slice($items, 0, 6),
            'topCandidates' => array_slice($this->sortByScore($items), 0, 5),
            'profileDistribution' => $profileDistribution,
            'statusDistribution' => $statusDistribution,
            'funnel' => $funnel,
            'scoreDistribution' => [
                ['label' => '>=85', 'count' => count(array_filter($scores, fn ($s) => $s >= 85))],
                ['label' => '70-84', 'count' => count(array_filter($scores, fn ($s) => $s >= 70 && $s < 85))],
                ['label' => '50-69', 'count' => count(array_filter($scores, fn ($s) => $s >= 50 && $s < 70))],
                ['label' => '<50', 'count' => count(array_filter($scores, fn ($s) => $s < 50))],
            ],
            'slaAlerts' => array_slice(array_values(array_filter($items, fn ($i) => ($i['matchScore'] ?? 0) >= 85 && in_array($i['status'] ?? '', ['nouveau', 'prequalifie', 'en_cours', 'entretien_rh'], true))), 0, 6),
            'jobsOverview' => $jobsOverview,
            'jobProfiles' => array_map(fn ($p) => ['id' => (int) $p['id'], 'name' => $p['titre']], $jobs),
            'filters' => [
                'statuses' => array_map(fn ($key, $label) => ['value' => $key, 'label' => $label], array_keys(self::STATUS_LABELS), array_values(self::STATUS_LABELS)),
                'profiles' => array_keys($profileDistribution),
            ],
        ];
    }

    private function candidatesList(): array
    {
        $this->bootstrapDomains();
        $this->bootstrapCandidateScoreColumns();
        $user = $this->auth->currentUser(false);
        $domain = trim((string) ($_GET['domain'] ?? ''));
        $rows = $this->candidateRows($user, $domain !== '' ? (int) $domain : null);
        $items = array_map(fn (array $c): array => $this->candidateSummaryPayload($c, $user), $rows);
        return ['candidates' => $this->dedupeCandidates($items)];
    }

    private function candidateDetail(int $id): array
    {
        $user = $this->auth->currentUser(false);
        $candidate = $this->candidateRow($id, $user);
        return ['candidate' => $this->candidateSummaryPayload($candidate, $user)];
    }

    private function candidateDelete(int $id): array
    {
        $user = $this->auth->currentUser(false);
        $candidate = $this->candidateRow($id, $user);
        $this->db->run('DELETE FROM candidates WHERE id = :id', ['id' => $id]);
        return ['message' => 'Candidat supprime: ' . trim(($candidate['prenom'] ?? '') . ' ' . ($candidate['nom'] ?? ''))];
    }

    private function candidateUpdate(int $id): array
    {
        $user = $this->auth->currentUser(false);
        $candidate = $this->candidateRow($id, $user);
        $application = $this->latestApplication($id, $user);
        if (!$application) {
            return ['candidate' => $this->candidateSummaryPayload($candidate, $user)];
        }

        $data = $this->input();
        $updates = ['updated_at' => $this->now()];
        if (($data['status'] ?? '') !== '') {
            $previous = $application['statut'];
            $status = (string) $data['status'];
            $updates['statut'] = $status;
            $updates['workflow_step'] = $this->workflowStep($status);
            $updates['sla_due_at'] = $this->slaDueAt($status);
            if ($previous !== $status) {
                $this->db->insert('application_status_history', [
                    'candidature_id' => $application['id'],
                    'previous_status' => $previous,
                    'new_status' => $status,
                    'comment' => (string) ($data['statusComment'] ?? ''),
                    'changed_by_id' => $user['id'] ?? null,
                    'changed_at' => $this->now(),
                ]);
            }
        }
        if (array_key_exists('decisionComment', $data)) {
            $updates['decision_comment'] = (string) $data['decisionComment'];
        }
        if (array_key_exists('assignedToId', $data)) {
            $updates['assigned_to_id'] = $data['assignedToId'] ?: null;
        }

        $this->db->update('applications', (int) $application['id'], $updates);
        $updated = $this->latestApplication($id, $user);
        return ['candidate' => $this->applicationPayload($updated, $candidate)];
    }

    private function candidateHistory(int $id): array
    {
        $user = $this->auth->currentUser(true);
        $candidate = $this->candidateRow($id, $user);
        $rows = $this->db->all(
            'SELECT h.*, u.username AS changed_by_name
             FROM application_status_history h
             LEFT JOIN users u ON u.id = h.changed_by_id
             INNER JOIN applications a ON a.id = h.candidature_id
             WHERE a.candidat_id = :id
             ORDER BY h.changed_at DESC',
            ['id' => $candidate['id']]
        );

        return ['history' => array_map(fn ($h) => [
            'id' => (int) $h['id'],
            'candidature' => (int) $h['candidature_id'],
            'previous_status' => $h['previous_status'] ?? '',
            'new_status' => $h['new_status'] ?? '',
            'comment' => $h['comment'] ?? '',
            'changed_by' => $h['changed_by_id'] !== null ? (int) $h['changed_by_id'] : null,
            'changed_by_name' => $h['changed_by_name'] ?? '',
            'changed_at' => $this->iso($h['changed_at'] ?? null),
        ], $rows)];
    }

    private function candidateUpload(): array
    {
        $user = $this->auth->currentUser(false);
        $file = $_FILES['cv'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'Aucun fichier CV fourni.');
        }

        $originalName = (string) ($file['name'] ?? 'cv.pdf');
        $format = $this->cvExtractor->detectFormat($originalName);
        $ext = $format;

        $mediaRoot = $this->mediaRoot();
        $cvDir = $mediaRoot . DIRECTORY_SEPARATOR . 'cvs';
        if (!is_dir($cvDir) && !mkdir($cvDir, 0775, true) && !is_dir($cvDir)) {
            throw new RuntimeException('Impossible de creer le dossier media/cvs.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'cv';
        $storedName = $safeName . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $relative = 'cvs/' . $storedName;
        $target = $cvDir . DIRECTORY_SEPARATOR . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            throw new RuntimeException('Echec de sauvegarde du CV.');
        }

        $cvText = trim($this->cvExtractor->extractFromPath($target, $format, $this->groq));
        if ($cvText === '') {
            @unlink($target);
            throw new HttpException(
                400,
                'Impossible d\'extraire le texte du CV (PyMuPDF et Groq Vision). Verifiez PYTHON_BINARY dans backend_php/.env ou essayez le format DOCX.'
            );
        }

        $sourceEmail = trim((string) ($_POST['sourceEmail'] ?? ''));
        $targetJobId = trim((string) ($_POST['targetJobId'] ?? ''));

        $analysis = $this->groq->analyseCv($cvText);
        if (!($analysis['ia_disponible'] ?? false)) {
            @unlink($target);
            throw new HttpException(503, (string) ($analysis['error'] ?? 'Analyse Groq indisponible.'), $analysis);
        }

        $groqRepartition = [];
        $groqPosteId = null;
        $postesForAi = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'titre' => $p['titre'] ?? '',
            'description' => $p['description'] ?? '',
            'competences_requises' => $p['competences_requises'] ?? '',
            'departement' => $p['departement'] ?? '',
        ], array_slice($this->jobRows($user), 0, 40));

        try {
            $groqRepartition = $this->groq->recommanderRepartition($cvText, $postesForAi, self::DEFAULT_DOMAINS);
            if ($targetJobId === '' && ($groqRepartition['ia_disponible'] ?? false) && !empty($groqRepartition['poste_titre'])) {
                $suggested = strtolower(trim((string) $groqRepartition['poste_titre']));
                foreach ($postesForAi as $posteRow) {
                    if (strtolower(trim((string) ($posteRow['titre'] ?? ''))) === $suggested) {
                        $groqPosteId = (string) $posteRow['id'];
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $groqRepartition = [];
        }

        $prenom = trim((string) ($analysis['prenom'] ?? ''));
        $nom = trim((string) ($analysis['nom'] ?? ''));
        if ($prenom === '' && $nom === '') {
            $nameSource = $sourceEmail ? explode('@', $sourceEmail)[0] : pathinfo($originalName, PATHINFO_FILENAME);
            [$prenom, $nom] = $this->parseName($nameSource);
        } elseif ($prenom === '') {
            $prenom = 'Candidat';
        } elseif ($nom === '') {
            $nom = 'Inconnu';
        }

        $email = $this->uniqueCandidateEmail(
            trim((string) ($analysis['email'] ?? '')) ?: $sourceEmail ?: strtolower($safeName) . '@local.invalid'
        );

        $job = null;
        $resolvedJobId = $targetJobId !== '' ? $targetJobId : $groqPosteId;
        if ($resolvedJobId !== null && $resolvedJobId !== '') {
            $job = $this->db->one('SELECT * FROM job_positions WHERE id = :id', ['id' => (int) $resolvedJobId]);
        }

        $domaineId = null;
        if (($groqRepartition['ia_disponible'] ?? false) && !empty($groqRepartition['domaine'])) {
            $this->bootstrapDomains();
            $domainRow = $this->db->one('SELECT id FROM domains WHERE nom = :nom', ['nom' => $groqRepartition['domaine']]);
            $domaineId = $domainRow ? (int) $domainRow['id'] : null;
        }

        $competences = implode(', ', (array) ($analysis['competences_techniques'] ?? []));
        $langues = implode(', ', (array) ($analysis['langues'] ?? []));
        $softSkills = implode(', ', (array) ($analysis['competences_soft'] ?? []));
        $scoreGlobal = (float) ($analysis['score_global'] ?? 0);
        $recommandationIa = trim((string) ($analysis['recommandation'] ?? ''));

        $this->bootstrapCandidateScoreColumns();

        $candidate = $this->db->insert('candidates', [
            'nom' => $nom,
            'prenom' => $prenom,
            'email' => $email,
            'telephone' => trim((string) ($analysis['telephone'] ?? '')),
            'localisation' => trim((string) ($analysis['adresse'] ?? '')),
            'source' => (string) ($_POST['source'] ?? 'manual'),
            'source_detail' => $sourceEmail,
            'current_title' => $job['titre'] ?? '',
            'niveau_etudes' => trim((string) ($analysis['niveau_etudes'] ?? '')),
            'annees_experience' => (float) ($analysis['annees_experience'] ?? 0),
            'competences' => $competences,
            'langues' => $langues,
            'soft_skills' => $softSkills,
            'resume_profil' => trim((string) ($analysis['resume_profil'] ?? '')) ?: 'CV importe et analyse via Groq.',
            'domaine_id' => $domaineId,
            'score_global' => $scoreGlobal,
            'recommandation_ia' => $recommandationIa,
            'consentement_rgpd' => true,
            'created_by_id' => $user['id'] ?? null,
            'created_at' => $this->now(),
        ]);

        $cv = $this->db->insert('resumes', [
            'candidat_id' => $candidate['id'],
            'fichier' => $relative,
            'format_fichier' => $ext,
            'texte_extrait' => $cvText,
            'email_source' => $sourceEmail,
            'created_at' => $this->now(),
        ]);

        if (!$job) {
            $job = $this->bootstrapGeneralJob($user);
        }

        $this->persistGroqApplication(
            $candidate,
            $cv,
            $job,
            $cvText,
            $analysis,
            $user,
            (string) ($_POST['source'] ?? 'manual')
        );

        $payload = $this->candidateSummaryPayload($this->candidateRow((int) $candidate['id'], $user), $user);
        if ($groqRepartition) {
            $routing = [
                'enabled' => (bool) ($groqRepartition['ia_disponible'] ?? false),
                'poste_titre' => $groqRepartition['poste_titre'] ?? '',
                'domaine' => $groqRepartition['domaine'] ?? '',
                'confiance' => $groqRepartition['confiance'] ?? 0,
                'justification' => $groqRepartition['justification'] ?? '',
            ];
            $payload['groqRouting'] = $routing;
            $payload['deepseekRouting'] = $routing;
        }

        return ['candidate' => $payload];
    }

    private function domainsList(): array
    {
        $this->auth->currentUser(true);
        $this->bootstrapDomains();
        $rows = $this->db->all(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM candidates c WHERE c.domaine_id = d.id) AS candidats_count
             FROM domains d
             WHERE d.actif = 1
             ORDER BY d.nom'
        );
        return ['domains' => array_map(fn ($d) => $this->domainPayload($d), $rows)];
    }

    private function domainCreate(): array
    {
        $this->auth->currentUser(true);
        $data = $this->input();
        $name = trim((string) ($data['nom'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        if (strlen($name) < 2) {
            throw new HttpException(400, 'Le nom du dossier est obligatoire.');
        }
        if ($this->db->one('SELECT id FROM domains WHERE nom = :nom', ['nom' => $name])) {
            throw new HttpException(400, 'Ce dossier existe deja.');
        }
        $domain = $this->db->insert('domains', [
            'nom' => $name,
            'description' => $description ?: 'Domaine RH: ' . $name,
            'actif' => true,
            'created_at' => $this->now(),
        ]);
        $domain['candidats_count'] = 0;
        return ['domain' => $this->domainPayload($domain)];
    }

    private function domainCandidates(int $id): array
    {
        $user = $this->auth->currentUser(true);
        $domain = $this->db->one('SELECT *, 0 AS candidats_count FROM domains WHERE id = :id AND actif = 1', ['id' => $id]);
        if (!$domain) {
            throw new HttpException(404, 'Domaine non trouve.');
        }
        $rows = $this->candidateRows($user, $id);
        return [
            'domain' => $this->domainPayload($domain),
            'candidates' => array_map(fn ($c) => $this->candidateSummaryPayload($c, $user), $rows),
        ];
    }

    private function candidateMoveDomain(int $id): array
    {
        $user = $this->auth->currentUser(true);
        $data = $this->input();
        $domainId = (int) ($data['domainId'] ?? 0);
        if ($domainId <= 0) {
            throw new HttpException(400, 'domainId est requis.');
        }
        $candidate = $this->candidateRow($id, $user);
        if (!$this->db->one('SELECT id FROM domains WHERE id = :id AND actif = 1', ['id' => $domainId])) {
            throw new HttpException(404, 'Dossier cible non trouve.');
        }
        $this->db->update('candidates', $id, ['domaine_id' => $domainId]);
        return ['candidate' => $this->candidateSummaryPayload($this->candidateRow((int) $candidate['id'], $user), $user)];
    }

    private function dossiers(): array
    {
        $this->bootstrapDomains();
        $user = $this->auth->currentUser(false);
        $result = [];
        $buckets = [];

        foreach ($this->jobRows($user) as $job) {
            $apps = $this->applicationsForJob((int) $job['id'], $user);
            $items = array_map(function (array $app): array {
                $candidate = $this->candidateRowById((int) $app['candidat_id']);
                return $this->applicationPayload($app, $candidate);
            }, $apps);
            $scores = array_map(fn ($i) => (float) ($i['matchScore'] ?? 0), $items);
            $domain = $this->classifyJobDomain($job);
            $folder = [
                'id' => (int) $job['id'],
                'titre' => $job['titre'] ?? '',
                'description' => $job['description'] ?? '',
                'departement' => $job['departement'] ?? '',
                'localisation' => $job['localisation'] ?? '',
                'typeContrat' => $job['type_contrat'] ?? '',
                'priorite' => $job['niveau_priorite'] ?? 'medium',
                'seuilQualification' => (float) ($job['score_qualification'] ?? 70),
                'competences' => $job['competences_requises'] ?? '',
                'langues' => $job['langues_requises'] ?? '',
                'domaine' => $domain,
                'totalCvs' => count($items),
                'nouveaux' => count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'nouveau')),
                'prequalifies' => count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'prequalifie')),
                'entretiens' => count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'entretien')),
                'acceptes' => count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'accepte')),
                'refuses' => count(array_filter($items, fn ($i) => ($i['status'] ?? '') === 'refuse')),
                'outlookCvs' => count(array_filter($items, fn ($i) => ($i['source'] ?? '') === 'outlook')),
                'bestScore' => $scores ? round(max($scores), 1) : 0,
                'avgScore' => $scores ? round(array_sum($scores) / count($scores), 1) : 0,
                'cvs' => $items,
            ];
            $result[] = $folder;
            $buckets[$domain] ??= ['domaine' => $domain, 'totalPostes' => 0, 'totalCvs' => 0, 'bestScore' => 0, 'dossiers' => []];
            $buckets[$domain]['totalPostes']++;
            $buckets[$domain]['totalCvs'] += $folder['totalCvs'];
            $buckets[$domain]['bestScore'] = max($buckets[$domain]['bestScore'], $folder['bestScore']);
            $buckets[$domain]['dossiers'][] = $folder;
        }

        return ['dossiers' => $result, 'dossiersParDomaine' => array_values($buckets)];
    }

    private function jobsRoute(string $method, string $path): array
    {
        $user = $this->auth->currentUser(false);
        if ($path === '/postes' && $method === 'GET') {
            return [array_map(fn ($j) => $this->jobPayload($j), $this->jobRows($user)), 200];
        }
        if ($path === '/postes' && $method === 'POST') {
            $data = $this->filterInput($this->input(), self::JOB_FIELDS, [
                'description' => '',
                'competences_requises' => '',
                'competences_optionnelles' => '',
                'langues_requises' => '',
                'departement' => '',
                'localisation' => '',
                'type_contrat' => '',
                'experience_min_annees' => 0,
                'niveau_etudes_requis' => '',
                'quota_cible' => 1,
                'workflow_actif' => true,
                'score_qualification' => 70,
                'niveau_priorite' => 'medium',
                'poids_competences' => 35,
                'poids_experience' => 25,
                'poids_formation' => 20,
                'poids_langues' => 10,
                'poids_localisation' => 5,
                'poids_soft_skills' => 5,
            ]);
            $data['created_by_id'] = $user['id'] ?? null;
            $data['created_at'] = $this->now();
            $job = $this->db->insert('job_positions', $data);
            return [$this->jobPayload($job), 201];
        }
        if (preg_match('#^/postes/(\d+)$#', $path, $m)) {
            $id = (int) $m[1];
            if ($method === 'GET') {
                return [$this->jobPayload($this->jobRow($id, $user)), 200];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                $job = $this->jobRow($id, $user);
                $updated = $this->db->update('job_positions', (int) $job['id'], $this->filterInput($this->input(), self::JOB_FIELDS));
                return [$this->jobPayload($updated ?? $job), 200];
            }
            if ($method === 'DELETE') {
                $job = $this->jobRow($id, $user);
                $this->db->run('DELETE FROM job_positions WHERE id = :id', ['id' => $job['id']]);
                return [null, 204];
            }
        }
        throw new HttpException(405, 'Methode non supportee.');
    }

    private function rawCandidatesRoute(string $method, string $path): array
    {
        $user = $this->auth->currentUser(false);
        if ($path === '/candidats' && $method === 'GET') {
            return [array_map(fn ($c) => $this->rawCandidatePayload($c), $this->candidateRows($user)), 200];
        }
        if ($path === '/candidats' && $method === 'POST') {
            $data = $this->filterInput($this->input(), self::CANDIDATE_FIELDS, [
                'telephone' => '',
                'localisation' => '',
                'source' => 'manual',
                'source_detail' => '',
                'current_title' => '',
                'niveau_etudes' => '',
                'annees_experience' => 0,
                'competences' => '',
                'langues' => '',
                'soft_skills' => '',
                'resume_profil' => '',
                'consentement_rgpd' => true,
            ]);
            $data['created_by_id'] = $user['id'] ?? null;
            $data['created_at'] = $this->now();
            return [$this->rawCandidatePayload($this->db->insert('candidates', $data)), 201];
        }
        if (preg_match('#^/candidats/(\d+)$#', $path, $m)) {
            $candidate = $this->candidateRow((int) $m[1], $user);
            if ($method === 'GET') {
                return [$this->rawCandidatePayload($candidate), 200];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                $updated = $this->db->update('candidates', (int) $candidate['id'], $this->filterInput($this->input(), self::CANDIDATE_FIELDS));
                return [$this->rawCandidatePayload($updated ?? $candidate), 200];
            }
            if ($method === 'DELETE') {
                $this->db->run('DELETE FROM candidates WHERE id = :id', ['id' => $candidate['id']]);
                return [null, 204];
            }
        }
        throw new HttpException(405, 'Methode non supportee.');
    }

    private function cvsRoute(string $method, string $path): array
    {
        $user = $this->auth->currentUser(false);
        if ($path === '/cvs' && $method === 'GET') {
            [$scope, $params] = $this->scopeCondition('c', 'created_by_id', $user);
            $rows = $this->db->all("SELECT r.* FROM resumes r INNER JOIN candidates c ON c.id = r.candidat_id WHERE {$scope} ORDER BY r.created_at DESC", $params);
            return [array_map(fn ($r) => $this->cvPayload($r), $rows), 200];
        }
        if (preg_match('#^/cvs/(\d+)$#', $path, $m) && $method === 'GET') {
            $row = $this->db->one('SELECT * FROM resumes WHERE id = :id', ['id' => (int) $m[1]]);
            if (!$row) {
                throw new HttpException(404, 'CV non trouve.');
            }
            return [$this->cvPayload($row), 200];
        }
        throw new HttpException(405, 'Methode non supportee.');
    }

    private function applicationsRoute(string $method, string $path): array
    {
        $user = $this->auth->currentUser(false);
        if ($path === '/candidatures' && $method === 'GET') {
            [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
            $rows = $this->db->all(
                "SELECT a.*, c.nom AS candidat_nom, c.prenom AS candidat_prenom, p.titre AS poste_titre
                 FROM applications a
                 LEFT JOIN candidates c ON c.id = a.candidat_id
                 LEFT JOIN job_positions p ON p.id = a.poste_id
                 WHERE {$scope}
                 ORDER BY a.updated_at DESC",
                $params
            );
            return [array_map(fn ($a) => $this->rawApplicationPayload($a), $rows), 200];
        }
        if ($path === '/candidatures' && $method === 'POST') {
            $data = $this->normalizeApplicationInput($this->input());
            $data['created_by_id'] = $user['id'] ?? null;
            $data['created_at'] = $this->now();
            $data['updated_at'] = $this->now();
            return [$this->rawApplicationPayload($this->db->insert('applications', $data)), 201];
        }
        if (preg_match('#^/candidatures/(\d+)$#', $path, $m)) {
            $application = $this->applicationRow((int) $m[1], $user);
            if ($method === 'GET') {
                return [$this->rawApplicationPayload($application), 200];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                $data = $this->normalizeApplicationInput($this->input());
                $data['updated_at'] = $this->now();
                $updated = $this->db->update('applications', (int) $application['id'], $data);
                return [$this->rawApplicationPayload($updated ?? $application), 200];
            }
            if ($method === 'DELETE') {
                $this->db->run('DELETE FROM applications WHERE id = :id', ['id' => $application['id']]);
                return [null, 204];
            }
        }
        throw new HttpException(405, 'Methode non supportee.');
    }

    private function interviewsRoute(string $method, string $path): array
    {
        $user = $this->auth->currentUser(false);
        if ($path === '/entretiens' && $method === 'GET') {
            $rows = $this->interviewRows($user);
            return [array_map(fn ($e) => $this->interviewPayload($e), $rows), 200];
        }
        if ($path === '/entretiens' && $method === 'POST') {
            $data = $this->normalizeInterviewInput($this->input());
            $data['created_by_id'] = $user['id'] ?? null;
            $data['created_at'] = $this->now();
            $data['updated_at'] = $this->now();
            return [$this->interviewPayload($this->db->insert('interviews', $data)), 201];
        }
        if (preg_match('#^/entretiens/(\d+)$#', $path, $m)) {
            $interview = $this->interviewRow((int) $m[1], $user);
            if ($method === 'GET') {
                return [$this->interviewPayload($interview), 200];
            }
            if (in_array($method, ['PUT', 'PATCH'], true)) {
                $data = $this->normalizeInterviewInput($this->input());
                $data['updated_at'] = $this->now();
                $updated = $this->db->update('interviews', (int) $interview['id'], $data);
                return [$this->interviewPayload($updated ?? $interview), 200];
            }
            if ($method === 'DELETE') {
                $this->db->run('DELETE FROM interviews WHERE id = :id', ['id' => $interview['id']]);
                return [null, 204];
            }
        }
        throw new HttpException(405, 'Methode non supportee.');
    }

    private function chatConversations(string $method): array
    {
        $user = $this->auth->currentUser(true);
        if ($method === 'GET') {
            $rows = $this->db->all(
                'SELECT c.*, COUNT(m.id) AS message_count,
                        (SELECT text FROM chat_messages lm WHERE lm.conversation_id = c.id ORDER BY lm.created_at DESC LIMIT 1) AS last_text
                 FROM chat_conversations c
                 LEFT JOIN chat_messages m ON m.conversation_id = c.id
                 WHERE c.user_id = :user_id
                 GROUP BY c.id
                 ORDER BY c.updated_at DESC
                 LIMIT 120',
                ['user_id' => $user['id']]
            );
            return ['conversations' => array_map(fn ($c) => $this->conversationPayload($c), $rows)];
        }

        $data = $this->input();
        $title = substr(trim((string) ($data['title'] ?? '')), 0, 200);
        $row = $this->db->insert('chat_conversations', [
            'user_id' => $user['id'],
            'title' => $title,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        $row['message_count'] = 0;
        $row['last_text'] = '';
        return ['conversation' => $this->conversationPayload($row)];
    }

    private function chatConversationDelete(int $id): array
    {
        $user = $this->auth->currentUser(true);
        $this->db->run('DELETE FROM chat_conversations WHERE id = :id AND user_id = :user_id', ['id' => $id, 'user_id' => $user['id']]);
        return ['ok' => true];
    }

    private function chatHistory(): array
    {
        $user = $this->auth->currentUser(true);
        $conversationId = (int) ($_GET['conversation'] ?? 0);
        if ($conversationId <= 0) {
            throw new HttpException(400, 'Parametre conversation requis.');
        }
        $limit = max(1, min(500, (int) ($_GET['limit'] ?? 200)));
        $rows = $this->db->all(
            'SELECT m.*
             FROM chat_messages m
             INNER JOIN chat_conversations c ON c.id = m.conversation_id
             WHERE m.user_id = :user_id AND m.conversation_id = :cid
             ORDER BY m.created_at DESC
             LIMIT ' . $limit,
            ['user_id' => $user['id'], 'cid' => $conversationId]
        );
        $rows = array_reverse($rows);
        return ['messages' => array_map(fn ($m) => $this->chatMessagePayload($m), $rows)];
    }

    private function chatClear(): array
    {
        $user = $this->auth->currentUser(true);
        $this->db->run('DELETE FROM chat_conversations WHERE user_id = :user_id', ['user_id' => $user['id']]);
        return ['ok' => true];
    }

    private function chatAsk(): array
    {
        $user = $this->auth->currentUser(true);
        $data = $this->input();
        $question = trim((string) ($data['question'] ?? ''));
        if ($question === '') {
            throw new HttpException(400, 'Question requise.');
        }

        $conversationId = (int) ($data['conversationId'] ?? 0);
        if ($conversationId > 0) {
            $conv = $this->db->one('SELECT * FROM chat_conversations WHERE id = :id AND user_id = :user_id', ['id' => $conversationId, 'user_id' => $user['id']]);
            if (!$conv) {
                throw new HttpException(404, 'Conversation non trouvee.');
            }
        } else {
            $conv = $this->db->insert('chat_conversations', [
                'user_id' => $user['id'],
                'title' => '',
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
            $conversationId = (int) $conv['id'];
        }

        $this->db->insert('chat_messages', [
            'user_id' => $user['id'],
            'conversation_id' => $conversationId,
            'role' => 'user',
            'text' => $question,
            'highlights_json' => '[]',
            'suggested_actions_json' => '[]',
            'created_at' => $this->now(),
        ]);

        $historyRows = $this->db->all(
            'SELECT role, text FROM chat_messages WHERE conversation_id = :cid AND user_id = :uid ORDER BY created_at DESC LIMIT 36',
            ['cid' => $conversationId, 'uid' => $user['id']]
        );
        $historyRows = array_reverse($historyRows);
        $historyLines = [];
        foreach ($historyRows as $hm) {
            $label = ($hm['role'] ?? '') === 'user' ? 'Utilisateur' : 'Assistant';
            $text = trim(preg_replace('/\s+/', ' ', (string) ($hm['text'] ?? '')) ?? '');
            $historyLines[] = '- ' . $label . ': ' . substr($text, 0, 1200);
        }
        $historyBlock = $historyLines ? implode("\n", $historyLines) : '(aucun message precedent dans cette conversation)';

        $candidateRows = array_map(fn (array $c): array => $this->chatCandidateRow($c, $user), array_slice($this->candidateRows($user), 0, 120));
        $posteRows = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'titre' => $p['titre'] ?? '',
            'departement' => $p['departement'] ?? '',
            'priorite' => $p['niveau_priorite'] ?? 'medium',
            'seuil' => (float) ($p['score_qualification'] ?? 70),
        ], array_slice($this->jobRows($user), 0, 40));
        $this->bootstrapDomains();
        $domainRows = array_map(static fn (array $d): array => [
            'id' => (int) $d['id'],
            'nom' => $d['nom'] ?? '',
            'actif' => true,
        ], $this->db->all('SELECT id, nom FROM domains WHERE actif = 1 ORDER BY nom'));

        $llm = $this->groq->chatResponse($question, $historyBlock, $candidateRows, $posteRows, $domainRows);
        if (!($llm['ok'] ?? false)) {
            $errorCode = (string) ($llm['error_code'] ?? '');
            if ($errorCode === 'invalid_api_key') {
                $failAnswer = 'La cle API Groq est invalide ou expiree. '
                    . 'Pour reactiver le chatbot IA: allez sur https://console.groq.com/keys, '
                    . 'generez une nouvelle cle gratuite, et mettez-la dans backend_php/.env (variable GROQ_API_KEY), '
                    . 'puis redemarrez le serveur PHP.';
            } elseif ($errorCode === 'rate_limited') {
                $failAnswer = 'Limite de requetes atteinte. ' . ($llm['error'] ?? 'Reessayez dans quelques secondes.');
            } elseif ($errorCode === 'json_parse_error' && !empty($llm['raw'])) {
                $failAnswer = trim((string) $llm['raw']) ?: 'Je n\'ai pas de reponse exploitable pour le moment.';
            } else {
                $failAnswer = (string) ($llm['error'] ?? 'Le service IA est temporairement indisponible. Reessayez dans un instant.');
            }
            $highlights = [];
            $suggested = [];
            $answer = $failAnswer;
        } else {
            $data = $llm['data'] ?? [];
            $answer = trim((string) ($data['answer'] ?? '')) ?: 'Je n\'ai pas de reponse exploitable pour le moment.';
            $highlights = is_array($data['highlights'] ?? null) ? $data['highlights'] : [];
            $suggested = is_array($data['suggestedActions'] ?? null) ? $data['suggestedActions'] : [];
        }

        $this->db->insert('chat_messages', [
            'user_id' => $user['id'],
            'conversation_id' => $conversationId,
            'role' => 'assistant',
            'text' => $answer,
            'highlights_json' => json_encode($highlights, JSON_UNESCAPED_UNICODE),
            'suggested_actions_json' => json_encode($suggested, JSON_UNESCAPED_UNICODE),
            'created_at' => $this->now(),
        ]);
        $title = trim((string) ($conv['title'] ?? '')) ?: substr($question, 0, 200);
        $this->db->run('UPDATE chat_conversations SET title = :title, updated_at = :now WHERE id = :id', ['title' => $title, 'now' => $this->now(), 'id' => $conversationId]);

        return [
            'answer' => $answer,
            'highlights' => $highlights,
            'suggestedActions' => $suggested,
            'ai_provider' => $llm['provider'] ?? $this->groq->displayName(),
            'conversationId' => $conversationId,
        ];
    }

    private function aiAnalyse(): array
    {
        [$cvText, $jobTitle, $jobDesc] = $this->readUploadedCvText();
        $result = $this->groq->analyseCv($cvText, $jobDesc, $jobTitle);
        if (!($result['ia_disponible'] ?? false)) {
            throw new HttpException(503, (string) ($result['error'] ?? 'Service Groq indisponible.'), $result);
        }
        $result['methode'] = $this->groq->displayName();

        return $result;
    }

    private function aiScore(): array
    {
        [$cvText, $jobTitle, $jobDesc] = $this->readUploadedCvText();
        if ($jobTitle === '' && $jobDesc === '') {
            throw new HttpException(400, 'Titre ou description du poste requis.');
        }
        $result = $this->groq->scoreCvContrePoste($cvText, $jobTitle, $jobDesc);
        if (!($result['ia_disponible'] ?? false)) {
            throw new HttpException(503, (string) ($result['justification'] ?? 'Service Groq indisponible.'), $result);
        }

        return $result;
    }

    private function readUploadedCvText(): array
    {
        $file = $_FILES['cv'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(400, 'Aucun fichier CV fourni.');
        }

        $originalName = (string) ($file['name'] ?? 'cv.pdf');
        $format = $this->cvExtractor->detectFormat($originalName);
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            throw new HttpException(400, 'Fichier CV invalide.');
        }

        $cvText = trim($this->cvExtractor->extractFromPath($tmpPath, $format, $this->groq));
        if ($cvText === '') {
            throw new HttpException(
                400,
                'Impossible d\'extraire le texte du CV (PyMuPDF et Groq Vision). Verifiez PYTHON_BINARY dans backend_php/.env ou essayez le format DOCX.'
            );
        }

        return [
            $cvText,
            trim((string) ($_POST['job_title'] ?? '')),
            trim((string) ($_POST['job_desc'] ?? '')),
        ];
    }

    private function chatCandidateRow(array $candidate, ?array $user): array
    {
        $payload = $this->candidateSummaryPayload($candidate, $user);

        return [
            'id' => $payload['candidateId'] ?? $payload['id'] ?? null,
            'nom' => $payload['fullName'] ?? '',
            'email' => trim((string) ($payload['email'] ?? '')),
            'telephone' => trim((string) ($payload['phone'] ?? '')) ?: null,
            'poste' => $payload['targetJob'] ?? '',
            'domaine' => $payload['domainName'] ?? '',
            'statut' => $payload['statusLabel'] ?? '',
            'score' => $payload['matchScore'] ?? null,
            'localisation' => trim((string) ($payload['location'] ?? '')),
            'titre_professionnel' => trim((string) ($payload['currentTitle'] ?? '')),
            'annees_experience' => $payload['yearsExperience'] ?? 0,
            'niveau_etudes' => trim((string) ($payload['educationLevel'] ?? '')),
            'resume_court' => substr(trim((string) ($payload['summary'] ?? '')), 0, 400),
        ];
    }

    private function mailPlaceholder(string $provider): array
    {
        return [
            'provider' => $provider,
            'connection' => ['status' => 'error', 'message' => 'Connecteur email non migre dans le backend PHP natif.'],
            'already_processed' => (int) $this->db->value('SELECT COUNT(*) FROM email_logs'),
        ];
    }

    private function outlookStatus(): array
    {
        $connection = ['status' => 'error', 'error' => 'Configuration Azure AD manquante.'];
        try {
            $client = OutlookClient::fromConfig($this->config);
            $connection = $client->testConnection();
        } catch (Throwable $e) {
            $connection = [
                'status' => 'error',
                'error' => $e->getMessage(),
                'mailbox' => trim((string) $this->config->get('OUTLOOK_MAILBOX', '')),
            ];
        }

        $syncRows = $this->db->all(
            'SELECT started_at, finished_at, emails_scanned, cvs_created, cvs_error, triggered_by
             FROM sync_history
             ORDER BY started_at DESC
             LIMIT 5'
        );
        $logRows = $this->db->all(
            'SELECT el.*, c.prenom AS candidat_prenom, c.nom AS candidat_nom
             FROM email_logs el
             LEFT JOIN candidates c ON c.id = el.candidat_id
             ORDER BY el.created_at DESC
             LIMIT 20'
        );

        return [
            'connection' => $connection,
            'syncHistory' => array_map(static fn (array $row): array => [
                'startedAt' => (string) ($row['started_at'] ?? ''),
                'finishedAt' => (string) ($row['finished_at'] ?? ''),
                'emailsScanned' => (int) ($row['emails_scanned'] ?? 0),
                'cvsCreated' => (int) ($row['cvs_created'] ?? 0),
                'cvsError' => (int) ($row['cvs_error'] ?? 0),
                'triggeredBy' => (string) ($row['triggered_by'] ?? 'manual'),
            ], $syncRows),
            'emailLogs' => array_map(static function (array $row): array {
                $messageId = (string) ($row['message_id'] ?? '');
                $subject = (string) ($row['subject'] ?? '');
                $errorMessage = (string) ($row['error_message'] ?? '');
                $prenom = trim((string) ($row['candidat_prenom'] ?? ''));
                $nom = trim((string) ($row['candidat_nom'] ?? ''));

                return [
                    'messageId' => strlen($messageId) > 16 ? substr($messageId, 0, 16) . '...' : $messageId,
                    'senderEmail' => (string) ($row['sender_email'] ?? ''),
                    'senderName' => (string) ($row['sender_name'] ?? ''),
                    'subject' => strlen($subject) > 80 ? substr($subject, 0, 80) : $subject,
                    'filename' => (string) ($row['filename'] ?? ''),
                    'status' => (string) ($row['status'] ?? ''),
                    'errorMessage' => strlen($errorMessage) > 200 ? substr($errorMessage, 0, 200) : $errorMessage,
                    'candidatId' => isset($row['candidat_id']) ? (int) $row['candidat_id'] : null,
                    'candidatName' => ($prenom !== '' || $nom !== '') ? trim($prenom . ' ' . $nom) : null,
                    'createdAt' => (string) ($row['created_at'] ?? ''),
                ];
            }, $logRows),
            'totalEmailsProcessed' => (int) $this->db->value('SELECT COUNT(*) FROM email_logs'),
            'totalSyncs' => (int) $this->db->value('SELECT COUNT(*) FROM sync_history'),
        ];
    }

    private function outlookSync(): array
    {
        $user = $this->auth->currentUser(false);
        $startedAt = $this->now();
        $report = [
            'startedAt' => $startedAt,
            'finishedAt' => '',
            'emailsScanned' => 0,
            'cvsFound' => 0,
            'cvsCreated' => 0,
            'cvsDuplicate' => 0,
            'cvsError' => 0,
            'errors' => [],
        ];

        try {
            $client = OutlookClient::fromConfig($this->config);
            $maxMessages = $client->maxMessages($this->config);
            $alreadyProcessed = [];
            foreach ($this->db->all('SELECT message_id FROM email_logs') as $row) {
                $alreadyProcessed[(string) $row['message_id']] = true;
            }

            foreach ($client->fetchCvAttachments($alreadyProcessed, $maxMessages) as $attachment) {
                $report['emailsScanned']++;
                $report['cvsFound']++;
                $messageId = (string) ($attachment['message_id'] ?? '');

                if ($messageId === '' || isset($alreadyProcessed[$messageId])) {
                    $report['cvsDuplicate']++;
                    continue;
                }

                try {
                    $candidateId = $this->importOutlookCv($attachment, $user);
                    $this->db->insert('email_logs', [
                        'message_id' => $messageId,
                        'sender_email' => (string) ($attachment['sender_email'] ?? ''),
                        'sender_name' => (string) ($attachment['sender_name'] ?? ''),
                        'subject' => (string) ($attachment['subject'] ?? ''),
                        'received_at' => (string) ($attachment['received_at'] ?? ''),
                        'filename' => (string) ($attachment['filename'] ?? ''),
                        'status' => 'processed',
                        'error_message' => null,
                        'candidat_id' => $candidateId,
                        'created_at' => $this->now(),
                    ]);
                    $alreadyProcessed[$messageId] = true;
                    $report['cvsCreated']++;
                } catch (Throwable $e) {
                    $report['cvsError']++;
                    $errorMsg = sprintf(
                        "Erreur traitement '%s' (%s) : %s",
                        (string) ($attachment['filename'] ?? ''),
                        (string) ($attachment['sender_email'] ?? ''),
                        $e->getMessage()
                    );
                    $report['errors'][] = $errorMsg;
                    try {
                        $this->db->insert('email_logs', [
                            'message_id' => $messageId,
                            'sender_email' => (string) ($attachment['sender_email'] ?? ''),
                            'sender_name' => (string) ($attachment['sender_name'] ?? ''),
                            'subject' => (string) ($attachment['subject'] ?? ''),
                            'received_at' => (string) ($attachment['received_at'] ?? ''),
                            'filename' => (string) ($attachment['filename'] ?? ''),
                            'status' => 'error',
                            'error_message' => $e->getMessage(),
                            'candidat_id' => null,
                            'created_at' => $this->now(),
                        ]);
                        $alreadyProcessed[$messageId] = true;
                    } catch (Throwable) {
                    }
                }
            }
        } catch (Throwable $e) {
            $report['errors'][] = $e->getMessage();
        }

        $report['finishedAt'] = $this->now();
        $report['success'] = $report['cvsError'] === 0 && $report['errors'] === [];

        $this->db->insert('sync_history', [
            'started_at' => $startedAt,
            'finished_at' => $report['finishedAt'],
            'emails_scanned' => $report['emailsScanned'],
            'cvs_found' => $report['cvsFound'],
            'cvs_created' => $report['cvsCreated'],
            'cvs_duplicate' => $report['cvsDuplicate'],
            'cvs_error' => $report['cvsError'],
            'triggered_by' => 'manual',
            'errors_json' => $report['errors'] !== [] ? json_encode($report['errors'], JSON_UNESCAPED_UNICODE) : null,
        ]);

        return $report;
    }

    /** @param array<string, mixed> $attachment */
    private function importOutlookCv(array $attachment, ?array $user): int
    {
        $originalName = (string) ($attachment['filename'] ?? 'cv.pdf');
        $format = $this->cvExtractor->detectFormat($originalName);
        $bytes = (string) ($attachment['content_bytes'] ?? '');
        if ($bytes === '') {
            throw new RuntimeException('Piece jointe vide.');
        }

        $mediaRoot = $this->mediaRoot();
        $cvDir = $mediaRoot . DIRECTORY_SEPARATOR . 'cvs';
        if (!is_dir($cvDir) && !mkdir($cvDir, 0775, true) && !is_dir($cvDir)) {
            throw new RuntimeException('Impossible de creer le dossier media/cvs.');
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'cv';
        $storedName = $safeName . '_outlook_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $format;
        $relative = 'cvs/' . $storedName;
        $target = $cvDir . DIRECTORY_SEPARATOR . $storedName;

        if (file_put_contents($target, $bytes) === false) {
            throw new RuntimeException('Echec de sauvegarde du CV Outlook.');
        }

        $senderEmail = trim((string) ($attachment['sender_email'] ?? ''));
        $senderName = trim((string) ($attachment['sender_name'] ?? ''));

        $cvText = trim($this->cvExtractor->extractFromPath($target, $format, $this->groq));
        if ($cvText === '') {
            @unlink($target);
            throw new RuntimeException('Impossible d\'extraire le texte du CV (PyMuPDF et Groq Vision).');
        }

        $analysis = $this->groq->analyseCv($cvText);
        if (!($analysis['ia_disponible'] ?? false)) {
            @unlink($target);
            throw new RuntimeException((string) ($analysis['error'] ?? 'Analyse Groq indisponible.'));
        }

        $groqRepartition = [];
        $groqPosteId = null;
        $postesForAi = array_map(static fn (array $p): array => [
            'id' => (int) $p['id'],
            'titre' => $p['titre'] ?? '',
            'description' => $p['description'] ?? '',
            'competences_requises' => $p['competences_requises'] ?? '',
            'departement' => $p['departement'] ?? '',
        ], array_slice($this->jobRows($user), 0, 40));

        try {
            $groqRepartition = $this->groq->recommanderRepartition($cvText, $postesForAi, self::DEFAULT_DOMAINS);
            if (($groqRepartition['ia_disponible'] ?? false) && !empty($groqRepartition['poste_titre'])) {
                $suggested = strtolower(trim((string) $groqRepartition['poste_titre']));
                foreach ($postesForAi as $posteRow) {
                    if (strtolower(trim((string) ($posteRow['titre'] ?? ''))) === $suggested) {
                        $groqPosteId = (string) $posteRow['id'];
                        break;
                    }
                }
            }
        } catch (Throwable) {
            $groqRepartition = [];
        }

        $prenom = trim((string) ($analysis['prenom'] ?? ''));
        $nom = trim((string) ($analysis['nom'] ?? ''));
        if ($prenom === '' && $nom === '') {
            $nameSource = $senderName !== '' ? $senderName : ($senderEmail ? explode('@', $senderEmail)[0] : pathinfo($originalName, PATHINFO_FILENAME));
            [$prenom, $nom] = $this->parseName($nameSource);
        } elseif ($prenom === '') {
            $prenom = 'Candidat';
        } elseif ($nom === '') {
            $nom = 'Inconnu';
        }

        $candidateEmail = trim((string) ($analysis['email'] ?? '')) ?: $senderEmail;
        if ($candidateEmail === '') {
            $candidateEmail = 'inconnu_' . bin2hex(random_bytes(4)) . '@outlook-import.local';
        }

        $job = null;
        if ($groqPosteId !== null && $groqPosteId !== '') {
            $job = $this->db->one('SELECT * FROM job_positions WHERE id = :id', ['id' => (int) $groqPosteId]);
        }

        $domaineId = null;
        if (($groqRepartition['ia_disponible'] ?? false) && !empty($groqRepartition['domaine'])) {
            $this->bootstrapDomains();
            $domainRow = $this->db->one('SELECT id FROM domains WHERE nom = :nom', ['nom' => $groqRepartition['domaine']]);
            $domaineId = $domainRow ? (int) $domainRow['id'] : null;
        }

        $competences = implode(', ', (array) ($analysis['competences_techniques'] ?? []));
        $langues = implode(', ', (array) ($analysis['langues'] ?? []));
        $softSkills = implode(', ', (array) ($analysis['competences_soft'] ?? []));
        $scoreGlobal = (float) ($analysis['score_global'] ?? 0);
        $recommandationIa = trim((string) ($analysis['recommandation'] ?? ''));

        $this->bootstrapCandidateScoreColumns();

        $existing = $this->db->one('SELECT * FROM candidates WHERE email = :email', ['email' => $candidateEmail]);
        if ($existing) {
            $updates = [];
            if (trim((string) ($existing['telephone'] ?? '')) === '' && trim((string) ($analysis['telephone'] ?? '')) !== '') {
                $updates['telephone'] = trim((string) ($analysis['telephone'] ?? ''));
            }
            if (($existing['score_global'] ?? null) === null && $scoreGlobal > 0) {
                $updates['score_global'] = $scoreGlobal;
            }
            if (trim((string) ($existing['recommandation_ia'] ?? '')) === '' && $recommandationIa !== '') {
                $updates['recommandation_ia'] = $recommandationIa;
            }
            if ($domaineId !== null && empty($existing['domaine_id'])) {
                $updates['domaine_id'] = $domaineId;
            }
            if ($updates !== []) {
                $this->db->update('candidates', (int) $existing['id'], $updates);
                $existing = $this->candidateRowById((int) $existing['id']);
            }
            $candidate = $existing;
        } else {
            $candidate = $this->db->insert('candidates', [
                'nom' => $nom,
                'prenom' => $prenom,
                'email' => $candidateEmail,
                'telephone' => trim((string) ($analysis['telephone'] ?? '')),
                'localisation' => trim((string) ($analysis['adresse'] ?? '')),
                'source' => 'outlook',
                'source_detail' => $senderEmail,
                'current_title' => $job['titre'] ?? '',
                'niveau_etudes' => trim((string) ($analysis['niveau_etudes'] ?? '')),
                'annees_experience' => (float) ($analysis['annees_experience'] ?? 0),
                'competences' => $competences,
                'langues' => $langues,
                'soft_skills' => $softSkills,
                'resume_profil' => trim((string) ($analysis['resume_profil'] ?? '')) ?: 'CV importe depuis Outlook et analyse via Groq.',
                'domaine_id' => $domaineId,
                'score_global' => $scoreGlobal,
                'recommandation_ia' => $recommandationIa,
                'consentement_rgpd' => true,
                'created_by_id' => $user['id'] ?? null,
                'created_at' => $this->now(),
            ]);
        }

        $cv = $this->db->insert('resumes', [
            'candidat_id' => $candidate['id'],
            'fichier' => $relative,
            'format_fichier' => $format,
            'texte_extrait' => $cvText,
            'email_source' => $senderEmail,
            'created_at' => $this->now(),
        ]);

        if (!$job) {
            $job = $this->bootstrapGeneralJob($user);
        }

        $this->persistGroqApplication($candidate, $cv, $job, $cvText, $analysis, $user, 'outlook');

        return (int) $candidate['id'];
    }

    private function mailStatusPlaceholder(string $provider): array
    {
        return [
            'provider' => $provider,
            'connection' => ['status' => 'error', 'message' => 'Connecteur email non migre dans le backend PHP natif.'],
            'syncHistory' => [],
            'emailLogs' => [],
            'totalEmailsProcessed' => (int) $this->db->value('SELECT COUNT(*) FROM email_logs'),
            'totalSyncs' => (int) $this->db->value('SELECT COUNT(*) FROM sync_history'),
        ];
    }

    private function syncPlaceholder(string $provider): array
    {
        return [
            'success' => false,
            'errors' => ["Synchronisation {$provider} non migree dans le backend PHP natif."],
            'cvsCreated' => 0,
            'cvsFound' => 0,
        ];
    }

    private function userPayload(array $user): array
    {
        $id = (int) $user['id'];
        return [
            'id' => $id,
            'username' => $user['username'] ?? '',
            'email' => $user['email'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'role' => $user['role'] ?? 'recruteur',
            'is_active' => $this->bool($user['is_active'] ?? false),
            'is_staff' => $this->bool($user['is_staff'] ?? false),
            'is_superuser' => $this->bool($user['is_superuser'] ?? false),
            'date_joined' => $this->iso($user['date_joined'] ?? null),
            'last_login' => $this->iso($user['last_login'] ?? null),
            'candidates_count' => (int) $this->db->value('SELECT COUNT(*) FROM candidates WHERE created_by_id = :id', ['id' => $id]),
            'postes_count' => (int) $this->db->value('SELECT COUNT(*) FROM job_positions WHERE created_by_id = :id', ['id' => $id]),
            'candidatures_count' => (int) $this->db->value('SELECT COUNT(*) FROM applications WHERE created_by_id = :id', ['id' => $id]),
            'last_login_display' => $this->formatDate($user['last_login'] ?? null, 'd/m/Y H:i') ?: 'Jamais connecte',
        ];
    }

    private function jobPayload(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['workflow_actif'] = $this->bool($row['workflow_actif'] ?? false);
        foreach (['experience_min_annees', 'quota_cible'] as $f) {
            $row[$f] = (int) ($row[$f] ?? 0);
        }
        foreach (['score_qualification', 'poids_competences', 'poids_experience', 'poids_formation', 'poids_langues', 'poids_localisation', 'poids_soft_skills'] as $f) {
            $row[$f] = (float) ($row[$f] ?? 0);
        }
        $row['created_by'] = $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null;
        unset($row['created_by_id']);
        return $row;
    }

    private function rawCandidatePayload(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['annees_experience'] = (float) ($row['annees_experience'] ?? 0);
        $row['consentement_rgpd'] = $this->bool($row['consentement_rgpd'] ?? false);
        $row['domaine'] = $row['domaine_id'] !== null ? (int) $row['domaine_id'] : null;
        $row['created_by'] = $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null;
        unset($row['domaine_id'], $row['created_by_id'], $row['domaine_nom']);
        return $row;
    }

    private function cvPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'candidat' => (int) $row['candidat_id'],
            'fichier' => $row['fichier'] ?? '',
            'format_fichier' => $row['format_fichier'] ?? '',
            'texte_extrait' => $row['texte_extrait'] ?? '',
            'email_source' => $row['email_source'] ?? '',
            'created_at' => $this->iso($row['created_at'] ?? null),
        ];
    }

    private function rawApplicationPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'candidat' => (int) $row['candidat_id'],
            'poste' => (int) $row['poste_id'],
            'cv' => $row['cv_id'] !== null ? (int) $row['cv_id'] : null,
            'statut' => $row['statut'] ?? 'nouveau',
            'score' => $row['score'] !== null ? (float) $row['score'] : null,
            'recommandation' => $row['recommandation'] ?? '',
            'workflow_step' => $row['workflow_step'] ?? '',
            'source_channel' => $row['source_channel'] ?? '',
            'explication_score' => $row['explication_score'] ?? '',
            'score_details_json' => $row['score_details_json'] ?? '{}',
            'decision_comment' => $row['decision_comment'] ?? '',
            'sla_due_at' => $this->iso($row['sla_due_at'] ?? null),
            'assigned_to' => $row['assigned_to_id'] !== null ? (int) $row['assigned_to_id'] : null,
            'created_by' => $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null,
            'created_at' => $this->iso($row['created_at'] ?? null),
            'updated_at' => $this->iso($row['updated_at'] ?? null),
            'candidat_nom' => $row['candidat_nom'] ?? '',
            'candidat_prenom' => $row['candidat_prenom'] ?? '',
            'poste_titre' => $row['poste_titre'] ?? '',
        ];
    }

    private function interviewPayload(array $row): array
    {
        $labels = ['rh' => 'Entretien RH', 'technique' => 'Entretien technique', 'final' => 'Entretien final', 'autre' => 'Autre'];
        return [
            'id' => (int) $row['id'],
            'candidature' => (int) $row['candidature_id'],
            'titre' => $row['titre'] ?? '',
            'type_entretien' => $row['type_entretien'] ?? 'rh',
            'debut' => $this->iso($row['debut'] ?? null),
            'fin' => $this->iso($row['fin'] ?? null),
            'lieu' => $row['lieu'] ?? '',
            'notes' => $row['notes'] ?? '',
            'created_by' => $row['created_by_id'] !== null ? (int) $row['created_by_id'] : null,
            'created_at' => $this->iso($row['created_at'] ?? null),
            'updated_at' => $this->iso($row['updated_at'] ?? null),
            'candidat_nom' => $row['candidat_nom'] ?? '',
            'candidat_prenom' => $row['candidat_prenom'] ?? '',
            'poste_titre' => $row['poste_titre'] ?? '',
            'type_entretien_label' => $labels[$row['type_entretien'] ?? 'rh'] ?? ($row['type_entretien'] ?? 'rh'),
        ];
    }

    private function domainPayload(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'nom' => $row['nom'] ?? '',
            'description' => $row['description'] ?? '',
            'actif' => $this->bool($row['actif'] ?? false),
            'created_at' => $this->iso($row['created_at'] ?? null),
            'candidats_count' => (int) ($row['candidats_count'] ?? 0),
        ];
    }

    private function candidateSummaryPayload(array $candidate, ?array $user): array
    {
        $candidate = $this->ensureCandidateGroqScore($candidate, $user);
        $application = $this->latestApplication((int) $candidate['id'], $user);
        if ($application) {
            return $this->applicationPayload($application, $candidate);
        }

        $cv = $this->latestCv((int) $candidate['id']);
        $skills = $this->splitCsv($candidate['competences'] ?? '');
        $score = isset($candidate['score_global']) && $candidate['score_global'] !== null
            ? (float) $candidate['score_global']
            : null;

        return [
            'id' => (int) $candidate['id'],
            'candidatureId' => null,
            'candidateId' => (int) $candidate['id'],
            'fullName' => trim(($candidate['prenom'] ?? '') . ' ' . ($candidate['nom'] ?? '')),
            'email' => $candidate['email'] ?? '',
            'phone' => $candidate['telephone'] ?? '',
            'location' => $candidate['localisation'] ?? '',
            'profileLabel' => $candidate['current_title'] ?: 'Non classe',
            'currentTitle' => $candidate['current_title'] ?? '',
            'matchScore' => $score,
            'status' => 'nouveau',
            'statusLabel' => self::STATUS_LABELS['nouveau'],
            'recommendation' => trim((string) ($candidate['recommandation_ia'] ?? '')) ?: ($score !== null ? $this->recommendation($score) : ''),
            'workflowStep' => 'Ingestion',
            'educationLevel' => $candidate['niveau_etudes'] ?: 'Non precise',
            'yearsExperience' => (float) ($candidate['annees_experience'] ?? 0),
            'summary' => $candidate['resume_profil'] ?: substr((string) ($cv['texte_extrait'] ?? ''), 0, 240),
            'skills' => $skills,
            'languages' => $this->splitCsv($candidate['langues'] ?? ''),
            'softSkills' => $this->splitCsv($candidate['soft_skills'] ?? ''),
            'notes' => '',
            'scoreDetails' => new stdClass(),
            'scoreExplanation' => '',
            'source' => $candidate['source'] ?? '',
            'domainId' => $candidate['domaine_id'] !== null ? (int) $candidate['domaine_id'] : null,
            'domainName' => $candidate['domaine_nom'] ?? '',
            'sourceEmail' => $cv['email_source'] ?? ($candidate['source_detail'] ?? ''),
            'cvUrl' => $this->cvUrl($cv['fichier'] ?? null),
            'cvFileName' => isset($cv['fichier']) ? basename((string) $cv['fichier']) : null,
            'targetJob' => '',
            'assignedTo' => '',
            'slaDueAt' => null,
            'createdAt' => $this->iso($candidate['created_at'] ?? null),
            'updatedAt' => $this->iso($candidate['created_at'] ?? null),
        ];
    }

    private function applicationPayload(array $app, array $candidate): array
    {
        $cvFile = $app['cv_fichier'] ?? null;
        $details = json_decode((string) ($app['score_details_json'] ?? '{}'), true);
        if (!is_array($details)) {
            $details = [];
        }

        return [
            'id' => (int) $app['id'],
            'candidatureId' => (int) $app['id'],
            'candidateId' => (int) $candidate['id'],
            'jobId' => (int) ($app['poste_id'] ?? 0),
            'fullName' => trim(($candidate['prenom'] ?? '') . ' ' . ($candidate['nom'] ?? '')),
            'email' => $candidate['email'] ?? '',
            'phone' => $candidate['telephone'] ?? '',
            'location' => $candidate['localisation'] ?? '',
            'profileLabel' => $this->displayJobTitle((string) ($app['poste_titre'] ?? '')) ?: ($candidate['current_title'] ?? ''),
            'currentTitle' => $candidate['current_title'] ?? '',
            'matchScore' => (float) ($app['score'] ?? 0),
            'status' => $app['statut'] ?? 'nouveau',
            'statusLabel' => self::STATUS_LABELS[$app['statut'] ?? 'nouveau'] ?? ($app['statut'] ?? 'nouveau'),
            'recommendation' => $app['recommandation'] ?: $this->recommendation((float) ($app['score'] ?? 0)),
            'workflowStep' => $app['workflow_step'] ?: $this->workflowStep((string) ($app['statut'] ?? 'nouveau')),
            'educationLevel' => $candidate['niveau_etudes'] ?: 'Non precise',
            'yearsExperience' => (float) ($candidate['annees_experience'] ?? 0),
            'summary' => $candidate['resume_profil'] ?: substr((string) ($app['cv_texte_extrait'] ?? ''), 0, 240),
            'skills' => $this->splitCsv($candidate['competences'] ?? ''),
            'languages' => $this->splitCsv($candidate['langues'] ?? ''),
            'softSkills' => $this->splitCsv($candidate['soft_skills'] ?? ''),
            'notes' => $app['decision_comment'] ?? '',
            'scoreDetails' => (object) $details,
            'scoreExplanation' => $app['explication_score'] ?? '',
            'source' => $candidate['source'] ?? '',
            'domainId' => $candidate['domaine_id'] !== null ? (int) $candidate['domaine_id'] : null,
            'domainName' => $candidate['domaine_nom'] ?? '',
            'sourceEmail' => $app['cv_email_source'] ?? ($candidate['source_detail'] ?? ''),
            'cvUrl' => $this->cvUrl($cvFile),
            'cvFileName' => $cvFile ? basename((string) $cvFile) : null,
            'targetJob' => $this->displayJobTitle((string) ($app['poste_titre'] ?? '')),
            'assignedTo' => $app['assigned_username'] ?? '',
            'slaDueAt' => $this->iso($app['sla_due_at'] ?? null),
            'createdAt' => $this->iso($candidate['created_at'] ?? null),
            'updatedAt' => $this->iso($app['updated_at'] ?? null),
        ];
    }

    private function candidateRows(?array $user, ?int $domainId = null): array
    {
        [$scope, $params] = $this->scopeCondition('c', 'created_by_id', $user);
        $where = [$scope];
        if ($domainId !== null) {
            $where[] = 'c.domaine_id = :domain_id';
            $params['domain_id'] = $domainId;
        }
        return $this->db->all(
            'SELECT c.*, d.nom AS domaine_nom
             FROM candidates c
             LEFT JOIN domains d ON d.id = c.domaine_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.created_at DESC',
            $params
        );
    }

    private function candidateRow(int $id, ?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('c', 'created_by_id', $user);
        $params['id'] = $id;
        $row = $this->db->one(
            "SELECT c.*, d.nom AS domaine_nom
             FROM candidates c
             LEFT JOIN domains d ON d.id = c.domaine_id
             WHERE c.id = :id AND {$scope}",
            $params
        );
        if (!$row) {
            throw new HttpException(404, 'Candidat non trouve.');
        }
        return $row;
    }

    private function candidateRowById(int $id): array
    {
        $row = $this->db->one('SELECT c.*, d.nom AS domaine_nom FROM candidates c LEFT JOIN domains d ON d.id = c.domaine_id WHERE c.id = :id', ['id' => $id]);
        if (!$row) {
            throw new HttpException(404, 'Candidat non trouve.');
        }
        return $row;
    }

    private function latestApplication(int $candidateId, ?array $user): ?array
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        $params['candidate_id'] = $candidateId;
        return $this->db->one(
            "SELECT a.*, p.titre AS poste_titre, r.fichier AS cv_fichier, r.texte_extrait AS cv_texte_extrait,
                    r.email_source AS cv_email_source, u.username AS assigned_username
             FROM applications a
             LEFT JOIN job_positions p ON p.id = a.poste_id
             LEFT JOIN resumes r ON r.id = a.cv_id
             LEFT JOIN users u ON u.id = a.assigned_to_id
             WHERE a.candidat_id = :candidate_id AND {$scope}
             ORDER BY a.updated_at DESC, (a.score IS NULL) ASC, a.score DESC
             LIMIT 1",
            $params
        );
    }

    private function latestCv(int $candidateId): ?array
    {
        return $this->db->one('SELECT * FROM resumes WHERE candidat_id = :id ORDER BY created_at DESC LIMIT 1', ['id' => $candidateId]);
    }

    private function jobRows(?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('p', 'created_by_id', $user);
        return $this->db->all("SELECT p.* FROM job_positions p WHERE {$scope} ORDER BY p.titre", $params);
    }

    private function jobRow(int $id, ?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('p', 'created_by_id', $user);
        $params['id'] = $id;
        $row = $this->db->one("SELECT p.* FROM job_positions p WHERE p.id = :id AND {$scope}", $params);
        if (!$row) {
            throw new HttpException(404, 'Poste non trouve.');
        }
        return $row;
    }

    private function applicationRow(int $id, ?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        $params['id'] = $id;
        $row = $this->db->one(
            "SELECT a.*, c.nom AS candidat_nom, c.prenom AS candidat_prenom, p.titre AS poste_titre
             FROM applications a
             LEFT JOIN candidates c ON c.id = a.candidat_id
             LEFT JOIN job_positions p ON p.id = a.poste_id
             WHERE a.id = :id AND {$scope}",
            $params
        );
        if (!$row) {
            throw new HttpException(404, 'Candidature non trouvee.');
        }
        return $row;
    }

    private function applicationsForJob(int $jobId, ?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        $params['job_id'] = $jobId;
        return $this->db->all(
            "SELECT a.*, p.titre AS poste_titre, r.fichier AS cv_fichier, r.texte_extrait AS cv_texte_extrait,
                    r.email_source AS cv_email_source, u.username AS assigned_username
             FROM applications a
             LEFT JOIN job_positions p ON p.id = a.poste_id
             LEFT JOIN resumes r ON r.id = a.cv_id
             LEFT JOIN users u ON u.id = a.assigned_to_id
             WHERE a.poste_id = :job_id AND {$scope}
             ORDER BY (a.score IS NULL) ASC, a.score DESC, a.updated_at DESC",
            $params
        );
    }

    private function interviewRows(?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        return $this->db->all(
            "SELECT e.*, c.nom AS candidat_nom, c.prenom AS candidat_prenom, p.titre AS poste_titre
             FROM interviews e
             INNER JOIN applications a ON a.id = e.candidature_id
             LEFT JOIN candidates c ON c.id = a.candidat_id
             LEFT JOIN job_positions p ON p.id = a.poste_id
             WHERE {$scope}
             ORDER BY e.debut",
            $params
        );
    }

    private function interviewRow(int $id, ?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        $params['id'] = $id;
        $row = $this->db->one(
            "SELECT e.*, c.nom AS candidat_nom, c.prenom AS candidat_prenom, p.titre AS poste_titre
             FROM interviews e
             INNER JOIN applications a ON a.id = e.candidature_id
             LEFT JOIN candidates c ON c.id = a.candidat_id
             LEFT JOIN job_positions p ON p.id = a.poste_id
             WHERE e.id = :id AND {$scope}",
            $params
        );
        if (!$row) {
            throw new HttpException(404, 'Entretien non trouve.');
        }
        return $row;
    }

    private function jobOverviewPayload(array $job, ?array $user): array
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        $params['job_id'] = $job['id'];
        $row = $this->db->one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN score >= :threshold THEN 1 ELSE 0 END) AS qualified,
                    AVG(score) AS avg_score
             FROM applications a
             WHERE a.poste_id = :job_id AND {$scope}",
            ['threshold' => (float) ($job['score_qualification'] ?? 70), ...$params]
        ) ?? ['total' => 0, 'qualified' => 0, 'avg_score' => 0];

        return [
            'id' => (int) $job['id'],
            'name' => $job['titre'] ?? '',
            'department' => $job['departement'] ?? '',
            'location' => $job['localisation'] ?? '',
            'priority' => $job['niveau_priorite'] ?? 'medium',
            'qualifiedThreshold' => (float) ($job['score_qualification'] ?? 70),
            'candidateCount' => (int) ($row['total'] ?? 0),
            'qualifiedCount' => (int) ($row['qualified'] ?? 0),
            'avgScore' => round((float) ($row['avg_score'] ?? 0), 1),
        ];
    }

    private function normalizeApplicationInput(array $input): array
    {
        foreach (['candidat' => 'candidat_id', 'poste' => 'poste_id', 'cv' => 'cv_id', 'assigned_to' => 'assigned_to_id'] as $from => $to) {
            if (array_key_exists($from, $input)) {
                $input[$to] = $input[$from];
            }
        }
        $data = $this->filterInput($input, self::APPLICATION_FIELDS, [
            'statut' => 'nouveau',
            'score' => null,
            'recommandation' => '',
            'workflow_step' => '',
            'source_channel' => 'manual',
            'explication_score' => '',
            'score_details_json' => '{}',
            'decision_comment' => '',
            'assigned_to_id' => null,
        ]);
        if (isset($data['statut']) && ($data['workflow_step'] ?? '') === '') {
            $data['workflow_step'] = $this->workflowStep((string) $data['statut']);
        }
        return $data;
    }

    private function normalizeInterviewInput(array $input): array
    {
        if (array_key_exists('candidature', $input)) {
            $input['candidature_id'] = $input['candidature'];
        }
        $data = $this->filterInput($input, self::INTERVIEW_FIELDS, [
            'titre' => '',
            'type_entretien' => 'rh',
            'lieu' => '',
            'notes' => '',
        ]);
        if (empty($data['candidature_id']) || empty($data['debut']) || empty($data['fin'])) {
            throw new HttpException(400, 'Candidature, debut et fin sont obligatoires.');
        }
        if (strtotime((string) $data['fin']) <= strtotime((string) $data['debut'])) {
            throw new HttpException(400, 'Heure de fin invalide.', ['fin' => "L'heure de fin doit etre apres le debut."]);
        }
        return $data;
    }

    private function conversationPayload(array $row): array
    {
        $title = trim((string) ($row['title'] ?? ''));
        $last = trim((string) ($row['last_text'] ?? ''));
        if ($title === '') {
            $title = $last !== '' ? substr($last, 0, 72) : 'Conversation du ' . $this->formatDate($row['created_at'] ?? null, 'd/m/Y H:i');
        }
        return [
            'id' => (int) $row['id'],
            'title' => $title,
            'updatedAt' => $this->iso($row['updated_at'] ?? null),
            'createdAt' => $this->iso($row['created_at'] ?? null),
            'messageCount' => (int) ($row['message_count'] ?? 0),
            'preview' => substr($last, 0, 120),
        ];
    }

    private function chatMessagePayload(array $row): array
    {
        $highlights = json_decode((string) ($row['highlights_json'] ?? '[]'), true);
        $suggested = json_decode((string) ($row['suggested_actions_json'] ?? '[]'), true);
        return [
            'id' => (int) $row['id'],
            'role' => $row['role'] ?? 'assistant',
            'text' => $row['text'] ?? '',
            'highlights' => is_array($highlights) ? $highlights : [],
            'suggestedActions' => is_array($suggested) ? $suggested : [],
            'createdAt' => $this->iso($row['created_at'] ?? null),
        ];
    }

    private function filterInput(array $input, array $fields, array $defaults = []): array
    {
        $data = $defaults;
        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = $input[$field];
            }
        }
        foreach ($data as $field => $value) {
            if ($value === '') {
                $nullable = str_ends_with($field, '_id') || in_array($field, ['score', 'sla_due_at'], true);
                $data[$field] = $nullable ? null : '';
            }
        }
        return $data;
    }

    private function input(): array
    {
        $type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains(strtolower($type), 'application/json')) {
            $raw = file_get_contents('php://input') ?: '{}';
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }
        return $_POST ?: [];
    }

    private function scopeCondition(string $alias, string $column, ?array $user): array
    {
        $qualified = $alias . '.' . $column;
        if ($this->auth->isAdmin($user)) {
            return ['1=1', []];
        }
        if (!$user) {
            return [$qualified . ' IS NULL', []];
        }
        return ['(' . $qualified . ' = :scope_user_id OR ' . $qualified . ' IS NULL)', ['scope_user_id' => (int) $user['id']]];
    }

    private function countScoped(string $table, string $column, ?array $user): int
    {
        if ($this->auth->isAdmin($user)) {
            return (int) $this->db->value("SELECT COUNT(*) FROM {$table}");
        }
        if (!$user) {
            return (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE {$column} IS NULL");
        }
        return (int) $this->db->value("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id OR {$column} IS NULL", ['id' => $user['id']]);
    }

    private function overdueCount(?array $user): int
    {
        [$scope, $params] = $this->scopeCondition('a', 'created_by_id', $user);
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM applications a
             WHERE {$scope}
             AND a.sla_due_at IS NOT NULL
             AND a.sla_due_at < NOW()
             AND a.statut NOT IN ('accepte', 'refuse', 'archive')",
            $params
        );
    }

    private function findOr404(string $table, int $id, string $message): array
    {
        $row = $this->db->one("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        if (!$row) {
            throw new HttpException(404, $message);
        }
        return $row;
    }

    private function bootstrapCandidateScoreColumns(): void
    {
        foreach (['score_global DOUBLE NULL', 'recommandation_ia VARCHAR(50) NULL'] as $definition) {
            try {
                $this->db->run('ALTER TABLE candidates ADD COLUMN ' . $definition);
            } catch (PDOException) {
                // Colonne deja presente.
            }
        }
    }

    private function bootstrapGeneralJob(?array $user): array
    {
        $job = $this->db->one('SELECT * FROM job_positions WHERE titre = :titre LIMIT 1', ['titre' => self::GENERAL_JOB_TITLE]);
        if ($job) {
            return $job;
        }

        return $this->db->insert('job_positions', [
            'titre' => self::GENERAL_JOB_TITLE,
            'description' => 'Evaluation generale du profil candidat via Groq.',
            'competences_requises' => '',
            'competences_optionnelles' => '',
            'langues_requises' => '',
            'departement' => 'RH',
            'localisation' => '',
            'type_contrat' => '',
            'experience_min_annees' => 0,
            'niveau_etudes_requis' => '',
            'quota_cible' => 1,
            'workflow_actif' => false,
            'score_qualification' => 70,
            'niveau_priorite' => 'medium',
            'poids_competences' => 35,
            'poids_experience' => 25,
            'poids_formation' => 20,
            'poids_langues' => 10,
            'poids_localisation' => 5,
            'poids_soft_skills' => 5,
            'created_by_id' => $user['id'] ?? null,
            'created_at' => $this->now(),
        ]);
    }

    private function persistGroqApplication(
        array $candidate,
        array $cv,
        array $job,
        string $cvText,
        array $analysis,
        ?array $user,
        string $sourceChannel
    ): void {
        $isGeneral = ($job['titre'] ?? '') === self::GENERAL_JOB_TITLE;
        $score = (float) ($analysis['score_global'] ?? 0);
        $explanation = trim((string) ($analysis['justification_score'] ?? 'Score calcule via Groq.'));
        $details = [
            'methode' => 'Groq',
            'score_global' => $score,
        ];

        if (!$isGeneral) {
            $scoreData = $this->groq->scoreCvContrePoste(
                $cvText,
                (string) ($job['titre'] ?? ''),
                (string) ($job['description'] ?? '')
            );
            if (!($scoreData['ia_disponible'] ?? false)) {
                throw new HttpException(503, (string) ($scoreData['justification'] ?? 'Scoring Groq indisponible.'), $scoreData);
            }
            $score = (float) ($scoreData['score'] ?? $score);
            $explanation = trim((string) ($scoreData['justification'] ?? $explanation));
            $details = [
                'methode' => 'Groq',
                'score_competences' => $scoreData['score_competences'] ?? null,
                'score_experience' => $scoreData['score_experience'] ?? null,
                'score_formation' => $scoreData['score_formation'] ?? null,
                'score_langues' => $scoreData['score_langues'] ?? null,
                'score_domaine' => $scoreData['score_domaine'] ?? null,
                'competences_matchees' => $scoreData['competences_matchees'] ?? [],
                'competences_manquantes' => $scoreData['competences_manquantes'] ?? [],
            ];
        }

        $recommandation = trim((string) ($analysis['recommandation'] ?? '')) ?: $this->recommendation($score);
        $status = $score >= (float) ($job['score_qualification'] ?? 70) ? 'shortlist' : ($score >= 50 ? 'prequalifie' : 'refuse');

        $this->db->insert('applications', [
            'candidat_id' => $candidate['id'],
            'poste_id' => $job['id'],
            'cv_id' => $cv['id'],
            'statut' => $status,
            'score' => $score,
            'recommandation' => $recommandation,
            'workflow_step' => $this->workflowStep($status),
            'source_channel' => $sourceChannel,
            'explication_score' => $explanation,
            'score_details_json' => json_encode($details, JSON_UNESCAPED_UNICODE),
            'decision_comment' => '',
            'sla_due_at' => $this->slaDueAt($status),
            'assigned_to_id' => null,
            'created_by_id' => $user['id'] ?? null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
    }

    private function ensureCandidateGroqScore(array $candidate, ?array $user): array
    {
        if ($this->latestApplication((int) $candidate['id'], $user)) {
            return $candidate;
        }

        if (isset($candidate['score_global']) && $candidate['score_global'] !== null) {
            return $candidate;
        }

        $cv = $this->latestCv((int) $candidate['id']);
        $cvText = trim((string) ($cv['texte_extrait'] ?? ''));
        if ($cvText === '' || !$this->groq->isAvailable()) {
            return $candidate;
        }

        $analysis = $this->groq->analyseCv($cvText);
        if (!($analysis['ia_disponible'] ?? false)) {
            return $candidate;
        }

        $this->bootstrapCandidateScoreColumns();
        $scoreGlobal = (float) ($analysis['score_global'] ?? 0);
        $updated = $this->db->update('candidates', (int) $candidate['id'], [
            'score_global' => $scoreGlobal,
            'recommandation_ia' => trim((string) ($analysis['recommandation'] ?? '')),
        ]) ?? $candidate;

        $job = $this->bootstrapGeneralJob($user);
        $this->persistGroqApplication($updated, $cv, $job, $cvText, $analysis, $user, (string) ($updated['source'] ?? 'manual'));

        return $this->candidateRow((int) $candidate['id'], $user);
    }

    private function displayJobTitle(string $title): string
    {
        return $title === self::GENERAL_JOB_TITLE ? '' : $title;
    }

    private function bootstrapDomains(): void
    {
        foreach (self::DEFAULT_DOMAINS as $domain) {
            $this->db->run(
                'INSERT IGNORE INTO domains (nom, description, actif, created_at)
                 VALUES (:nom, :description, 1, :created_at)',
                ['nom' => $domain, 'description' => 'Domaine RH: ' . $domain, 'created_at' => $this->now()]
            );
        }
    }

    private function dedupeCandidates(array $items): array
    {
        usort($items, fn ($a, $b) => strcmp((string) ($b['updatedAt'] ?? ''), (string) ($a['updatedAt'] ?? '')));
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $email = strtolower(trim((string) ($item['email'] ?? '')));
            $full = strtolower(trim((string) ($item['fullName'] ?? '')));
            $phone = preg_replace('/\D+/', '', (string) ($item['phone'] ?? ''));
            $key = $email ? 'email:' . $email : ($full && $phone ? 'name_phone:' . $full . ':' . $phone : ($full ? 'name:' . $full : 'id:' . ($item['candidateId'] ?? $item['id'] ?? '')));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }
        return $out;
    }

    private function sortByScore(array $items): array
    {
        usort($items, fn ($a, $b) => (float) ($b['matchScore'] ?? 0) <=> (float) ($a['matchScore'] ?? 0));
        return $items;
    }

    private function splitCsv(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== ''));
    }

    private function recommendation(float|int|null $score): string
    {
        $score = (float) ($score ?? 0);
        if ($score >= 85) {
            return 'Excellent';
        }
        if ($score >= 70) {
            return 'Selectionnable';
        }
        if ($score >= 50) {
            return 'A evaluer';
        }
        return 'Insuffisant';
    }

    private function workflowStep(string $status): string
    {
        return [
            'nouveau' => 'Ingestion',
            'prequalifie' => 'Pre-qualification',
            'shortlist' => 'Shortlist',
            'entretien_rh' => 'Entretien RH',
            'entretien_technique' => 'Entretien Technique',
            'validation_manager' => 'Validation Manager',
            'accepte' => 'Cloture',
            'refuse' => 'Cloture',
            'archive' => 'Archive',
            'entretien' => 'Entretien',
            'finaliste' => 'Decision finale',
            'offre' => 'Offre envoyee',
            'en_cours' => 'Evaluation RH',
        ][$status] ?? 'Evaluation RH';
    }

    private function slaDueAt(string $status): string
    {
        $hours = [
            'nouveau' => 24,
            'prequalifie' => 48,
            'shortlist' => 72,
            'entretien_rh' => 96,
            'entretien_technique' => 120,
            'validation_manager' => 48,
            'entretien' => 168,
            'finaliste' => 48,
            'offre' => 72,
            'en_cours' => 24,
        ][$status] ?? 72;
        return date('Y-m-d H:i:s', time() + $hours * 3600);
    }

    private function classifyJobDomain(array $job): string
    {
        $text = strtolower(implode(' ', [
            $job['titre'] ?? '',
            $job['description'] ?? '',
            $job['departement'] ?? '',
            $job['competences_requises'] ?? '',
            $job['competences_optionnelles'] ?? '',
        ]));
        $map = [
            'Industrie & Peinture' => ['industrie', 'industriel', 'peinture', 'colorado', 'production', 'usine', 'maintenance', 'qse', 'qualite'],
            'IT & Digital' => ['developpeur', 'developer', 'software', 'data', 'ia', 'python', 'react', 'django', 'devops', 'cloud', 'informatique'],
            'Commercial & Marketing' => ['commercial', 'vente', 'sales', 'marketing', 'crm', 'communication'],
            'Finance & Administration' => ['finance', 'comptable', 'comptabilite', 'controle de gestion', 'administration', 'rh', 'paie'],
        ];
        $best = 'Autres domaines';
        $bestScore = 0;
        foreach ($map as $domain => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $best = $domain;
                $bestScore = $score;
            }
        }
        return $best;
    }

    private function uniqueCandidateEmail(string $email): string
    {
        $email = trim($email) ?: 'candidate@local.invalid';
        if (!$this->db->one('SELECT id FROM candidates WHERE email = :email', ['email' => $email])) {
            return $email;
        }
        [$local, $domain] = str_contains($email, '@') ? explode('@', $email, 2) : [$email, 'local.invalid'];
        for ($i = 2; $i < 1000; $i++) {
            $candidate = $local . '+' . $i . '@' . $domain;
            if (!$this->db->one('SELECT id FROM candidates WHERE email = :email', ['email' => $candidate])) {
                return $candidate;
            }
        }
        return $local . '+' . bin2hex(random_bytes(3)) . '@' . $domain;
    }

    private function parseName(string $value): array
    {
        $value = trim(preg_replace('/[._-]+/', ' ', $value) ?? '');
        $parts = array_values(array_filter(explode(' ', ucwords(strtolower($value)))));
        if (count($parts) === 0) {
            return ['Candidat', 'Inconnu'];
        }
        if (count($parts) === 1) {
            return [$parts[0], 'Inconnu'];
        }
        $last = array_pop($parts);
        return [implode(' ', $parts), $last];
    }

    private function cvUrl(?string $file): ?string
    {
        if (!$file) {
            return null;
        }
        return '/media/' . ltrim(str_replace('\\', '/', $file), '/');
    }

    private function serveMedia(string $path): void
    {
        $root = realpath($this->mediaRoot());
        if ($root === false) {
            throw new HttpException(404, 'Media introuvable.');
        }

        $relative = urldecode(substr($path, strlen('/media/')));
        $file = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        if ($file === false || !str_starts_with($file, $root) || !is_file($file)) {
            throw new HttpException(404, 'Fichier media introuvable.');
        }

        $type = mime_content_type($file) ?: 'application/octet-stream';
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    private function mediaRoot(): string
    {
        $configured = $this->config->get('MEDIA_ROOT');
        if ($configured) {
            return str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $configured)
                ? $configured
                : $this->config->rootPath() . DIRECTORY_SEPARATOR . $configured;
        }
        return $this->config->projectRoot() . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'media';
    }

    private function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
        if ($this->config->bool('CORS_ALLOW_ALL_ORIGINS', true)) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        } else {
            $allowed = array_map('trim', explode(',', (string) $this->config->get('CORS_ALLOWED_ORIGINS', '')));
            if (in_array($origin, $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
            }
        }
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    }

    private function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true', 'yes', 'on'], true);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function iso(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }
        $time = strtotime((string) $value);
        return $time ? date(DATE_ATOM, $time) : null;
    }

    private function formatDate(mixed $value, string $format): ?string
    {
        if (!$value) {
            return null;
        }
        $time = strtotime((string) $value);
        return $time ? date($format, $time) : null;
    }
}
