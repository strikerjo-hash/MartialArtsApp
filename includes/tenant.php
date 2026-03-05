<?php
/**
 * includes/tenant.php — Multi-tenancy context layer.
 *
 * Provides the current school_id for query scoping.
 * Loaded by config.php after auth.php and session start.
 *
 * Usage in queries:
 *   $params = [];
 *   $sql = "SELECT * FROM students WHERE status = 'active'" . school_where();
 *   school_param($params);
 *   $stmt = $pdo->prepare($sql);
 *   $stmt->execute($params);
 *
 *   // For INSERTs:
 *   $stmt = $pdo->prepare("INSERT INTO students (school_id, name) VALUES (?, ?)");
 *   $stmt->execute([current_school_id(), $name]);
 */

/**
 * Get the school_id for the current session.
 *
 * Priority:
 *   1. Super admin with active school switch → $_SESSION['active_school_id']
 *   2. User's own school → $_SESSION['school_id']
 *   3. Fallback → 1 (default school)
 */
function current_school_id(): int
{
    if (!empty($_SESSION['active_school_id'])) {
        return (int) $_SESSION['active_school_id'];
    }
    if (!empty($_SESSION['school_id'])) {
        return (int) $_SESSION['school_id'];
    }
    return 1;
}

/**
 * Check if the current user is a super admin.
 */
function is_super_admin(): bool
{
    return ($_SESSION['role'] ?? '') === 'super_admin';
}

/**
 * Check if the current user should see ALL schools' data
 * (super admin in "All Schools" mode, not switched into a specific school).
 */
function is_viewing_all_schools(): bool
{
    return is_super_admin() && empty($_SESSION['active_school_id']);
}

/**
 * Get a list of all active schools.
 */
function get_all_schools(): array
{
    try {
        $pdo = get_db();
        $stmt = $pdo->query("SELECT id, name, slug, status FROM schools ORDER BY name");
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Get the school record for the current context.
 */
function get_current_school(): array
{
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT * FROM schools WHERE id = ? LIMIT 1");
        $stmt->execute([current_school_id()]);
        return $stmt->fetch() ?: ['id' => 1, 'name' => 'Default School', 'slug' => 'default'];
    } catch (\PDOException $e) {
        return ['id' => 1, 'name' => 'Default School', 'slug' => 'default'];
    }
}

/**
 * Switch the super admin's active school context.
 * Pass null or 0 to switch to "All Schools" mode.
 */
function switch_school(?int $schoolId): void
{
    if (!is_super_admin()) {
        return;
    }
    if ($schoolId === null || $schoolId === 0) {
        unset($_SESSION['active_school_id']);
    } else {
        $_SESSION['active_school_id'] = $schoolId;
    }
}

// ────────────────────────────────────────────────────────────
// Query scoping helpers
// ────────────────────────────────────────────────────────────

/**
 * Return an SQL fragment like " AND s.school_id = ?" to append to a WHERE clause.
 * Returns "" if the super admin is viewing all schools.
 *
 * @param string $alias  Table alias (e.g. 's' for students). Empty string = no alias.
 * @param string $conjunction  Usually 'AND'; pass 'WHERE' if this is the first condition.
 */
function school_where(string $alias = '', string $conjunction = 'AND'): string
{
    if (is_viewing_all_schools()) {
        return '';
    }
    $col = $alias ? "{$alias}.school_id" : 'school_id';
    return " {$conjunction} {$col} = ?";
}

/**
 * Append the current school_id to a params array.
 * Skips if super admin is viewing all schools (no param needed).
 */
function school_param(array &$params): void
{
    if (!is_viewing_all_schools()) {
        $params[] = current_school_id();
    }
}

/**
 * Convenience: get a WHERE clause that starts with WHERE.
 * Returns "WHERE school_id = ?" or "WHERE 1=1" for super admin all-view.
 */
function school_where_clause(string $alias = ''): string
{
    if (is_viewing_all_schools()) {
        return 'WHERE 1=1';
    }
    $col = $alias ? "{$alias}.school_id" : 'school_id';
    return "WHERE {$col} = ?";
}

// ────────────────────────────────────────────────────────────
// Multi-school user helpers (user_schools junction table)
// ────────────────────────────────────────────────────────────

/**
 * SQL fragment to filter users by the user_schools junction table.
 * Use instead of school_where() when querying the users table.
 *
 * @param string $alias  Table alias for users (e.g. 'u'). Empty = no alias.
 * @param string $conjunction  'AND' or 'WHERE'.
 */
function user_school_where(string $alias = '', string $conjunction = 'AND'): string
{
    if (is_viewing_all_schools()) {
        return '';
    }
    $col = $alias ? "{$alias}.id" : 'id';
    return " {$conjunction} {$col} IN (SELECT user_id FROM user_schools WHERE school_id = ?)";
}

/**
 * Append the current school_id param for user_school_where().
 */
function user_school_param(array &$params): void
{
    if (!is_viewing_all_schools()) {
        $params[] = current_school_id();
    }
}

/**
 * Get all school IDs assigned to a user.
 */
function get_user_schools(int $userId): array
{
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT school_id FROM user_schools WHERE user_id = ? ORDER BY school_id");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    } catch (\PDOException $e) {
        return [];
    }
}

// ────────────────────────────────────────────────────────────
// Timezone helper
// ────────────────────────────────────────────────────────────

/**
 * Get the timezone string for the current school.
 * Result is cached for the lifetime of the request.
 */
function get_school_timezone(): string
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT timezone FROM schools WHERE id = ? LIMIT 1");
        $stmt->execute([current_school_id()]);
        $tz = $stmt->fetchColumn() ?: 'America/New_York';
    } catch (\PDOException $e) {
        $tz = 'America/New_York';
    }
    return $tz;
}
