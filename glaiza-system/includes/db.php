<?php
// ============================================================
// includes/db.php  — PDO database connection
// ============================================================

// Local development settings for XAMPP / WAMP / MAMP.
// Override with environment variables when needed.
$envDbHost = getenv('DB_HOST') ?: $_ENV['DB_HOST'] ?? null;
$envDbName = getenv('DB_NAME') ?: $_ENV['DB_NAME'] ?? null;
$envDbUser = getenv('DB_USER') ?: $_ENV['DB_USER'] ?? null;
$envDbPass = getenv('DB_PASS') ?: $_ENV['DB_PASS'] ?? null;

define('DB_HOST', $envDbHost ?: 'localhost');
define('DB_NAME', $envDbName ?: 'barangayglaiza');
define('DB_USER', $envDbUser ?: 'root');
define('DB_PASS', $envDbPass ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $candidateNames = array_values(array_unique([
            DB_NAME,
            'barangayglaiza',
        ]));

        $lastError = null;
        foreach ($candidateNames as $databaseName) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                $databaseName,
                DB_CHARSET
            );
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                $pdo->exec("SET time_zone = '+08:00'"); // Fixes MySQL-side current_timestamp() to PH time
                break;
            } catch (PDOException $e) {
                $lastError = $e;
                $pdo = null;
            }
        }

        if ($pdo === null) {
            http_response_code(500);
            die(json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . ($lastError ? $lastError->getMessage() : 'Unknown error')
            ]));
        }
    }
    return $pdo;
}

