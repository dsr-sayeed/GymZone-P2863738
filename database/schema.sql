-- Apartments in the housing community. Several residents can share one apartment.
CREATE TABLE IF NOT EXISTS apartments (
    -- id: a number that identifies the row; AUTO_INCREMENT counts up by itself.
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    number      VARCHAR(20)  NOT NULL,
    created_at  DATETIME     NOT NULL,
    UNIQUE KEY uq_apartments_number (number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Residents and housing administrators.
-- status: pending = invited, has not set a password yet; active = can sign in; inactive = deactivated by an administrator.
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- role: resident or admin. It decides which pages the user may open.
    role            ENUM('resident','admin') NOT NULL DEFAULT 'resident',
    -- status: see the comment above. Only 'active' accounts can sign in.
    status          ENUM('pending','active','inactive') NOT NULL DEFAULT 'pending',
    full_name       VARCHAR(100) NOT NULL,
    username        VARCHAR(30)  NOT NULL,
    -- email: stored in lower case and unique (see the keys below).
    email           VARCHAR(190) NOT NULL,
    -- password_hash: the bcrypt hash from PHP's password_hash(); NULL while the account is pending.
    password_hash   VARCHAR(255) NULL,
    -- apartment_id: the apartment this resident lives in (NULL for administrators).
    apartment_id    INT UNSIGNED NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,   -- raised on password change to sign out other sessions
    created_at      DATETIME NOT NULL,
    activated_at    DATETIME NULL,
    last_login_at   DATETIME NULL,
    -- Unique keys: no two users may share an email or a username.
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_apartment (apartment_id),
    KEY idx_users_role_status (role, status),
    -- Foreign key: apartment_id must point to an existing apartment.
    CONSTRAINT fk_users_apartment FOREIGN KEY (apartment_id) REFERENCES apartments (id),
    -- CHECK rules: a resident must have an apartment, and an active account must have a password.
    CONSTRAINT chk_users_resident_apartment CHECK (role = 'admin' OR apartment_id IS NOT NULL),
    CONSTRAINT chk_users_active_password CHECK (status <> 'active' OR password_hash IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gym zones. is_open = 0 closes the zone until an administrator reopens it.
CREATE TABLE IF NOT EXISTS zones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(60)  NOT NULL,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    -- capacity: how many people may use the zone at the same moment (1 to 500, see the CHECK below).
    capacity     SMALLINT UNSIGNED NOT NULL,
    is_open      TINYINT(1) NOT NULL DEFAULT 1,
    closed_note  VARCHAR(255) NULL,
    -- notice: a short message shown to residents while the zone stays open, for example
    -- "1 treadmill is out of order". NULL when there is no notice.
    notice       VARCHAR(255) NULL,
    -- sort_order: the order in which zones are shown.
    sort_order   SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_zones_name (name),
    CONSTRAINT chk_zones_capacity CHECK (capacity BETWEEN 1 AND 500)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Weekly opening hours. weekday follows ISO-8601: 1 = Monday ... 7 = Sunday. close_time may be 24:00:00.
-- The default is open around the clock (00:00–24:00 every day). Residents choose any start minute
-- and a duration; a session must fall inside the opening hours (it may continue past midnight).
CREATE TABLE IF NOT EXISTS operating_hours (
    -- weekday is the primary key, so there is exactly one row per day.
    weekday     TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    is_open     TINYINT(1) NOT NULL DEFAULT 1,
    open_time   TIME NOT NULL,
    close_time  TIME NOT NULL,
    CONSTRAINT chk_hours_weekday CHECK (weekday BETWEEN 1 AND 7),
    CONSTRAINT chk_hours_order CHECK (close_time > open_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planned closures for a period: the whole gym (zone_id NULL) or one zone.
CREATE TABLE IF NOT EXISTS closures (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- zone_id NULL means the whole gym is closed.
    zone_id     INT UNSIGNED NULL,
    starts_at   DATETIME NOT NULL,
    ends_at     DATETIME NOT NULL,
    reason      VARCHAR(255) NOT NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL,
    KEY idx_closures_period (starts_at, ends_at),
    -- If a zone is deleted, its closures are deleted too (CASCADE).
    CONSTRAINT fk_closures_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE CASCADE,
    CONSTRAINT fk_closures_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT chk_closures_order CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per session. start_at/end_at are the booked (planned) times, chosen to the minute.
-- Walk-ins are stored here too (source = 'walk_in', starting at the minute of check-in), so every
-- resident is counted exactly once.
-- status: booked = reserved, not yet arrived; checked_in = in the gym; completed = checked out;
--         cancelled = cancelled by the resident or an administrator;
--         released = not checked in before the check-in period ended, so the place was given back.
-- day_key makes "one session per zone per resident per day" a database rule: it is the date while the
-- booking counts, and NULL once it is cancelled or released (NULLs never clash in a unique index).
CREATE TABLE IF NOT EXISTS bookings (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- Who and where: the resident and the zone.
    user_id        INT UNSIGNED NOT NULL,
    zone_id        INT UNSIGNED NOT NULL,
    -- booking_date: the day the session starts; used by the one-per-day rule.
    booking_date   DATE NOT NULL,
    -- The planned start and end, to the minute.
    start_at       DATETIME NOT NULL,
    end_at         DATETIME NOT NULL,
    -- source: a normal booking or a walk-in. status: see the list above.
    source         ENUM('booking','walk_in') NOT NULL DEFAULT 'booking',
    status         ENUM('booked','checked_in','completed','cancelled','released') NOT NULL DEFAULT 'booked',
    created_at     DATETIME NOT NULL,
    -- Who cancelled, when and why (empty unless cancelled).
    cancelled_at   DATETIME NULL,
    cancelled_by   INT UNSIGNED NULL,
    cancel_reason  VARCHAR(255) NULL,
    -- day_key is calculated by the database. It equals booking_date while the booking counts, and is NULL
    -- when the booking is cancelled or released. STORED means it is saved, so it can be part of a unique key.
    day_key        DATE AS (CASE WHEN status IN ('booked','checked_in','completed') THEN booking_date END) STORED,
    -- THE ONE-PER-DAY RULE: no two rows may have the same user_id, zone_id and day_key. NULLs never clash,
    -- so a cancelled or released booking does not block a new one.
    UNIQUE KEY uq_bookings_one_zone_per_day (user_id, zone_id, day_key),
    -- Indexes make the common searches fast: by zone and time (capacity checks), by user, and by date.
    KEY idx_bookings_zone_period (zone_id, start_at, end_at, status),
    KEY idx_bookings_user_period (user_id, start_at),
    KEY idx_bookings_date (booking_date),
    -- RESTRICT: a user or zone that still has bookings cannot be deleted by accident.
    CONSTRAINT fk_bookings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_zone FOREIGN KEY (zone_id) REFERENCES zones (id) ON DELETE RESTRICT,
    CONSTRAINT fk_bookings_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users (id) ON DELETE SET NULL,
    -- A session must end after it starts.
    CONSTRAINT chk_bookings_order CHECK (end_at > start_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Actual check-in and check-out times for a booking or walk-in (may differ from the planned times).
-- auto_checked_out = 1 when nobody checked out within 6 hours; the resident is then asked for the real
-- time and checkout_reported_at records when they answered.
-- active_user enforces "one active check-in per resident" in the database.
CREATE TABLE IF NOT EXISTS attendance (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- booking_id: the booking (or walk-in) this visit belongs to. user_id: the resident.
    booking_id      INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    -- The real check-in and check-out times; checked_out_at is NULL while the resident is inside.
    checked_in_at   DATETIME NOT NULL,
    checked_out_at  DATETIME NULL,
    auto_checked_out      TINYINT(1) NOT NULL DEFAULT 0,
    checkout_reported_at  DATETIME NULL,
    -- active_user equals user_id while the resident is inside, and is NULL after check-out (calculated by the database).
    active_user     INT UNSIGNED AS (CASE WHEN checked_out_at IS NULL THEN user_id END) STORED,
    -- One attendance row per booking.
    UNIQUE KEY uq_attendance_booking (booking_id),
    -- THE ONE-ACTIVE-CHECK-IN RULE: two open visits of the same resident would have the same active_user,
    -- so the unique key refuses the second one.
    UNIQUE KEY uq_attendance_one_active (active_user),
    KEY idx_attendance_open (checked_out_at),
    CONSTRAINT fk_attendance_booking FOREIGN KEY (booking_id) REFERENCES bookings (id) ON DELETE RESTRICT,
    CONSTRAINT fk_attendance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    -- Check-out may not be before check-in.
    CONSTRAINT chk_attendance_order CHECK (checked_out_at IS NULL OR checked_out_at >= checked_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use, expiring links sent by email: account activation, email change and password change.
-- Only the SHA-256 hash of each token is stored. A new password waits here (already hashed) until confirmed.
CREATE TABLE IF NOT EXISTS verification_tokens (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    -- purpose: which kind of link this is.
    purpose            ENUM('activation','email_change','password_change') NOT NULL,
    -- token_hash: the SHA-256 hash of the token in the link (64 characters). The token itself is never stored.
    token_hash         CHAR(64) NOT NULL,
    -- For changes: the new email, or the new password's hash, waiting until the link is opened.
    new_email          VARCHAR(190) NULL,
    new_password_hash  VARCHAR(255) NULL,
    -- expires_at: when the link stops working. consumed_at: when it was used (NULL = not used yet).
    expires_at         DATETIME NOT NULL,
    consumed_at        DATETIME NULL,
    created_at         DATETIME NOT NULL,
    UNIQUE KEY uq_verification_token (token_hash),
    KEY idx_verification_user (user_id, purpose),
    -- If the user is deleted, their tokens are deleted too.
    CONSTRAINT fk_verification_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Forgotten-password links (single use, expiring, hashed).
CREATE TABLE IF NOT EXISTS password_resets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    token_hash    CHAR(64) NOT NULL,
    expires_at    DATETIME NOT NULL,
    consumed_at   DATETIME NULL,
    created_at    DATETIME NOT NULL,
    -- requested_ip: the IP address that asked for the reset.
    requested_ip  VARCHAR(45) NOT NULL,
    UNIQUE KEY uq_password_reset_token (token_hash),
    KEY idx_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attempt log used for rate limiting (sign-in, password reset requests, link checks, emails).
CREATE TABLE IF NOT EXISTS rate_limits (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- action: for example 'login'. subject: the email, IP address or user id. attempted_at: when.
    action        VARCHAR(30)  NOT NULL,
    subject       VARCHAR(190) NOT NULL,
    attempted_at  DATETIME NOT NULL,
    -- This index makes counting recent attempts fast.
    KEY idx_rate_limits_lookup (action, subject, attempted_at),
    KEY idx_rate_limits_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Messages residents send to the housing office from the Help page.
CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    -- user_id is NULL for messages sent from the sign-in page by someone who is not signed in.
    user_id     INT UNSIGNED NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(190) NOT NULL,
    apartment   VARCHAR(20)  NULL,
    subject     VARCHAR(120) NOT NULL,
    message     TEXT NOT NULL,
    created_at  DATETIME NOT NULL,
    -- handled_at and handled_by: when, and by whom, the office marked the message handled.
    handled_at  DATETIME NULL,
    handled_by  INT UNSIGNED NULL,
    KEY idx_contact_open (handled_at, created_at),
    CONSTRAINT fk_contact_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_contact_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking rules and contact details that administrators can change.
CREATE TABLE IF NOT EXISTS settings (
    -- name: for example checkin_grace_minutes. value: for example 15 (stored as text).
    name   VARCHAR(50)  NOT NULL PRIMARY KEY,
    value  VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
