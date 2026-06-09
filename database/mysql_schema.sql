SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  password VARCHAR(255) NOT NULL,
  last_login DATETIME NULL,
  is_superuser TINYINT(1) NOT NULL DEFAULT 0,
  username VARCHAR(150) NOT NULL,
  first_name VARCHAR(150) NOT NULL DEFAULT '',
  last_name VARCHAR(150) NOT NULL DEFAULT '',
  email VARCHAR(254) NOT NULL DEFAULT '',
  is_staff TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  date_joined DATETIME NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'recruteur',
  PRIMARY KEY (id),
  UNIQUE KEY users_username_unique (username),
  KEY users_role_idx (role),
  KEY users_active_idx (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS domains (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom VARCHAR(120) NOT NULL,
  description TEXT NULL,
  actif TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY domains_nom_unique (nom),
  KEY domains_actif_idx (actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_positions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  titre VARCHAR(200) NOT NULL,
  description LONGTEXT NOT NULL,
  competences_requises LONGTEXT NULL,
  competences_optionnelles LONGTEXT NULL,
  langues_requises LONGTEXT NULL,
  departement VARCHAR(120) NOT NULL DEFAULT '',
  localisation VARCHAR(120) NOT NULL DEFAULT '',
  type_contrat VARCHAR(80) NOT NULL DEFAULT '',
  experience_min_annees INT UNSIGNED NOT NULL DEFAULT 0,
  niveau_etudes_requis VARCHAR(120) NOT NULL DEFAULT '',
  quota_cible INT UNSIGNED NOT NULL DEFAULT 1,
  workflow_actif TINYINT(1) NOT NULL DEFAULT 1,
  score_qualification DOUBLE NOT NULL DEFAULT 70,
  niveau_priorite VARCHAR(20) NOT NULL DEFAULT 'medium',
  poids_competences DOUBLE NOT NULL DEFAULT 35,
  poids_experience DOUBLE NOT NULL DEFAULT 25,
  poids_formation DOUBLE NOT NULL DEFAULT 20,
  poids_langues DOUBLE NOT NULL DEFAULT 10,
  poids_localisation DOUBLE NOT NULL DEFAULT 5,
  poids_soft_skills DOUBLE NOT NULL DEFAULT 5,
  created_by_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY job_positions_owner_active_idx (created_by_id, workflow_actif),
  KEY job_positions_created_idx (created_at),
  CONSTRAINT job_positions_created_by_fk
    FOREIGN KEY (created_by_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS candidates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL,
  email VARCHAR(254) NOT NULL,
  telephone VARCHAR(20) NOT NULL DEFAULT '',
  localisation VARCHAR(120) NOT NULL DEFAULT '',
  source VARCHAR(20) NOT NULL DEFAULT 'manual',
  source_detail VARCHAR(255) NOT NULL DEFAULT '',
  current_title VARCHAR(160) NOT NULL DEFAULT '',
  niveau_etudes VARCHAR(120) NOT NULL DEFAULT '',
  annees_experience DOUBLE NOT NULL DEFAULT 0,
  competences LONGTEXT NULL,
  langues LONGTEXT NULL,
  soft_skills LONGTEXT NULL,
  resume_profil LONGTEXT NULL,
  score_global DOUBLE NULL,
  recommandation_ia VARCHAR(50) NULL,
  domaine_id INT UNSIGNED NULL,
  consentement_rgpd TINYINT(1) NOT NULL DEFAULT 1,
  created_by_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY candidates_email_unique (email),
  KEY candidates_owner_created_idx (created_by_id, created_at),
  KEY candidates_created_idx (created_at),
  KEY candidates_name_idx (nom, prenom),
  KEY candidates_domain_idx (domaine_id),
  CONSTRAINT candidates_domain_fk
    FOREIGN KEY (domaine_id) REFERENCES domains(id)
    ON DELETE SET NULL,
  CONSTRAINT candidates_created_by_fk
    FOREIGN KEY (created_by_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resumes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidat_id INT UNSIGNED NOT NULL,
  fichier VARCHAR(500) NOT NULL,
  format_fichier VARCHAR(10) NOT NULL,
  texte_extrait LONGTEXT NULL,
  email_source VARCHAR(254) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY resumes_candidate_idx (candidat_id),
  KEY resumes_created_idx (created_at),
  CONSTRAINT resumes_candidate_fk
    FOREIGN KEY (candidat_id) REFERENCES candidates(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidat_id INT UNSIGNED NOT NULL,
  poste_id INT UNSIGNED NOT NULL,
  cv_id INT UNSIGNED NULL,
  statut VARCHAR(20) NOT NULL DEFAULT 'nouveau',
  score DOUBLE NULL,
  recommandation VARCHAR(50) NOT NULL DEFAULT '',
  workflow_step VARCHAR(80) NOT NULL DEFAULT '',
  source_channel VARCHAR(20) NOT NULL DEFAULT 'manual',
  explication_score LONGTEXT NULL,
  score_details_json LONGTEXT NULL,
  decision_comment LONGTEXT NULL,
  sla_due_at DATETIME NULL,
  assigned_to_id INT UNSIGNED NULL,
  created_by_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY applications_owner_status_idx (created_by_id, statut),
  KEY applications_job_status_idx (poste_id, statut),
  KEY applications_candidate_status_idx (candidat_id, statut),
  KEY applications_score_idx (score),
  KEY applications_status_updated_idx (statut, updated_at),
  KEY applications_assigned_idx (assigned_to_id),
  UNIQUE KEY applications_candidate_job_unique (candidat_id, poste_id),
  CONSTRAINT applications_candidate_fk
    FOREIGN KEY (candidat_id) REFERENCES candidates(id)
    ON DELETE CASCADE,
  CONSTRAINT applications_job_fk
    FOREIGN KEY (poste_id) REFERENCES job_positions(id)
    ON DELETE CASCADE,
  CONSTRAINT applications_cv_fk
    FOREIGN KEY (cv_id) REFERENCES resumes(id)
    ON DELETE SET NULL,
  CONSTRAINT applications_assigned_fk
    FOREIGN KEY (assigned_to_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT applications_created_by_fk
    FOREIGN KEY (created_by_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS interviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidature_id INT UNSIGNED NOT NULL,
  titre VARCHAR(200) NOT NULL DEFAULT '',
  type_entretien VARCHAR(20) NOT NULL DEFAULT 'rh',
  debut DATETIME NOT NULL,
  fin DATETIME NOT NULL,
  lieu VARCHAR(255) NOT NULL DEFAULT '',
  notes LONGTEXT NULL,
  created_by_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY interviews_start_idx (debut),
  KEY interviews_application_idx (candidature_id),
  CONSTRAINT interviews_application_fk
    FOREIGN KEY (candidature_id) REFERENCES applications(id)
    ON DELETE CASCADE,
  CONSTRAINT interviews_created_by_fk
    FOREIGN KEY (created_by_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_status_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  candidature_id INT UNSIGNED NOT NULL,
  previous_status VARCHAR(30) NOT NULL DEFAULT '',
  new_status VARCHAR(30) NOT NULL,
  comment LONGTEXT NULL,
  changed_by_id INT UNSIGNED NULL,
  changed_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY status_history_application_idx (candidature_id),
  KEY status_history_changed_at_idx (changed_at),
  CONSTRAINT status_history_application_fk
    FOREIGN KEY (candidature_id) REFERENCES applications(id)
    ON DELETE CASCADE,
  CONSTRAINT status_history_changed_by_fk
    FOREIGN KEY (changed_by_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id VARCHAR(512) NOT NULL,
  sender_email VARCHAR(254) NOT NULL DEFAULT '',
  sender_name VARCHAR(200) NOT NULL DEFAULT '',
  subject VARCHAR(500) NOT NULL DEFAULT '',
  received_at VARCHAR(50) NOT NULL DEFAULT '',
  filename VARCHAR(300) NOT NULL DEFAULT '',
  status VARCHAR(20) NOT NULL DEFAULT 'processed',
  error_message LONGTEXT NULL,
  candidat_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY email_logs_message_unique (message_id),
  KEY email_logs_status_idx (status),
  KEY email_logs_candidate_idx (candidat_id),
  CONSTRAINT email_logs_candidate_fk
    FOREIGN KEY (candidat_id) REFERENCES candidates(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_history (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  emails_scanned INT NOT NULL DEFAULT 0,
  cvs_found INT NOT NULL DEFAULT 0,
  cvs_created INT NOT NULL DEFAULT 0,
  cvs_duplicate INT NOT NULL DEFAULT 0,
  cvs_error INT NOT NULL DEFAULT 0,
  triggered_by VARCHAR(50) NOT NULL DEFAULT 'manual',
  errors_json LONGTEXT NULL,
  PRIMARY KEY (id),
  KEY sync_history_started_idx (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_conversations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY chat_conversations_user_idx (user_id),
  KEY chat_conversations_updated_idx (updated_at),
  CONSTRAINT chat_conversations_user_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  conversation_id INT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL,
  text LONGTEXT NOT NULL,
  highlights_json LONGTEXT NULL,
  suggested_actions_json LONGTEXT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY chat_messages_user_created_idx (user_id, created_at),
  KEY chat_messages_conversation_created_idx (conversation_id, created_at),
  CONSTRAINT chat_messages_user_fk
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT chat_messages_conversation_fk
    FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO domains (nom, description, actif, created_at) VALUES
('Informatique & IT', 'Domaine RH: Informatique & IT', 1, NOW()),
('Ressources Humaines', 'Domaine RH: Ressources Humaines', 1, NOW()),
('Finance & Comptabilite', 'Domaine RH: Finance & Comptabilite', 1, NOW()),
('Marketing & Communication', 'Domaine RH: Marketing & Communication', 1, NOW()),
('Commerce & Vente', 'Domaine RH: Commerce & Vente', 1, NOW()),
('Production Industrielle', 'Domaine RH: Production Industrielle', 1, NOW()),
('Logistique', 'Domaine RH: Logistique', 1, NOW()),
('Maintenance', 'Domaine RH: Maintenance', 1, NOW()),
('Qualite & Securite', 'Domaine RH: Qualite & Securite', 1, NOW()),
('Administration', 'Domaine RH: Administration', 1, NOW());

SET FOREIGN_KEY_CHECKS = 1;