function ensureResidentSchema(): void {
    $checks = [
        'middle_name' => "ALTER TABLE residents ADD COLUMN middle_name VARCHAR(80) NULL AFTER first_name",
        'must_change_password' => "ALTER TABLE residents ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash",
    ];

    foreach ($checks as $column => $alterSql) {
        try {
            $exists = dbFetchOne("SHOW COLUMNS FROM residents LIKE '" . str_replace("'", "\\'", $column) . "' ");
            if (!$exists) {
                dbExecute($alterSql);
            }
        } catch (Throwable $e) {
            // Ignore migration failures; the app can continue without the new column.
        }
    }

    try {
        $idColumn = dbFetchOne("SHOW COLUMNS FROM residents LIKE 'id'");
        if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            dbExecute("ALTER TABLE residents MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        $primaryKey = dbFetchOne("SHOW KEYS FROM residents WHERE Key_name = 'PRIMARY' LIMIT 1");
        if (!$primaryKey) {
            dbExecute("ALTER TABLE residents ADD PRIMARY KEY (id)");
        }

        $zeroRow = dbFetchOne("SELECT id FROM residents WHERE id=0 LIMIT 1");
        if ($zeroRow) {
            dbExecute("DELETE FROM residents WHERE id=0");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the app will continue even if the table is still malformed.
    }
}

function ensureActivityLogSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'activity_log'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS activity_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_type ENUM('admin','resident') DEFAULT 'admin',
                user_id INT UNSIGNED DEFAULT NULL,
                action VARCHAR(200) NOT NULL,
                module VARCHAR(80) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM activity_log LIKE 'id'");
        if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            dbExecute("ALTER TABLE activity_log MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        $primaryKey = dbFetchOne("SHOW KEYS FROM activity_log WHERE Key_name = 'PRIMARY' LIMIT 1");
        if (!$primaryKey) {
            dbExecute("ALTER TABLE activity_log ADD PRIMARY KEY (id)");
        }

        $zeroRow = dbFetchOne("SELECT id FROM activity_log WHERE id=0 LIMIT 1");
        if ($zeroRow) {
            dbExecute("DELETE FROM activity_log WHERE id=0");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; a bad table will be repaired the next time the app loads.
    }
}

ensureResidentSchema();
ensureActivityLogSchema();
ensureAnnouncementSchema();
ensureDocumentRequestSchema();
ensureAppointmentSchema();
ensureIncidentReportSchema();
ensureDocumentTemplateSchema();
ensureAdminNotificationsSchema();
ensurePurokSchema();

function ensureAnnouncementSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'announcements'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS announcements (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(200) NOT NULL,
                description TEXT NOT NULL,
                category ENUM('Event','Service Update','Advisory','Important Notice','Other') DEFAULT 'Other',
                color VARCHAR(20) DEFAULT 'primary',
                publish_date DATE DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM announcements LIKE 'id'");
        if ($idColumn) {
            $extra = strtolower((string)($idColumn['Extra'] ?? ''));
            if (stripos($extra, 'auto_increment') === false) {
                dbExecute("ALTER TABLE announcements MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
            }

            $zeroRow = dbFetchOne("SELECT id FROM announcements WHERE id=0 LIMIT 1");
            if ($zeroRow) {
                dbExecute("DELETE FROM announcements WHERE id=0");
            }

            $maxId = (int) (dbFetchOne("SELECT MAX(id) AS max_id FROM announcements")['max_id'] ?? 0);
            $nextId = $maxId > 0 ? $maxId + 1 : 1;
            dbExecute("ALTER TABLE announcements AUTO_INCREMENT = $nextId");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the app will continue, but the announcement page may still need a manual DB repair if needed.
    }
}

function ensureDocumentRequestSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'document_requests'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS document_requests (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_number VARCHAR(20) NOT NULL,
                resident_id INT UNSIGNED NOT NULL,
                document_type ENUM('Barangay Clearance','Certificate of Residency','Barangay ID','Certificate of Indigency','Business Permit','Proof of Residency','Other') NOT NULL,
                purpose VARCHAR(250) DEFAULT NULL,
                status ENUM('Pending','Processing','Ready for Pickup','Completed','Rejected','Cancelled') DEFAULT 'Pending',
                requested_date DATE NOT NULL,
                due_date DATE DEFAULT NULL,
                completed_date DATE DEFAULT NULL,
                remarks TEXT DEFAULT NULL,
                processed_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY request_number (request_number),
                KEY resident_id (resident_id),
                KEY processed_by (processed_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM document_requests LIKE 'id'");
        if ($idColumn) {
            $extra = strtolower((string)($idColumn['Extra'] ?? ''));
            if (stripos($extra, 'auto_increment') === false) {
                dbExecute("ALTER TABLE document_requests MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
            }

            $maxId = (int) (dbFetchOne("SELECT MAX(id) AS max_id FROM document_requests")['max_id'] ?? 0);
            $nextId = $maxId > 0 ? $maxId + 1 : 1;
            dbExecute("ALTER TABLE document_requests AUTO_INCREMENT = $nextId");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the app will continue, but the request page may still need a manual DB repair if the schema is broken.
    }
}

function ensureAppointmentSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'appointments'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS appointments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                resident_id INT UNSIGNED NOT NULL,
                purpose VARCHAR(200) NOT NULL,
                preferred_date DATE NOT NULL,
                preferred_time VARCHAR(40) NOT NULL,
                notes TEXT DEFAULT NULL,
                status ENUM('Pending','Confirmed','Cancelled','Completed','Rescheduled') DEFAULT 'Pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY resident_id (resident_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM appointments LIKE 'id'");
        if ($idColumn) {
            $extra = strtolower((string)($idColumn['Extra'] ?? ''));
            if (stripos($extra, 'auto_increment') === false) {
                dbExecute("ALTER TABLE appointments MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
            }

            $primaryKey = dbFetchOne("SHOW KEYS FROM appointments WHERE Key_name = 'PRIMARY' LIMIT 1");
            if (!$primaryKey) {
                dbExecute("ALTER TABLE appointments ADD PRIMARY KEY (id)");
            }

            $zeroRow = dbFetchOne("SELECT id FROM appointments WHERE id=0 LIMIT 1");
            if ($zeroRow) {
                dbExecute("DELETE FROM appointments WHERE id=0");
            }

            $maxId = (int) (dbFetchOne("SELECT MAX(id) AS max_id FROM appointments")['max_id'] ?? 0);
            $nextId = $maxId > 0 ? $maxId + 1 : 1;
            dbExecute("ALTER TABLE appointments AUTO_INCREMENT = $nextId");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the app can continue, but appointment inserts may still fail until the table is repaired.
    }
}

function ensureIncidentReportSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'incident_reports'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS incident_reports (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                reference_no VARCHAR(20) NOT NULL,
                resident_id INT UNSIGNED NOT NULL,
                report_type ENUM('Incident','Complaint') NOT NULL DEFAULT 'Incident',
                category VARCHAR(60) NOT NULL,
                location VARCHAR(200) DEFAULT NULL,
                details TEXT NOT NULL,
                status ENUM('Pending','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'Pending',
                admin_notes TEXT DEFAULT NULL,
                handled_by INT UNSIGNED DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY reference_no (reference_no),
                KEY resident_id (resident_id),
                KEY handled_by (handled_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $columns = [
            'reference_no' => "VARCHAR(20) NOT NULL",
            'resident_id' => "INT UNSIGNED NOT NULL",
            'report_type' => "ENUM('Incident','Complaint') NOT NULL DEFAULT 'Incident'",
            'category' => "VARCHAR(60) NOT NULL",
            'location' => "VARCHAR(200) DEFAULT NULL",
            'details' => "TEXT NOT NULL",
            'status' => "ENUM('Pending','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'Pending'",
            'admin_notes' => "TEXT DEFAULT NULL",
            'handled_by' => "INT UNSIGNED DEFAULT NULL",
        ];

        foreach ($columns as $column => $definition) {
            $columnRow = dbFetchOne("SHOW COLUMNS FROM incident_reports LIKE '" . str_replace("'", "\\'", $column) . "' ");
            if (!$columnRow) {
                dbExecute("ALTER TABLE incident_reports ADD COLUMN $column $definition");
            }
        }

        $statusColumn = dbFetchOne("SHOW COLUMNS FROM incident_reports LIKE 'status'");
        if ($statusColumn) {
            $type = strtolower((string)($statusColumn['Type'] ?? ''));
            if (stripos($type, 'dismissed') === false) {
                dbExecute("ALTER TABLE incident_reports MODIFY status ENUM('Pending','Under Review','Resolved','Dismissed') NOT NULL DEFAULT 'Pending'");
            }
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM incident_reports LIKE 'id'");
        if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            dbExecute("ALTER TABLE incident_reports MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        $primaryKey = dbFetchOne("SHOW KEYS FROM incident_reports WHERE Key_name = 'PRIMARY' LIMIT 1");
        if (!$primaryKey) {
            dbExecute("ALTER TABLE incident_reports ADD PRIMARY KEY (id)");
        }

        $zeroRow = dbFetchOne("SELECT id FROM incident_reports WHERE id=0 LIMIT 1");
        if ($zeroRow) {
            dbExecute("DELETE FROM incident_reports WHERE id=0");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the report page will still try the safe insert paths below.
    }
}

function ensureDocumentTemplateSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'document_templates'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS document_templates (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                document_type VARCHAR(100) NOT NULL,
                image_path VARCHAR(255) DEFAULT NULL,
                field_positions LONGTEXT DEFAULT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY document_type_unique (document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $columns = [
            'document_type' => "VARCHAR(100) NOT NULL",
            'image_path' => "VARCHAR(255) DEFAULT NULL",
            'field_positions' => "LONGTEXT DEFAULT NULL",
            'updated_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];

        foreach ($columns as $column => $definition) {
            $columnRow = dbFetchOne("SHOW COLUMNS FROM document_templates LIKE '" . str_replace("'", "\\'", $column) . "' ");
            if (!$columnRow) {
                dbExecute("ALTER TABLE document_templates ADD COLUMN $column $definition");
            }
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM document_templates LIKE 'id'");
        if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            dbExecute("ALTER TABLE document_templates MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        $primaryKey = dbFetchOne("SHOW KEYS FROM document_templates WHERE Key_name = 'PRIMARY' LIMIT 1");
        if (!$primaryKey) {
            dbExecute("ALTER TABLE document_templates ADD PRIMARY KEY (id)");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the upload flow will still continue if the table is usable.
    }
}

function ensureAdminNotificationsSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'admin_notifications'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS admin_notifications (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                type VARCHAR(40) DEFAULT 'info',
                title VARCHAR(150) NOT NULL,
                message TEXT NOT NULL,
                link VARCHAR(255) DEFAULT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_notifications_unread (is_read, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $columns = [
            'type' => "VARCHAR(40) DEFAULT 'info'",
            'title' => "VARCHAR(150) NOT NULL",
            'message' => "TEXT NOT NULL",
            'link' => "VARCHAR(255) DEFAULT NULL",
            'is_read' => "TINYINT(1) NOT NULL DEFAULT 0",
            'created_at' => "DATETIME DEFAULT CURRENT_TIMESTAMP",
        ];

        foreach ($columns as $column => $definition) {
            $columnRow = dbFetchOne("SHOW COLUMNS FROM admin_notifications LIKE '" . str_replace("'", "\\'", $column) . "' ");
            if (!$columnRow) {
                dbExecute("ALTER TABLE admin_notifications ADD COLUMN $column $definition");
            }
        }

        $idColumn = dbFetchOne("SHOW COLUMNS FROM admin_notifications LIKE 'id'");
        if ($idColumn && stripos((string)($idColumn['Extra'] ?? ''), 'auto_increment') === false) {
            dbExecute("ALTER TABLE admin_notifications MODIFY id INT UNSIGNED NOT NULL AUTO_INCREMENT");
        }

        $primaryKey = dbFetchOne("SHOW KEYS FROM admin_notifications WHERE Key_name = 'PRIMARY' LIMIT 1");
        if (!$primaryKey) {
            dbExecute("ALTER TABLE admin_notifications ADD PRIMARY KEY (id)");
        }
    } catch (Throwable $e) {
        // Ignore migration failures; admin notifications are best-effort and should not block the app.
    }
}

function addAdminNotification(string $title, string $message, string $type = 'info', string $link = ''): void {
    try {
        dbInsert(
            "INSERT INTO admin_notifications (type, title, message, link, is_read) VALUES (?,?,?,?,0)",
            [trim($type) ?: 'info', trim($title) ?: 'New notification', trim($message) ?: 'New update', $link ?: null]
        );
    } catch (Throwable $e) {
        // Ignore notification insert failures so resident actions still succeed.
    }
}

function getAdminNotifications(int $limit = 6): array {
    try {
        return dbFetchAll(
            "SELECT * FROM admin_notifications ORDER BY created_at DESC LIMIT ?",
            [$limit]
        );
    } catch (Throwable $e) {
        return [];
    }
}

function getUnreadAdminNotificationCount(): int {
    try {
        $row = dbFetchOne("SELECT COUNT(*) AS c FROM admin_notifications WHERE is_read=0");
        return (int)($row['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
}

function ensurePurokSchema(): void {
    try {
        $tableExists = dbFetchOne("SHOW TABLES LIKE 'puroks'");
        if (!$tableExists) {
            dbExecute("CREATE TABLE IF NOT EXISTS puroks (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                type ENUM('zone','marker','emergency') NOT NULL DEFAULT 'zone',
                description VARCHAR(250) DEFAULT NULL,
                color VARCHAR(20) DEFAULT '#34d399',
                coordinates LONGTEXT DEFAULT NULL,
                resident_count INT UNSIGNED DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        $column = dbFetchOne("SHOW COLUMNS FROM puroks LIKE 'type'");
        if ($column) {
            $typeDefinition = (string)($column['Type'] ?? '');
            if (stripos($typeDefinition, 'emergency') === false) {
                dbExecute("ALTER TABLE puroks MODIFY type ENUM('zone','marker','emergency') NOT NULL DEFAULT 'zone'");
            }
        }
    } catch (Throwable $e) {
        // Ignore migration failures; the app will continue even if the table is still malformed.
    }
}

// ── Convenience wrappers ──────────────────────────────────────

/**
 * Run a query and return all matching rows.
 */
function dbFetchAll(string $sql, array $params = []): array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Run a query and return the first matching row (or null).
 */
function dbFetchOne(string $sql, array $params = []): ?array {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Run an INSERT / UPDATE / DELETE and return affected row count.
 */
function dbExecute(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Run an INSERT and return the new row's ID.
 */
function dbInsert(string $sql, array $params = []): int {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return (int) getDB()->lastInsertId();
}

// ── JSON response helpers ─────────────────────────────────────

function jsonOk(array $data = [], string $message = 'Success'): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

function jsonError(string $message = 'An error occurred', int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ── Session helpers ────────────────────────────────────────────
// Both helpers now fall back to the "remember me" cookie (via
// attemptAutoLogin(), defined in remember_me.php) before giving up
// and bouncing the user to the login page. Previously the cookie
// and remember_tokens row were written on login but never read
// back anywhere, so "remember me" had no actual effect.

function requireAdminLogin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_id']) && !attemptAutoLogin('admin')) {
        header('Location: admin_login.php');
        exit;
    }
}

function requireResidentLogin(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();

    if (empty($_SESSION['resident_id'])) {
        if (!attemptAutoLogin('resident')) {
            header('Location: index.php');
            exit;
        }
    }

    if (!empty($_SESSION['resident_id'])) {
        $resident = dbFetchOne("SELECT id, status FROM residents WHERE id=? LIMIT 1", [$_SESSION['resident_id']]);
        if (!$resident) {
            session_unset();
            session_destroy();
            $_SESSION = [];
            header('Location: index.php');
            exit;
        }

        if ($resident['status'] === 'Pending') {
            header('Location: pending_verification.php');
            exit;
        }

        if ($resident['status'] !== 'Verified') {
            session_unset();
            session_destroy();
            $_SESSION = [];
            header('Location: index.php?login_status=pending');
            exit;
        }
    }
}

function logActivity(string $action, string $module = '', string $userType = 'admin'): void {
    $userId = $userType === 'admin'
        ? ($_SESSION['admin_id'] ?? null)
        : ($_SESSION['resident_id'] ?? null);
    dbExecute(
        'INSERT INTO activity_log (user_type, user_id, action, module) VALUES (?,?,?,?)',
        [$userType, $userId, $action, $module]
    );
}

function isHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }
    return false;
}

function getClientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

function ensureLoginAttemptsTable(): void {
    dbExecute("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_type VARCHAR(20) NOT NULL,
        username VARCHAR(180) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        locked_until DATETIME NULL,
        last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_lookup (user_type, username, ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rateLimitLogin(string $username, string $userType, int $maxAttempts = 5, int $lockMinutes = 15): bool {
    ensureLoginAttemptsTable();

    $safeUsername = strtolower(trim($username));
    $ip = getClientIp();
    $now = new DateTimeImmutable();
    $row = dbFetchOne(
        'SELECT * FROM login_attempts WHERE user_type=? AND username=? AND ip_address=? LIMIT 1',
        [$userType, $safeUsername, $ip]
    );

    if (!$row) {
        return true;
    }

    if (!empty($row['locked_until']) && new DateTime($row['locked_until']) > $now) {
        return false;
    }

    if (!empty($row['locked_until']) && new DateTime($row['locked_until']) <= $now) {
        dbExecute(
            'UPDATE login_attempts SET attempts=0, locked_until=NULL, last_attempt_at=? WHERE id=?',
            [$now->format('Y-m-d H:i:s'), $row['id']]
        );
    }

    $lastAttempt = new DateTime($row['last_attempt_at'] ?? $now->format('Y-m-d H:i:s'));
    if ($lastAttempt->getTimestamp() < $now->getTimestamp() - 900) {
        dbExecute(
            'UPDATE login_attempts SET attempts=0, locked_until=NULL, last_attempt_at=? WHERE id=?',
            [$now->format('Y-m-d H:i:s'), $row['id']]
        );
    }

    $attempts = (int) ($row['attempts'] ?? 0);
    return $attempts < $maxAttempts;
}

function recordFailedLogin(string $username, string $userType, int $maxAttempts = 5, int $lockMinutes = 15): void {
    ensureLoginAttemptsTable();

    $safeUsername = strtolower(trim($username));
    $ip = getClientIp();
    $now = new DateTimeImmutable();
    $row = dbFetchOne(
        'SELECT * FROM login_attempts WHERE user_type=? AND username=? AND ip_address=? LIMIT 1',
        [$userType, $safeUsername, $ip]
    );

    if ($row) {
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $lockedUntil = null;

        if ($attempts >= $maxAttempts) {
            $lockedUntil = $now->modify('+' . $lockMinutes . ' minutes')->format('Y-m-d H:i:s');
        }

        dbExecute(
            'UPDATE login_attempts SET attempts=?, locked_until=?, last_attempt_at=? WHERE id=?',
            [$attempts, $lockedUntil, $now->format('Y-m-d H:i:s'), $row['id']]
        );
        return;
    }

    dbExecute(
        'INSERT INTO login_attempts (user_type, username, ip_address, attempts, locked_until, last_attempt_at) VALUES (?,?,?,?,?,?)',
        [$userType, $safeUsername, $ip, 1, null, $now->format('Y-m-d H:i:s')]
    );
}

function recordSuccessfulLogin(string $username, string $userType): void {
    ensureLoginAttemptsTable();
    $safeUsername = strtolower(trim($username));
    $ip = getClientIp();
    dbExecute(
        'DELETE FROM login_attempts WHERE user_type=? AND username=? AND ip_address=?',
        [$userType, $safeUsername, $ip]
    );
}

// ── Resident ID generator ─────────────────────────────────────

function generateResidentId(): string {
    $pdo = getDB();
    // Lock the row, increment sequence atomically
    $pdo->beginTransaction();
    try {
        $year = (int) dbFetchOne(
            "SELECT setting_value FROM barangay_settings WHERE setting_key='resident_id_year'"
        )['setting_value'];
        $seq  = (int) dbFetchOne(
            "SELECT setting_value FROM barangay_settings WHERE setting_key='resident_id_seq'"
        )['setting_value'];

        $currentYear = (int) date('Y');
        if ($year !== $currentYear) {
            $seq  = 0;
            $year = $currentYear;
            dbExecute(
                "UPDATE barangay_settings SET setting_value=? WHERE setting_key='resident_id_year'",
                [$year]
            );
        }
        $seq++;
        dbExecute(
            "UPDATE barangay_settings SET setting_value=? WHERE setting_key='resident_id_seq'",
            [$seq]
        );
        $pdo->commit();
        return sprintf('BRGY-%d-%04d', $year, $seq);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Request number generator ──────────────────────────────────

function generateRequestNumber(): string {
    $year  = date('Y');
    $count = (int) (dbFetchOne(
        "SELECT COUNT(*) AS cnt FROM document_requests WHERE YEAR(created_at)=?",
        [$year]
    )['cnt'] ?? 0);
    return sprintf('REQ-%s-%04d', $year, $count + 1);
}
// Auto-load site settings after DB is available
if (file_exists(__DIR__ . '/site_settings.php')) {
    require_once __DIR__ . '/site_settings.php';
    require_once __DIR__ . '/remember_me.php';
}
if (file_exists(__DIR__ . '/manual_reset.php')) {
    require_once __DIR__ . '/manual_reset.php';
}