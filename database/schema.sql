-- =====================================================================
-- Bharat SEO Backlink Automation — database schema (version 1)
-- Target: MySQL 8.0+ or MariaDB 10.5+ · InnoDB · utf8mb4
-- Executed by install.php (statement by statement). Safe to read; do NOT
-- expose this file publicly (the shipped .htaccess files deny access).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Identity & tenancy
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                VARCHAR(120) NOT NULL,
  email               VARCHAR(190) NOT NULL,
  password_hash       VARCHAR(255) NOT NULL,
  role                ENUM('super_admin','seo_manager','client') NOT NULL,
  status              ENUM('active','disabled') NOT NULL DEFAULT 'active',
  timezone            VARCHAR(64) NULL,
  last_login_at       DATETIME NULL,
  last_login_ip       VARCHAR(45) NULL,
  password_changed_at DATETIME NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clients (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(160) NOT NULL,
  company       VARCHAR(190) NULL,
  contact_email VARCHAR(190) NULL,
  phone         VARCHAR(40) NULL,
  notes         TEXT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_name (name),
  KEY idx_clients_status (status),
  CONSTRAINT fk_clients_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  can_approve TINYINT(1) NOT NULL DEFAULT 0,
  can_export  TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_client_users (client_id, user_id),
  KEY idx_client_users_user (user_id),
  CONSTRAINT fk_client_users_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_client_users_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS projects (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id            INT UNSIGNED NOT NULL,
  name                 VARCHAR(160) NOT NULL,
  website_url          VARCHAR(2048) NOT NULL,
  domain               VARCHAR(253) NOT NULL,
  business_description TEXT NULL,
  niche                VARCHAR(160) NULL,
  target_countries     VARCHAR(500) NULL,
  target_languages     VARCHAR(255) NULL,
  brand_name           VARCHAR(160) NULL,
  preferred_anchors    TEXT NULL,
  contact_email        VARCHAR(190) NULL,
  outreach_from_name   VARCHAR(160) NULL,
  outreach_signature   TEXT NULL,
  exclusions           TEXT NULL,
  approval_policy      ENUM('manager','client','manager_and_client') NOT NULL DEFAULT 'manager',
  status               ENUM('active','paused','archived') NOT NULL DEFAULT 'active',
  verification_token   CHAR(32) NOT NULL,
  verification_method  ENUM('file','dns','meta') NULL,
  verified_at          DATETIME NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_client_domain (client_id, domain),
  KEY idx_projects_status (status),
  KEY idx_projects_domain (domain),
  CONSTRAINT fk_projects_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT,
  CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_members (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  role       ENUM('manager','viewer') NOT NULL DEFAULT 'manager',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_members (project_id, user_id),
  KEY idx_project_members_user (user_id),
  CONSTRAINT fk_project_members_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_project_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_keywords (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  keyword    VARCHAR(190) NOT NULL,
  type       ENUM('keyword','topic') NOT NULL DEFAULT 'keyword',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_keywords (project_id, keyword, type),
  CONSTRAINT fk_project_keywords_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_pages (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  url        VARCHAR(2048) NOT NULL,
  url_hash   CHAR(64) NOT NULL,
  title      VARCHAR(255) NULL,
  priority   TINYINT UNSIGNED NOT NULL DEFAULT 5,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_target_pages (project_id, url_hash),
  CONSTRAINT fk_target_pages_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Discovery
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS discovery_runs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  client_id       INT UNSIGNED NOT NULL,
  source_type     ENUM('manual','csv','dataset','competitor_export','search_api','public_page','prospect_list') NOT NULL,
  source_detail   VARCHAR(500) NULL,
  default_category VARCHAR(40) NULL,
  status          ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
  total_input     INT UNSIGNED NOT NULL DEFAULT 0,
  created_count   INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_count INT UNSIGNED NOT NULL DEFAULT 0,
  rejected_count  INT UNSIGNED NOT NULL DEFAULT 0,
  error_message   TEXT NULL,
  started_by      INT UNSIGNED NULL,
  started_at      DATETIME NULL,
  finished_at     DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_discovery_runs_project (project_id, created_at),
  KEY idx_discovery_runs_client (client_id),
  CONSTRAINT fk_discovery_runs_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_discovery_runs_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_discovery_runs_user FOREIGN KEY (started_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospect_domains (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id        INT UNSIGNED NOT NULL,
  client_id         INT UNSIGNED NOT NULL,
  domain            VARCHAR(253) NOT NULL,
  page_count        INT UNSIGNED NOT NULL DEFAULT 0,
  last_contacted_at DATETIME NULL,
  notes             TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prospect_domains (project_id, domain),
  KEY idx_prospect_domains_client (client_id),
  CONSTRAINT fk_prospect_domains_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_prospect_domains_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prospect_pages (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id              INT UNSIGNED NOT NULL,
  client_id               INT UNSIGNED NOT NULL,
  prospect_domain_id      INT UNSIGNED NOT NULL,
  discovery_run_id        INT UNSIGNED NULL,
  url                     VARCHAR(2048) NOT NULL,
  normalized_url          VARCHAR(2048) NOT NULL,
  dedupe_key              CHAR(64) NOT NULL,
  domain                  VARCHAR(253) NOT NULL,
  title                   VARCHAR(500) NULL,
  site_name               VARCHAR(255) NULL,
  meta_description        TEXT NULL,
  canonical_url           VARCHAR(2048) NULL,
  final_url               VARCHAR(2048) NULL,
  http_status             SMALLINT UNSIGNED NULL,
  content_type            VARCHAR(120) NULL,
  category                ENUM('business_directory','local_citation','industry_association','resource_page','guest_post','expert_contribution','broken_link','unlinked_mention','partner_page','community_profile','other') NOT NULL DEFAULT 'other',
  discovery_source        ENUM('manual','csv','dataset','competitor_export','search_api','public_page','prospect_list') NOT NULL,
  discovered_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  contact_email           VARCHAR(190) NULL,
  contact_name            VARCHAR(160) NULL,
  contact_page_url        VARCHAR(2048) NULL,
  submission_url          VARCHAR(2048) NULL,
  evidence                TEXT NULL,
  proposed_target_page_id INT UNSIGNED NULL,
  suggested_anchor        VARCHAR(255) NULL,
  quality_score           TINYINT UNSIGNED NULL,
  score_band              ENUM('high','review','low') NULL,
  risk_flags              TEXT NULL,
  has_critical_risk       TINYINT(1) NOT NULL DEFAULT 0,
  manual_hold             TINYINT(1) NOT NULL DEFAULT 0,
  hold_reason             VARCHAR(500) NULL,
  status                  ENUM('discovered','evaluated','needs_review','approved','outreach_sent','submitted','verified','rejected','failed') NOT NULL DEFAULT 'discovered',
  status_reason           VARCHAR(500) NULL,
  external_submission_id  VARCHAR(190) NULL,
  word_count              INT UNSIGNED NULL,
  outbound_link_count     INT UNSIGNED NULL,
  last_fetched_at         DATETIME NULL,
  fetch_error             VARCHAR(500) NULL,
  notes                   TEXT NULL,
  created_by              INT UNSIGNED NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prospect_pages_dedupe (project_id, dedupe_key),
  UNIQUE KEY uq_prospect_pages_submission (project_id, external_submission_id),
  KEY idx_prospect_pages_status (project_id, status),
  KEY idx_prospect_pages_client (client_id),
  KEY idx_prospect_pages_score (project_id, quality_score),
  KEY idx_prospect_pages_category (project_id, category),
  KEY idx_prospect_pages_domain (prospect_domain_id),
  KEY idx_prospect_pages_discovered (discovered_at),
  CONSTRAINT fk_prospect_pages_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_prospect_pages_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_prospect_pages_domain FOREIGN KEY (prospect_domain_id) REFERENCES prospect_domains (id) ON DELETE CASCADE,
  CONSTRAINT fk_prospect_pages_run FOREIGN KEY (discovery_run_id) REFERENCES discovery_runs (id) ON DELETE SET NULL,
  CONSTRAINT fk_prospect_pages_target FOREIGN KEY (proposed_target_page_id) REFERENCES target_pages (id) ON DELETE SET NULL,
  CONSTRAINT fk_prospect_pages_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quality_assessments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  prospect_page_id INT UNSIGNED NOT NULL,
  project_id       INT UNSIGNED NOT NULL,
  client_id        INT UNSIGNED NOT NULL,
  total_score      TINYINT UNSIGNED NOT NULL,
  relevance_score  TINYINT UNSIGNED NOT NULL,
  editorial_score  TINYINT UNSIGNED NOT NULL,
  trust_score      TINYINT UNSIGNED NOT NULL,
  technical_score  TINYINT UNSIGNED NOT NULL,
  placement_score  TINYINT UNSIGNED NOT NULL,
  band             ENUM('high','review','low') NOT NULL,
  components       MEDIUMTEXT NOT NULL,
  risk_flags       TEXT NOT NULL,
  has_critical     TINYINT(1) NOT NULL DEFAULT 0,
  scorer_version   VARCHAR(20) NOT NULL,
  is_current       TINYINT(1) NOT NULL DEFAULT 1,
  assessed_by      INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_qa_prospect (prospect_page_id, is_current),
  KEY idx_qa_project (project_id, created_at),
  KEY idx_qa_client (client_id),
  CONSTRAINT fk_qa_prospect FOREIGN KEY (prospect_page_id) REFERENCES prospect_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_qa_user FOREIGN KEY (assessed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Outreach
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outreach_campaigns (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id      INT UNSIGNED NOT NULL,
  client_id       INT UNSIGNED NOT NULL,
  name            VARCHAR(190) NOT NULL,
  type            ENUM('guest_post','resource_page','broken_link','unlinked_mention','expert_contribution','partner_page','general') NOT NULL DEFAULT 'general',
  status          ENUM('draft','active','paused','completed') NOT NULL DEFAULT 'draft',
  follow_up_days  TINYINT UNSIGNED NOT NULL DEFAULT 7,
  max_follow_ups  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  auto_drafts     TINYINT(1) NOT NULL DEFAULT 1,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_name (project_id, name),
  KEY idx_campaigns_client (client_id),
  CONSTRAINT fk_campaigns_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_campaigns_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outreach_messages (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id          INT UNSIGNED NOT NULL,
  project_id           INT UNSIGNED NOT NULL,
  client_id            INT UNSIGNED NOT NULL,
  prospect_page_id     INT UNSIGNED NOT NULL,
  parent_message_id    INT UNSIGNED NULL,
  message_type         ENUM('initial','follow_up') NOT NULL DEFAULT 'initial',
  follow_up_number     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  recipient_email      VARCHAR(190) NULL,
  recipient_name       VARCHAR(160) NULL,
  subject              VARCHAR(255) NOT NULL,
  body                 MEDIUMTEXT NOT NULL,
  subject_alternatives TEXT NULL,
  generator            ENUM('template','local_model','manual') NOT NULL DEFAULT 'template',
  generator_note       VARCHAR(255) NULL,
  revision             INT UNSIGNED NOT NULL DEFAULT 1,
  status               ENUM('draft','needs_review','approved','sending','sent','failed','cancelled','suppressed') NOT NULL DEFAULT 'draft',
  send_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  next_attempt_at      DATETIME NULL,
  last_error           VARCHAR(500) NULL,
  sent_at              DATETIME NULL,
  smtp_message_id      VARCHAR(255) NULL,
  reply_status         ENUM('none','replied','positive','negative','bounced') NOT NULL DEFAULT 'none',
  unsubscribe_token    CHAR(64) NOT NULL,
  dedupe_hash          CHAR(64) NOT NULL,
  approved_revision    INT UNSIGNED NULL,
  approved_at          DATETIME NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_outreach_unsub (unsubscribe_token),
  UNIQUE KEY uq_outreach_dedupe (dedupe_hash),
  KEY idx_outreach_status (status, next_attempt_at),
  KEY idx_outreach_project (project_id, status),
  KEY idx_outreach_client (client_id),
  KEY idx_outreach_campaign (campaign_id),
  KEY idx_outreach_prospect (prospect_page_id),
  KEY idx_outreach_sent (sent_at),
  CONSTRAINT fk_outreach_campaign FOREIGN KEY (campaign_id) REFERENCES outreach_campaigns (id) ON DELETE CASCADE,
  CONSTRAINT fk_outreach_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_outreach_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_outreach_prospect FOREIGN KEY (prospect_page_id) REFERENCES prospect_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_outreach_parent FOREIGN KEY (parent_message_id) REFERENCES outreach_messages (id) ON DELETE SET NULL,
  CONSTRAINT fk_outreach_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS outreach_message_revisions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  revision   INT UNSIGNED NOT NULL,
  subject    VARCHAR(255) NOT NULL,
  body       MEDIUMTEXT NOT NULL,
  edited_by  INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_revision (message_id, revision),
  CONSTRAINT fk_revisions_message FOREIGN KEY (message_id) REFERENCES outreach_messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_revisions_user FOREIGN KEY (edited_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_suppressions (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email      VARCHAR(190) NOT NULL,
  scope_key  VARCHAR(40) NOT NULL DEFAULT 'global',
  project_id INT UNSIGNED NULL,
  reason     ENUM('unsubscribe','bounce','complaint','manual') NOT NULL,
  note       VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppression (email, scope_key),
  KEY idx_suppression_project (project_id),
  CONSTRAINT fk_suppression_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_suppression_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id    INT UNSIGNED NOT NULL,
  client_id     INT UNSIGNED NOT NULL,
  entity_type   ENUM('prospect','outreach_message') NOT NULL,
  entity_id     INT UNSIGNED NOT NULL,
  entity_revision INT UNSIGNED NOT NULL DEFAULT 1,
  required_party ENUM('manager','client') NOT NULL,
  status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  pending_key   VARCHAR(80) NULL,
  requested_by  INT UNSIGNED NULL,
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME NULL,
  decision_note VARCHAR(1000) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_approval_pending (pending_key),
  KEY idx_approval_project_status (project_id, status),
  KEY idx_approval_entity (entity_type, entity_id),
  KEY idx_approval_client (client_id),
  CONSTRAINT fk_approval_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_approval_requested FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_approval_decided FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Backlink monitoring
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS backlink_records (
  id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id             INT UNSIGNED NOT NULL,
  client_id              INT UNSIGNED NOT NULL,
  prospect_page_id       INT UNSIGNED NULL,
  referring_page_url     VARCHAR(2048) NOT NULL,
  referring_url_hash     CHAR(64) NOT NULL,
  referring_domain       VARCHAR(253) NOT NULL,
  target_url             VARCHAR(2048) NOT NULL,
  target_url_hash        CHAR(64) NOT NULL,
  expected_anchor        VARCHAR(255) NULL,
  observed_anchor        VARCHAR(500) NULL,
  http_status            SMALLINT UNSIGNED NULL,
  rel_attributes         VARCHAR(120) NULL,
  is_nofollow            TINYINT(1) NOT NULL DEFAULT 0,
  is_sponsored           TINYINT(1) NOT NULL DEFAULT 0,
  is_ugc                 TINYINT(1) NOT NULL DEFAULT 0,
  link_present           TINYINT(1) NULL,
  status                 ENUM('unverified','active','lost','redirected','broken','not_pointing','changed_anchor','nofollow','temporarily_unavailable') NOT NULL DEFAULT 'unverified',
  lifecycle              ENUM('candidate','confirmed','lost') NOT NULL DEFAULT 'candidate',
  pending_observation    VARCHAR(40) NULL,
  consecutive_failures   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  consecutive_negative   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_detected_at      DATETIME NULL,
  last_verified_at       DATETIME NULL,
  last_checked_at        DATETIME NULL,
  next_check_at          DATETIME NULL,
  last_error             VARCHAR(500) NULL,
  external_submission_id VARCHAR(190) NULL,
  source                 ENUM('outreach','submission','manual','import') NOT NULL DEFAULT 'manual',
  created_by             INT UNSIGNED NULL,
  created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_backlink (project_id, referring_url_hash, target_url_hash),
  UNIQUE KEY uq_backlink_submission (project_id, external_submission_id),
  KEY idx_backlink_status (project_id, status),
  KEY idx_backlink_next_check (next_check_at),
  KEY idx_backlink_client (client_id),
  CONSTRAINT fk_backlink_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_backlink_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_backlink_prospect FOREIGN KEY (prospect_page_id) REFERENCES prospect_pages (id) ON DELETE SET NULL,
  CONSTRAINT fk_backlink_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backlink_check_history (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  backlink_id      INT UNSIGNED NOT NULL,
  project_id       INT UNSIGNED NOT NULL,
  client_id        INT UNSIGNED NOT NULL,
  checked_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  http_status      SMALLINT UNSIGNED NULL,
  final_url        VARCHAR(2048) NULL,
  link_present     TINYINT(1) NULL,
  observed_anchor  VARCHAR(500) NULL,
  rel_attributes   VARCHAR(120) NULL,
  observation      VARCHAR(40) NOT NULL,
  status_before    VARCHAR(40) NOT NULL,
  status_after     VARCHAR(40) NOT NULL,
  status_changed   TINYINT(1) NOT NULL DEFAULT 0,
  error            VARCHAR(500) NULL,
  response_time_ms INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_history_backlink (backlink_id, checked_at),
  KEY idx_history_project (project_id, checked_at),
  KEY idx_history_client (client_id),
  CONSTRAINT fk_history_backlink FOREIGN KEY (backlink_id) REFERENCES backlink_records (id) ON DELETE CASCADE,
  CONSTRAINT fk_history_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_history_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Jobs, cron, imports/exports
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_queue (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type            VARCHAR(60) NOT NULL,
  payload         TEXT NOT NULL,
  project_id      INT UNSIGNED NULL,
  status          ENUM('pending','running','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  priority        TINYINT UNSIGNED NOT NULL DEFAULT 5,
  attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  unique_key      VARCHAR(190) NULL,
  available_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reserved_token  CHAR(32) NULL,
  locked_by       VARCHAR(100) NULL,
  locked_at       DATETIME NULL,
  lock_expires_at DATETIME NULL,
  last_error      VARCHAR(1000) NULL,
  completed_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_job_unique (unique_key),
  UNIQUE KEY uq_job_token (reserved_token),
  KEY idx_job_reserve (status, available_at, priority),
  KEY idx_job_type (type, status),
  KEY idx_job_project (project_id),
  KEY idx_job_lock (status, lock_expires_at),
  CONSTRAINT fk_job_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS failed_jobs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id      BIGINT UNSIGNED NULL,
  type        VARCHAR(60) NOT NULL,
  payload     TEXT NOT NULL,
  project_id  INT UNSIGNED NULL,
  error       VARCHAR(2000) NOT NULL,
  attempts    TINYINT UNSIGNED NOT NULL,
  failed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL,
  resolution  ENUM('retried','dismissed') NULL,
  PRIMARY KEY (id),
  KEY idx_failed_unresolved (resolved_at, failed_at),
  KEY idx_failed_project (project_id),
  CONSTRAINT fk_failed_job FOREIGN KEY (job_id) REFERENCES job_queue (id) ON DELETE SET NULL,
  CONSTRAINT fk_failed_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_failed_user FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  script      VARCHAR(60) NOT NULL,
  trigger_type ENUM('cli','web') NOT NULL DEFAULT 'cli',
  status      ENUM('running','completed','failed','skipped') NOT NULL DEFAULT 'running',
  processed   INT UNSIGNED NOT NULL DEFAULT 0,
  message     VARCHAR(1000) NULL,
  started_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cron_runs_script (script, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_jobs (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id        INT UNSIGNED NOT NULL,
  client_id         INT UNSIGNED NOT NULL,
  user_id           INT UNSIGNED NULL,
  discovery_run_id  INT UNSIGNED NULL,
  type              ENUM('prospects','competitor_backlinks','dataset','prospect_list','backlinks') NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  stored_path       VARCHAR(500) NOT NULL,
  file_sha256       CHAR(64) NOT NULL,
  default_category  VARCHAR(40) NULL,
  status            ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  byte_offset       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  header_map        TEXT NULL,
  total_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  created_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows    INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows        INT UNSIGNED NOT NULL DEFAULT 0,
  errors            TEXT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at       DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import_file (project_id, file_sha256, type),
  KEY idx_import_client (client_id),
  CONSTRAINT fk_import_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_import_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_import_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_import_run FOREIGN KEY (discovery_run_id) REFERENCES discovery_runs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS export_jobs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NULL,
  client_id   INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  type        VARCHAR(40) NOT NULL,
  format      ENUM('csv','html') NOT NULL DEFAULT 'csv',
  status      ENUM('completed','failed') NOT NULL DEFAULT 'completed',
  row_count   INT UNSIGNED NOT NULL DEFAULT 0,
  filters     VARCHAR(1000) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_export_project (project_id, created_at),
  KEY idx_export_client (client_id),
  CONSTRAINT fk_export_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_export_client FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE,
  CONSTRAINT fk_export_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Settings, audit, notifications, security support tables
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS application_settings (
  setting_key  VARCHAR(120) NOT NULL,
  value        TEXT NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  updated_by   INT UNSIGNED NULL,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_settings (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  INT UNSIGNED NOT NULL,
  setting_key VARCHAR(120) NOT NULL,
  value       TEXT NULL,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_project_settings (project_id, setting_key),
  CONSTRAINT fk_project_settings_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NULL,
  client_id   INT UNSIGNED NULL,
  project_id  INT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  entity_type VARCHAR(60) NULL,
  entity_id   BIGINT UNSIGNED NULL,
  details     TEXT NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  prev_hash   CHAR(64) NOT NULL,
  entry_hash  CHAR(64) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_audit_hash (entry_hash),
  KEY idx_audit_project (project_id, created_at),
  KEY idx_audit_client (client_id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_user (user_id)
  -- Deliberately no foreign keys: audit rows must survive deletion of users/projects.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  type       VARCHAR(60) NOT NULL,
  title      VARCHAR(190) NOT NULL,
  body       VARCHAR(1000) NULL,
  link       VARCHAR(500) NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user (user_id, is_read, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email_hash   CHAR(64) NOT NULL,
  ip_address   VARCHAR(45) NOT NULL,
  success      TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_email (email_hash, attempted_at),
  KEY idx_login_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (user_id),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   VARCHAR(190) NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  last_hit_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (bucket_key),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS robots_cache (
  host        VARCHAR(253) NOT NULL,
  status_code SMALLINT UNSIGNED NOT NULL,
  body        MEDIUMTEXT NULL,
  fetched_at  DATETIME NOT NULL,
  PRIMARY KEY (host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(40) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_baseline');

SET FOREIGN_KEY_CHECKS = 1;
