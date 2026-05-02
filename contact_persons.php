<?php

function add_contact_person($conn, $contractor_id, $first_name, $last_name = null, $status = 0, $current_user_id = null, $timestamp = null, $contact_no = null) {
    if (!$timestamp) $timestamp = date('Y-m-d H:i:s');

    // If contact_no column exists, include it in the insert, otherwise fall back to legacy insert
    $has_contact_no = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'contact_no'")->num_rows > 0;
    $status_val = $status ?: 0; // Default to 0 if not specified

    if ($has_contact_no) {
        $stmt = $conn->prepare("INSERT INTO contact_persons (contractor_id, first_name, last_name, status, contact_no, user_id, saved_by, saved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param('issisiss', $contractor_id, $first_name, $last_name, $status_val, $contact_no, $current_user_id, $current_user_id, $timestamp);
    } else {
        $stmt = $conn->prepare("INSERT INTO contact_persons (contractor_id, first_name, last_name, status, user_id, saved_by, saved_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param('issiiis', $contractor_id, $first_name, $last_name, $status_val, $current_user_id, $current_user_id, $timestamp);
    }

    $res = $stmt->execute();
    if ($res) {
        $cp_id = $stmt->insert_id;
    }
    $stmt->close();

    // If insert succeeded, update contractors table primary/secondary contact fields for the same company
    if ($res) {
        $full_name = trim(($first_name ?? '') . ' ' . ($last_name ?? '')) ?: null;
        if ($full_name !== null) {
            // Get company identifier for this contractor row
            $cstmt = $conn->prepare("SELECT company_name FROM contractors WHERE id = ? LIMIT 1");
            if ($cstmt) {
                $cstmt->bind_param('i', $contractor_id);
                $cstmt->execute();
                $cres = $cstmt->get_result();
                $crow = $cres ? $cres->fetch_assoc() : null;
                $cstmt->close();

                if ($crow) {
                    $company_name = $crow['company_name'];

                    // Determine whether the company already has a contact_person set
                    $hasCp = false;
                    $chk = $conn->prepare("SELECT contact_person FROM contractors WHERE company_name = ? LIMIT 1");
                    if ($chk) {
                        $chk->bind_param('s', $company_name);
                        $chk->execute();
                        $cres2 = $chk->get_result();
                        $crow2 = $cres2 ? $cres2->fetch_assoc() : null;
                        $chk->close();
                        if ($crow2 && !empty($crow2['contact_person'])) $hasCp = true;
                    }

                    if ($status_val == 0) { // Only update for active records
                        // If no primary/first contact exists yet, treat this as first (contact_person)
                        if (!$hasCp) {
                            $upd = $conn->prepare("UPDATE contractors SET contact_person = ? WHERE company_name = ?");
                            if ($upd) {
                                $upd->bind_param('ss', $full_name, $company_name);
                                $upd->execute();
                                $upd->close();
                            }
                        } else {
                            // Otherwise set as secondary
                            $upd = $conn->prepare("UPDATE contractors SET contact_person2 = ? WHERE company_name = ?");
                            if ($upd) {
                                $upd->bind_param('ss', $full_name, $company_name);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
            }
        }
    }

    return $res;
}

function get_contact_persons($conn, $contractor_id) {
    $stmt = $conn->prepare("SELECT * FROM contact_persons WHERE contractor_id = ? AND status = 0 ORDER BY id ASC");
    if (!$stmt) return [];
    $stmt->bind_param('i', $contractor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $stmt->close();
    return $rows;
}

function delete_contact_person($conn, $contact_id, $current_user_id = null, $timestamp = null) {
    if (!$timestamp) $timestamp = date('Y-m-d H:i:s');
    
    // Fetch existing contact person details so we can clear contractor fields if needed
    $row_stmt = $conn->prepare("SELECT contractor_id, first_name, last_name FROM contact_persons WHERE id = ? AND status = 0 LIMIT 1");
    if (!$row_stmt) return false;
    $row_stmt->bind_param('i', $contact_id);
    $row_stmt->execute();
    $row_res = $row_stmt->get_result();
    $row = $row_res ? $row_res->fetch_assoc() : null;
    $row_stmt->close();

    // Always update status to 3 (deleted) and set deleted_at/deleted_by
    $stmt = $conn->prepare("UPDATE contact_persons SET status = 3, deleted_at = ?, deleted_by = ? WHERE id = ?");
    if (!$stmt) return false;
    
    if ($current_user_id === null) {
        // If no user ID provided, set to NULL
        $stmt->bind_param('sii', $timestamp, $current_user_id, $contact_id);
    } else {
        $stmt->bind_param('sii', $timestamp, $current_user_id, $contact_id);
    }
    
    $res = $stmt->execute();
    $stmt->close();

    // If deleted successfully and we have the original row, clear contractor contact fields for the same company if they match
    if ($res && $row) {
        // Recompute remaining (active) contact persons for this contractor
        $contractor_id = (int)$row['contractor_id'];

        $cp_stmt = $conn->prepare("SELECT first_name, last_name FROM contact_persons WHERE contractor_id = ? AND status = 0 ORDER BY id ASC");
        if ($cp_stmt) {
            $cp_stmt->bind_param('i', $contractor_id);
            $cp_stmt->execute();
            $cp_res = $cp_stmt->get_result();
            $names = [];
            while ($cp_row = $cp_res->fetch_assoc()) {
                $fullname = trim(($cp_row['first_name'] ?? '') . ' ' . ($cp_row['last_name'] ?? '')) ?: null;
                if ($fullname) $names[] = $fullname;
            }
            $cp_stmt->close();

            // determine company_name for this contractor id
            $cstmt = $conn->prepare("SELECT company_name FROM contractors WHERE id = ? LIMIT 1");
            if ($cstmt) {
                $cstmt->bind_param('i', $contractor_id);
                $cstmt->execute();
                $cres = $cstmt->get_result();
                $crow = $cres ? $cres->fetch_assoc() : null;
                $cstmt->close();

                if ($crow) {
                    $company_name = $crow['company_name'];

                    // Prepare update to set contact_person/contact_person2 based on remaining names
                    $new_primary = $names[0] ?? null;
                    $new_secondary = $names[1] ?? null;

                    $upd = $conn->prepare("UPDATE contractors SET contact_person = ?, contact_person2 = ? WHERE company_name = ?");
                    if ($upd) {
                        $upd->bind_param('sss', $new_primary, $new_secondary, $company_name);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }
        }
    }

    return $res;
}

// Ensure table exists on include
if (isset($conn) && $conn instanceof mysqli) {
    // Best-effort: add `contact_no` column to `contact_persons` if it doesn't exist yet.
    // This allows the app to store numbers entered via the Contact Persons UI.
    try {
        $has_contact_no_col = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'contact_no'")->num_rows > 0;
        if (!$has_contact_no_col) {
            $conn->query("ALTER TABLE contact_persons ADD COLUMN contact_no varchar(20) DEFAULT NULL AFTER last_name");
        }
        
        // Check if is_deleted column exists and rename it to status
        $has_is_deleted = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'is_deleted'")->num_rows > 0;
        if ($has_is_deleted) {
            $conn->query("ALTER TABLE contact_persons CHANGE is_deleted status TINYINT(1) DEFAULT 0");
            // Update existing data: 1 → 3 for deleted records
            $conn->query("UPDATE contact_persons SET status = 3 WHERE status = 1");
        }
        
        // Ensure status column has the correct comment
        $conn->query("ALTER TABLE contact_persons MODIFY COLUMN status TINYINT(1) DEFAULT 0 COMMENT '0=Active/Default, 1=Updated, 3=Deleted'");
    } catch (Exception $e) {
        // ignore - some environments may not permit ALTER in runtime
        error_log("Database migration error: " . $e->getMessage());
    }
    
    if (function_exists('ensure_contact_persons_table')) {
        ensure_contact_persons_table($conn);
    }
    
    // Sync existing contact_persons into contractors.contact_person and contact_person2
    if (!function_exists('sync_contractors_contact_persons')) {
        function sync_contractors_contact_persons($conn) {
            // Fetch active contact persons (status = 0) ordered by insertion order
            $sql = "SELECT contractor_id, first_name, last_name FROM contact_persons WHERE status = 0 ORDER BY contractor_id, id ASC";
            $res = $conn->query($sql);
            if (!$res) return false;

            $groups = [];
            while ($row = $res->fetch_assoc()) {
                $cid = (int)$row['contractor_id'];
                $fullname = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: null;
                if (!isset($groups[$cid])) $groups[$cid] = [];
                if ($fullname) $groups[$cid][] = $fullname;
            }

            // Prepare update statement to update all contractor rows for the same company (by company_name)
            $upd = $conn->prepare("UPDATE contractors SET contact_person = ?, contact_person2 = ? WHERE company_name = ?");
            if (!$upd) return false;

            foreach ($groups as $cid => $names) {
                // determine company_name for this contractor id
                $cstmt = $conn->prepare("SELECT company_name FROM contractors WHERE id = ? LIMIT 1");
                if (!$cstmt) continue;
                $cstmt->bind_param('i', $cid);
                $cstmt->execute();
                $cres = $cstmt->get_result();
                $crow = $cres ? $cres->fetch_assoc() : null;
                $cstmt->close();

                if (!$crow) continue;

                $company_name = $crow['company_name'];

                $n1 = $names[0] ?? null;
                $n2 = $names[1] ?? null;
                $upd->bind_param('sss', $n1, $n2, $company_name);
                $upd->execute();
            }

            $upd->close();
            return true;
        }
    }
    
    // Run sync once on include
    @sync_contractors_contact_persons($conn);
}

function update_contact_person($conn, $contact_id, $first_name, $last_name = null, $contact_no = null, $status = 0, $current_user_id = null, $timestamp = null) {
    if (!$timestamp) $timestamp = date('Y-m-d H:i:s');

    // Check whether contact_no column exists
    $has_contact_no = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'contact_no'")->num_rows > 0;
    $status_val = $status ?: 0;

    if ($has_contact_no) {
        $stmt = $conn->prepare("UPDATE contact_persons SET first_name = ?, last_name = ?, contact_no = ?, status = ?, user_id = ?, saved_by = ?, saved_at = ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ssisiisi', $first_name, $last_name, $contact_no, $status_val, $current_user_id, $current_user_id, $timestamp, $contact_id);
    } else {
        $stmt = $conn->prepare("UPDATE contact_persons SET first_name = ?, last_name = ?, status = ?, user_id = ?, saved_by = ?, saved_at = ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('ssiiisi', $first_name, $last_name, $status_val, $current_user_id, $current_user_id, $timestamp, $contact_id);
    }

    $res = $stmt->execute();
    $stmt->close();

    // After update: do not sync into contractors table; contact persons are stored only in contact_persons.

    return $res;
}

function ensure_contact_persons_table($conn) {
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'contact_persons'");
    if ($table_check && $table_check->num_rows > 0) {
        // Table exists, ensure it has the status column
        $col_check = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'status'");
        if (!$col_check || $col_check->num_rows == 0) {
            // Check if is_deleted exists
            $has_is_deleted = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'is_deleted'")->num_rows > 0;
            if ($has_is_deleted) {
                $conn->query("ALTER TABLE contact_persons CHANGE is_deleted status TINYINT(1) DEFAULT 0");
                // Update existing data: 1 → 3 for deleted records
                $conn->query("UPDATE contact_persons SET status = 3 WHERE status = 1");
            } else {
                $conn->query("ALTER TABLE contact_persons ADD COLUMN status TINYINT(1) DEFAULT 0 COMMENT '0=Active/Default, 1=Updated, 3=Deleted'");
            }
        }
        return true;
    }
    
    // Create table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS contact_persons (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        contractor_id INT(11) NOT NULL,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) DEFAULT NULL,
        contact_no VARCHAR(20) DEFAULT NULL,
        status TINYINT(1) DEFAULT 0 COMMENT '0=Active/Default, 1=Updated, 3=Deleted',
        user_id INT(11) DEFAULT NULL,
        saved_by INT(11) DEFAULT NULL,
        saved_at DATETIME DEFAULT NULL,
        deleted_by INT(11) DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL,
        INDEX idx_contractor_id (contractor_id),
        INDEX idx_status (status),
        INDEX idx_contact_no (contact_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    return $conn->query($sql);
}

// Helper function to get all contact persons (for display purposes)
function get_all_contact_persons($conn) {
    $sql = "SELECT cp.*, c.company_name FROM contact_persons cp 
            LEFT JOIN contractors c ON cp.contractor_id = c.id 
            WHERE cp.status = 0 
            ORDER BY c.company_name, cp.id ASC";
    $result = $conn->query($sql);
    $contact_persons = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $contact_persons[] = $row;
        }
    }
    return $contact_persons;
}

// Helper function to mark contact person as updated (status = 1)
function mark_contact_person_updated($conn, $contact_id, $current_user_id = null, $timestamp = null) {
    if (!$timestamp) $timestamp = date('Y-m-d H:i:s');
    
    $has_deleted_cols = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'deleted_by'")->num_rows > 0;
    if ($has_deleted_cols) {
        $stmt = $conn->prepare("UPDATE contact_persons SET status = 1, deleted_by = ?, deleted_at = ? WHERE id = ?");
        $stmt->bind_param('isi', $current_user_id, $timestamp, $contact_id);
    } else {
        $stmt = $conn->prepare("UPDATE contact_persons SET status = 1 WHERE id = ?");
        $stmt->bind_param('i', $contact_id);
    }
    
    if (!$stmt) return false;
    $res = $stmt->execute();
    $stmt->close();
    return $res;
}
?>