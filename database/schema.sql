-- Student Flatmate Finder — database schema v1 (MySQL 8+ / MariaDB 10.5+, InnoDB, utf8mb4)
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                  VARCHAR(120) NOT NULL,
  email                 VARCHAR(190) NOT NULL,
  password_hash         VARCHAR(255) NOT NULL,
  role                  ENUM('student','moderator','super_admin') NOT NULL DEFAULT 'student',
  permissions           VARCHAR(255) NULL,
  status                ENUM('pending','active','restricted','suspended','deleted') NOT NULL DEFAULT 'pending',
  status_reason         VARCHAR(500) NULL,
  date_of_birth         DATE NULL,
  age_confirmed         TINYINT(1) NOT NULL DEFAULT 0,
  email_verified_at     DATETIME NULL,
  last_login_at         DATETIME NULL,
  last_login_ip         VARCHAR(45) NULL,
  last_seen_at          DATETIME NULL,
  password_changed_at   DATETIME NULL,
  deletion_requested_at DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cities (
  id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name   VARCHAR(100) NOT NULL,
  slug   VARCHAR(120) NOT NULL,
  state  VARCHAR(100) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  intro  TEXT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cities_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS colleges (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  city_id      INT UNSIGNED NOT NULL,
  name         VARCHAR(190) NOT NULL,
  slug         VARCHAR(200) NOT NULL,
  email_domain VARCHAR(190) NULL,
  active       TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_colleges_slug (slug),
  KEY idx_colleges_city (city_id),
  KEY idx_colleges_domain (email_domain),
  CONSTRAINT fk_colleges_city FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profiles (
  user_id              INT UNSIGNED NOT NULL,
  display_name         VARCHAR(60) NOT NULL,
  photo_path           VARCHAR(255) NULL,
  gender               ENUM('female','male','non_binary','prefer_not') NULL,
  flatmate_gender_pref ENUM('any','same') NOT NULL DEFAULT 'any',
  college_id           INT UNSIGNED NULL,
  course               VARCHAR(120) NULL,
  academic_year        TINYINT UNSIGNED NULL,
  city_id              INT UNSIGNED NULL,
  locality             VARCHAR(120) NULL,
  accommodation_status ENUM('looking_room','looking_flatmate','has_room','not_sure') NULL,
  move_in_date         DATE NULL,
  budget_min           INT UNSIGNED NULL,
  budget_max           INT UNSIGNED NULL,
  flatmates_count      TINYINT UNSIGNED NULL,
  bio                  VARCHAR(1000) NULL,
  languages            VARCHAR(255) NULL,
  diet                 ENUM('veg','non_veg','eggetarian','vegan','any') NULL,
  diet_pref            ENUM('veg_only','any') NULL,
  smoking              ENUM('never','outside','yes') NULL,
  smoking_ok           TINYINT(1) NULL,
  alcohol              ENUM('never','sometimes','often') NULL,
  alcohol_ok           TINYINT(1) NULL,
  cleanliness          TINYINT UNSIGNED NULL,
  sleep_schedule       ENUM('early','normal','late') NULL,
  study_habits         ENUM('quiet','music','group') NULL,
  social_level         ENUM('introvert','balanced','social') NULL,
  guests               ENUM('rarely','sometimes','often') NULL,
  ac_pref              ENUM('needed','optional','not_needed') NULL,
  room_type            ENUM('private','shared','any') NULL,
  accessibility        VARCHAR(255) NULL,
  discoverable         TINYINT(1) NOT NULL DEFAULT 1,
  visibility           ENUM('members','matches') NOT NULL DEFAULT 'members',
  field_visibility     TEXT NULL,
  completeness         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  onboarding_step      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  onboarded_at         DATETIME NULL,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  KEY idx_profiles_discover (city_id, discoverable, completeness),
  KEY idx_profiles_college (college_id),
  CONSTRAINT fk_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_profiles_city FOREIGN KEY (city_id) REFERENCES cities (id) ON DELETE SET NULL,
  CONSTRAINT fk_profiles_college FOREIGN KEY (college_id) REFERENCES colleges (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_codes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  purpose      ENUM('email_verify','password_reset','college_email') NOT NULL,
  target_email VARCHAR(190) NOT NULL,
  code_hash    CHAR(64) NOT NULL,
  attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at   DATETIME NOT NULL,
  consumed_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_otp_user (user_id, purpose, created_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verifications (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       INT UNSIGNED NOT NULL,
  method        ENUM('college_email','document') NOT NULL,
  college_email VARCHAR(190) NULL,
  document_path VARCHAR(255) NULL,
  document_type ENUM('college_id','admission_letter','fee_receipt','other') NULL,
  status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewer_id   INT UNSIGNED NULL,
  reviewed_at   DATETIME NULL,
  note          VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verif_status (status, created_at),
  KEY idx_verif_user (user_id),
  CONSTRAINT fk_verif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_verif_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS swipes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  swiper_id  INT UNSIGNED NOT NULL,
  target_id  INT UNSIGNED NOT NULL,
  action     ENUM('like','pass') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_swipe (swiper_id, target_id),
  KEY idx_swipe_target (target_id, action),
  CONSTRAINT fk_swipe_swiper FOREIGN KEY (swiper_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_swipe_target FOREIGN KEY (target_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_a       INT UNSIGNED NOT NULL,
  user_b       INT UNSIGNED NOT NULL,
  score        TINYINT UNSIGNED NULL,
  unmatched_by INT UNSIGNED NULL,
  unmatched_at DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match (user_a, user_b),
  KEY idx_match_b (user_b),
  CONSTRAINT fk_match_a FOREIGN KEY (user_a) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_match_b FOREIGN KEY (user_b) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  type            ENUM('flat','shared_room','vacant_bed') NOT NULL,
  title           VARCHAR(140) NOT NULL,
  slug            VARCHAR(170) NOT NULL,
  city_id         INT UNSIGNED NOT NULL,
  locality        VARCHAR(120) NOT NULL,
  address_private VARCHAR(300) NULL,
  near_college_id INT UNSIGNED NULL,
  rent            INT UNSIGNED NOT NULL,
  deposit         INT UNSIGNED NULL,
  available_from  DATE NULL,
  furnishing      ENUM('unfurnished','semi','furnished') NULL,
  gender_pref     ENUM('any','female','male') NOT NULL DEFAULT 'any',
  total_beds      TINYINT UNSIGNED NULL,
  beds_available  TINYINT UNSIGNED NULL,
  amenities       VARCHAR(500) NULL,
  description     TEXT NOT NULL,
  status          ENUM('draft','pending','published','hidden','rejected','closed') NOT NULL DEFAULT 'draft',
  visibility      ENUM('public','members') NOT NULL DEFAULT 'public',
  moderation_note VARCHAR(500) NULL,
  reviewed_at     DATETIME NULL,
  reviewed_by     INT UNSIGNED NULL,
  views           INT UNSIGNED NOT NULL DEFAULT 0,
  published_at    DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_listing_slug (slug),
  KEY idx_listing_search (status, city_id, type, rent),
  KEY idx_listing_user (user_id),
  KEY idx_listing_public (status, visibility, published_at),
  CONSTRAINT fk_listing_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_listing_city FOREIGN KEY (city_id) REFERENCES cities (id),
  CONSTRAINT fk_listing_college FOREIGN KEY (near_college_id) REFERENCES colleges (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS listing_photos (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id INT UNSIGNED NOT NULL,
  path       VARCHAR(255) NOT NULL,
  sort       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_photos_listing (listing_id, sort),
  CONSTRAINT fk_photos_listing FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS saved_items (
  user_id    INT UNSIGNED NOT NULL,
  item_type  ENUM('listing','profile') NOT NULL,
  item_id    INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, item_type, item_id),
  CONSTRAINT fk_saved_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type            ENUM('match','listing') NOT NULL,
  match_id        INT UNSIGNED NULL,
  listing_id      INT UNSIGNED NULL,
  user_a          INT UNSIGNED NOT NULL,
  user_b          INT UNSIGNED NOT NULL,
  pair_key        VARCHAR(60) NOT NULL,
  last_message_at DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_conv_pair (pair_key),
  KEY idx_conv_a (user_a, last_message_at),
  KEY idx_conv_b (user_b, last_message_at),
  CONSTRAINT fk_conv_a FOREIGN KEY (user_a) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_b FOREIGN KEY (user_b) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_match FOREIGN KEY (match_id) REFERENCES matches (id) ON DELETE CASCADE,
  CONSTRAINT fk_conv_listing FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NOT NULL,
  sender_id       INT UNSIGNED NOT NULL,
  body            VARCHAR(2000) NOT NULL,
  read_at         DATETIME NULL,
  hidden_at       DATETIME NULL,
  created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY idx_msg_conv (conversation_id, id),
  CONSTRAINT fk_msg_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enquiries (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  listing_id      INT UNSIGNED NOT NULL,
  student_id      INT UNSIGNED NOT NULL,
  owner_id        INT UNSIGNED NOT NULL,
  kind            ENUM('enquiry','viewing') NOT NULL DEFAULT 'enquiry',
  message         VARCHAR(1000) NOT NULL,
  viewing_at      DATETIME NULL,
  status          ENUM('new','accepted','declined','cancelled') NOT NULL DEFAULT 'new',
  conversation_id INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_enq_owner (owner_id, status),
  KEY idx_enq_student (student_id),
  CONSTRAINT fk_enq_listing FOREIGN KEY (listing_id) REFERENCES listings (id) ON DELETE CASCADE,
  CONSTRAINT fk_enq_student FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_enq_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_enq_conv FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blocks (
  blocker_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_id, blocked_id),
  KEY idx_blocked (blocked_id),
  CONSTRAINT fk_block_a FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_block_b FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id   INT UNSIGNED NOT NULL,
  target_type   ENUM('user','listing','message') NOT NULL,
  target_id     BIGINT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NULL,
  reason        ENUM('fake','harassment','scam','inappropriate','discrimination','safety','spam','other') NOT NULL,
  details       VARCHAR(2000) NULL,
  status        ENUM('open','actioned','dismissed','escalated') NOT NULL DEFAULT 'open',
  severity      ENUM('normal','serious') NOT NULL DEFAULT 'normal',
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME NULL,
  decision_note VARCHAR(1000) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_once (reporter_id, target_type, target_id),
  KEY idx_reports_status (status, severity, created_at),
  KEY idx_reports_target_user (target_user_id),
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_report_decider FOREIGN KEY (decided_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  type       VARCHAR(40) NOT NULL,
  title      VARCHAR(190) NOT NULL,
  link       VARCHAR(255) NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email   VARCHAR(190) NOT NULL,
  subject    VARCHAR(255) NOT NULL,
  body       TEXT NOT NULL,
  status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NULL,
  send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mail_due (status, send_after)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id    INT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  target_type VARCHAR(40) NULL,
  target_id   BIGINT UNSIGNED NULL,
  details     TEXT NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_action (action, created_at),
  KEY idx_audit_actor (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS application_settings (
  setting_key  VARCHAR(120) NOT NULL,
  value        TEXT NULL,
  is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
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

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket_key   VARCHAR(190) NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  last_hit_at  DATETIME(3) NOT NULL,
  PRIMARY KEY (bucket_key),
  KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cron_runs (
  script      VARCHAR(60) NOT NULL,
  last_run_at DATETIME NOT NULL,
  status      VARCHAR(20) NOT NULL,
  message     VARCHAR(500) NULL,
  PRIMARY KEY (script)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sso_nonces (
  nonce      CHAR(32) NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (nonce),
  KEY idx_sso_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(40) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations (version) VALUES ('001_baseline'), ('002_trust_reviews');

SET FOREIGN_KEY_CHECKS = 1;
