<?php
/**
 * Program Duplication Helpers
 * Functions for copying programs (plans, classes, events, rooms) between schools.
 */

/**
 * Copy a membership plan to another school.
 * @return int|false New plan ID or false if already exists / not found
 */
function copy_membership_plan(int $planId, int $targetSchoolId): int|false
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM membership_plans WHERE id = ?");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) return false;

    // Skip if a plan with the same name already exists in the target school
    $chk = $pdo->prepare("SELECT id FROM membership_plans WHERE school_id = ? AND name = ?");
    $chk->execute([$targetSchoolId, $plan['name']]);
    if ($chk->fetchColumn()) return false;

    $ins = $pdo->prepare("
        INSERT INTO membership_plans
        (school_id, name, description, duration_months, price, classes_per_week,
         status, billing_frequency, registration_fee, tax_deductible,
         is_afterschool, program_start_date, program_end_date, is_grandfathered)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $targetSchoolId,
        $plan['name'],
        $plan['description'],
        $plan['duration_months'],
        $plan['price'],
        $plan['classes_per_week'],
        $plan['status'],
        $plan['billing_frequency'] ?? 'upfront',
        $plan['registration_fee'] ?? 0,
        $plan['tax_deductible'] ?? 0,
        $plan['is_afterschool'] ?? 0,
        $plan['program_start_date'],
        $plan['program_end_date'],
        $plan['is_grandfathered'] ?? 0
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Copy a class to another school.
 * instructor_id and room_id are set to NULL (school-specific resources).
 * style_id is preserved (global table).
 * @return int|false New class ID or false if already exists / not found
 */
function copy_class_to_school(int $classId, int $targetSchoolId): int|false
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM classes WHERE id = ?");
    $stmt->execute([$classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) return false;

    // Skip if class with same name + day already exists in target school
    $chk = $pdo->prepare("SELECT id FROM classes WHERE school_id = ? AND name = ? AND day_of_week = ?");
    $chk->execute([$targetSchoolId, $class['name'], $class['day_of_week']]);
    if ($chk->fetchColumn()) return false;

    $ins = $pdo->prepare("
        INSERT INTO classes
        (school_id, name, style_id, instructor_id, room_id, day_of_week,
         start_time, end_time, max_students, skill_level, description, status)
        VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $targetSchoolId,
        $class['name'],
        $class['style_id'],
        $class['day_of_week'],
        $class['start_time'],
        $class['end_time'],
        $class['max_students'],
        $class['skill_level'],
        $class['description'],
        $class['status']
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Copy an event to another school.
 * instructor_id is set to NULL (school-specific resource).
 * @return int|false New event ID or false if already exists / not found
 */
function copy_event_to_school(int $eventId, int $targetSchoolId): int|false
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$eventId]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$event) return false;

    // Skip if event with same name + date already exists in target school
    $chk = $pdo->prepare("SELECT id FROM events WHERE school_id = ? AND name = ? AND event_date = ?");
    $chk->execute([$targetSchoolId, $event['name'], $event['event_date']]);
    if ($chk->fetchColumn()) return false;

    $ins = $pdo->prepare("
        INSERT INTO events
        (school_id, name, event_type, description, event_date, start_time, end_time,
         location, max_participants, registration_fee, registration_deadline,
         instructor_id, status, requirements, requires_registration, tax_deductible)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)
    ");
    $ins->execute([
        $targetSchoolId,
        $event['name'],
        $event['event_type'],
        $event['description'],
        $event['event_date'],
        $event['start_time'],
        $event['end_time'],
        $event['location'],
        $event['max_participants'],
        $event['registration_fee'],
        $event['registration_deadline'],
        $event['status'],
        $event['requirements'],
        $event['requires_registration'] ?? 1,
        $event['tax_deductible'] ?? 0
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Copy a room to another school.
 * @return int|false New room ID or false if already exists / not found
 */
function copy_room_to_school(int $roomId, int $targetSchoolId): int|false
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$room) return false;

    // Skip if room with same name already exists in target school
    $chk = $pdo->prepare("SELECT id FROM rooms WHERE school_id = ? AND name = ?");
    $chk->execute([$targetSchoolId, $room['name']]);
    if ($chk->fetchColumn()) return false;

    // Handle both schema variants (with/without capacity+description columns)
    try {
        $ins = $pdo->prepare("
            INSERT INTO rooms (school_id, name, capacity, description, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        $ins->execute([
            $targetSchoolId,
            $room['name'],
            $room['capacity'] ?? 20,
            $room['description'] ?? null,
            $room['status'] ?? 'active'
        ]);
    } catch (\PDOException $e) {
        // Fallback: schema without capacity/description columns
        $ins = $pdo->prepare("
            INSERT INTO rooms (school_id, name, status)
            VALUES (?, ?, ?)
        ");
        $ins->execute([
            $targetSchoolId,
            $room['name'],
            $room['status'] ?? 'active'
        ]);
    }
    return (int)$pdo->lastInsertId();
}

/**
 * Get item name for display in results.
 */
function get_program_item_name(string $type, int $id): string
{
    $pdo = get_db();
    switch ($type) {
        case 'plans':
            $stmt = $pdo->prepare("SELECT name FROM membership_plans WHERE id = ?");
            break;
        case 'classes':
            $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
            break;
        case 'events':
            $stmt = $pdo->prepare("SELECT name FROM events WHERE id = ?");
            break;
        case 'rooms':
            $stmt = $pdo->prepare("SELECT name FROM rooms WHERE id = ?");
            break;
        default:
            return "Item #$id";
    }
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: "Item #$id";
}
