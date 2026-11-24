-- =====================================================================
-- Ducks In A Row: database tables for IONOS (MariaDB / MySQL)
-- Run in phpMyAdmin: select your database -> "Import" tab (or the "SQL" tab and paste).
-- Safe to run more than once (it only creates what is missing).
--
-- Every row belongs to a Clerk user id (looks like "user_2abc..."). The PHP API
-- checks the signed-in user on every request and only reads/writes their own rows.
-- =====================================================================

CREATE TABLE IF NOT EXISTS profiles (
  user_id            VARCHAR(64)  NOT NULL,
  full_name          VARCHAR(120) NULL,
  school             VARCHAR(120) NULL,
  program            VARCHAR(120) NULL,
  year_of_study      VARCHAR(30)  NULL,
  timezone           VARCHAR(64)  NULL,
  week_starts_on     ENUM('monday','sunday') NOT NULL DEFAULT 'monday',
  reminders_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
  onboarded          TINYINT(1)   NOT NULL DEFAULT 0,
  created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courses picked during onboarding (course code + section + lab/tutorial sections).
CREATE TABLE IF NOT EXISTS courses (
  id            CHAR(36)    NOT NULL,
  user_id       VARCHAR(64) NOT NULL,
  course_code   VARCHAR(12) NOT NULL,          -- e.g. COMP 1405
  section       VARCHAR(8)  NULL,              -- e.g. A
  lab_sections  VARCHAR(255) NOT NULL DEFAULT '[]',  -- JSON list, e.g. ["A1","B2"]
  term          VARCHAR(30) NULL,              -- e.g. Winter 2027
  created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY courses_user_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "To's and Due's" on the dashboard.
CREATE TABLE IF NOT EXISTS tasks (
  id          CHAR(36)     NOT NULL,
  user_id     VARCHAR(64)  NOT NULL,
  title       VARCHAR(160) NOT NULL,
  due_date    DATE         NULL,
  due_time    TIME         NULL,
  done        TINYINT(1)   NOT NULL DEFAULT 0,
  done_at     DATETIME     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY tasks_user_idx (user_id, done, due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Classes" on the dashboard: weekly repeating meetings.
CREATE TABLE IF NOT EXISTS classes (
  id          CHAR(36)     NOT NULL,
  user_id     VARCHAR(64)  NOT NULL,
  title       VARCHAR(80)  NOT NULL,
  days        VARCHAR(30)  NOT NULL,           -- e.g. mon,wed,fri
  all_day     TINYINT(1)   NOT NULL DEFAULT 0,
  start_time  TIME         NULL,
  end_time    TIME         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY classes_user_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Events" on the dashboard (and the calendar page later): one-off dated items.
CREATE TABLE IF NOT EXISTS events (
  id          CHAR(36)     NOT NULL,
  user_id     VARCHAR(64)  NOT NULL,
  title       VARCHAR(160) NOT NULL,
  event_date  DATE         NOT NULL,
  all_day     TINYINT(1)   NOT NULL DEFAULT 0,
  start_time  TIME         NULL,
  end_time    TIME         NULL,
  notes       TEXT         NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY events_user_idx (user_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
