<?php
session_start();

// Set Sri Lanka timezone (UTC+5:30)
date_default_timezone_set('Asia/Colombo');

require_once 'db_config.php';
require_once 'auth.php';
require_once 'contact_persons.php';

// Use centralized current user id variable for consistency
$current_user_id = $currentUserId; // legacy alias for older code using $current_user_id

$conn = get_db_connection();

// Ensure contact_persons table exists (contact_persons.php defines the function)
// Ensure `contact_no` column exists on `contact_persons` so contact numbers can be saved from the UI.
// This is a best-effort runtime migration; if your DB user cannot ALTER, run the provided SQL manually.
try {
    $res = $conn->query("SHOW COLUMNS FROM contact_persons LIKE 'contact_no'");
    if (!$res || $res->num_rows == 0) {
        $conn->query("ALTER TABLE contact_persons ADD COLUMN contact_no varchar(20) DEFAULT NULL AFTER last_name");
    }
} catch (Exception $e) {
    // ignore - some environments disallow ALTER; user can run manual SQL
}

// Best-effort: add `contact_no` / `contact_no2` to `contractors` if missing so existing INSERTs/UPDATES don't fail
try {
    $r1 = $conn->query("SHOW COLUMNS FROM contractors LIKE 'contact_no'");
    if (!$r1 || $r1->num_rows == 0) {
        $conn->query("ALTER TABLE contractors ADD COLUMN contact_no varchar(20) DEFAULT NULL AFTER contact_person");
    }
    $r2 = $conn->query("SHOW COLUMNS FROM contractors LIKE 'contact_no2'");
    if (!$r2 || $r2->num_rows == 0) {
        $conn->query("ALTER TABLE contractors ADD COLUMN contact_no2 varchar(20) DEFAULT NULL AFTER contact_no");
    }
    // Ensure `status` column exists: 0 = current/updated, 1 = archived/old
    $r3 = $conn->query("SHOW COLUMNS FROM contractors LIKE 'status'");
    if (!$r3 || $r3->num_rows == 0) {
        $conn->query("ALTER TABLE contractors ADD COLUMN `status` TINYINT(1) DEFAULT 0 AFTER record_status");
    }
} catch (Exception $e) {
    // ignore - if migrations not allowed, SQL can be run manually
}

// Best-effort: remove unique index on company_name to allow history rows with same name
try {
    $idxRes = $conn->query("SHOW INDEX FROM contractors WHERE Column_name = 'company_name' AND Non_unique = 0");
    if ($idxRes && $idxRes->num_rows > 0) {
        while ($idxRow = $idxRes->fetch_assoc()) {
            $indexName = $idxRow['Key_name'];
            // Do not drop PRIMARY key
            if (strtoupper($indexName) !== 'PRIMARY') {
                $conn->query("ALTER TABLE contractors DROP INDEX `" . $conn->real_escape_string($indexName) . "`");
            }
        }
    }
} catch (Exception $e) {
    // ignore - DB user may not have permission, manual change may be required
}

if (function_exists('ensure_contact_persons_table')) {
    ensure_contact_persons_table($conn);
}


// Initialize variables
$redirect_url = null;
$edit_contractor_id = null;
$edit_data = null;
$form_error = null;

// Check if we're editing a contractor
if (isset($_GET['edit'])) {
    $edit_contractor_id = intval($_GET['edit']);
    
    // Get the latest active record (record_status = 0 or 1)
    $stmt = $conn->prepare("SELECT * FROM contractors 
                           WHERE id = ? AND record_status IN (0, 1)
                           ORDER BY record_status DESC, created_at DESC 
                           LIMIT 1");
    $stmt->bind_param("i", $edit_contractor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $edit_data = $result->fetch_assoc();
    $stmt->close();

    
    // Get services for this contractor
    if ($edit_data) {
        $service_stmt = $conn->prepare("SELECT service_id FROM contractor_services 
                                       WHERE contractor_id = ? 
                                       AND is_deleted = 0 
                                       ORDER BY created_at DESC");
        $service_stmt->bind_param("i", $edit_contractor_id);
        $service_stmt->execute();
        $service_result = $service_stmt->get_result();
        $edit_data['services'] = [];
        while ($row = $service_result->fetch_assoc()) {
            $edit_data['services'][] = $row['service_id'];
        }
        $service_stmt->close();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Require access for POST actions
    requireAccessOrDie();
    
    // Get current Sri Lanka timestamp
    $current_timestamp = date('Y-m-d H:i:s');
    
    // Handle adding/updating contractor
    if (isset($_POST['add_contractor']) || isset($_POST['update_contractor'])) {
        // Validate required fields
        $company_name = $_POST['company_name'];
        // Contact persons are handled via the Contact Persons panel. Keep legacy vars empty.
        $contact_person = '';
        $contact_person2 = '';
        // company_uid deprecated — not used
        $company_uid = null;
        // Contact numbers removed from Add/Edit Contractor form; keep variables for compatibility
        $contact_no = $_POST['contact_no'] ?? '';
        $contact_no2 = $_POST['contact_no2'] ?? '';
        $email = $_POST['email'];
        $address = $_POST['address'];
        $category = $_POST['category'];
        $agreement_done = isset($_POST['agreement_done']) ? 1 : 0;
        $training_done = isset($_POST['training_done']) ? 1 : 0;
        $documents_submitted = isset($_POST['documents_submitted']) ? 1 : 0;
        $active_status = $_POST['active_status'] ?? '';
        $remarks = $_POST['remarks'];
        $services_string = '';
        
        // Contact numbers are managed via the Contact Persons panel now; no validation here

        // Validate status selection
        if (empty($form_error) && (empty($active_status) || !in_array($active_status, ['Active', 'Inactive']))) {
            $form_error = "Please select an active status (Active or Inactive)";
        } else {
            // Create comma-separated string of service names for contractor_services column
            if (isset($_POST['services']) && is_array($_POST['services'])) {
                $service_names = [];
                foreach ($_POST['services'] as $service_id) {
                    // Get service name from database
                    $service_query = $conn->prepare("SELECT service_name FROM services WHERE id = ?");
                    $service_query->bind_param("i", $service_id);
                    $service_query->execute();
                    $service_result = $service_query->get_result();
                    if ($service_row = $service_result->fetch_assoc()) {
                        $service_names[] = $service_row['service_name'];
                    }
                    $service_query->close();
                }
                $services_string = implode(', ', $service_names);
            }
            
            if (isset($_POST['update_contractor'])) {
                // Update existing contractor - create new row with record_status = 1
                $contractor_id = intval($_POST['contractor_id']);
                
                // Get current record before update
                $current_record_stmt = $conn->prepare("SELECT * FROM contractors 
                                                      WHERE id = ? AND record_status IN (0, 1)
                                                      ORDER BY record_status DESC, created_at DESC 
                                                      LIMIT 1");
                $current_record_stmt->bind_param("i", $contractor_id);
                $current_record_stmt->execute();
                $current_record_result = $current_record_stmt->get_result();
                $current_record = $current_record_result->fetch_assoc();
                $current_record_stmt->close();
                
                if ($current_record) {
                    // Preserve history: mark old record archived (1), insert a new record (0) with updated data
                    $conn->begin_transaction();
                    try {
                        // Archive old record (mark status=1)
                        $archive_stmt = $conn->prepare("UPDATE contractors SET record_status = 1, status = 1, updated_by = ?, updated_at = ? WHERE id = ?");
                        if ($archive_stmt) {
                            $archive_stmt->bind_param('isi', $current_user_id, $current_timestamp, $current_record['id']);
                            $archive_stmt->execute();
                            $archive_stmt->close();
                        }

                        // Insert new contractor record as current (record_status = 0)
                        $ins = $conn->prepare("INSERT INTO contractors (
                            company_name, contact_person, contact_person2, contact_no, contact_no2, email, address, 
                            category, agreement_done, training_done, documents_submitted, active_status, 
                            remarks, user_id, saved_by, saved_at, contractor_services, record_status, status
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                        $new_record_status = 0;
                        $status_flag = 0; // new/current record
                        if ($ins) {
                            $ins->bind_param("ssssssssiiissiissii", 
                                $company_name,
                                $contact_person,
                                $contact_person2,
                                $contact_no,
                                $contact_no2,
                                $email,
                                $address,
                                $category,
                                $agreement_done,
                                $training_done,
                                $documents_submitted,
                                $active_status,
                                $remarks,
                                $current_user_id,
                                $current_user_id,
                                $current_timestamp,
                                $services_string,
                                $new_record_status,
                                $status_flag
                            );

                            if (!$ins->execute()) {
                                throw new Exception('Insert new contractor failed: ' . $ins->error);
                            }

                            $new_contractor_id = $ins->insert_id;
                            $ins->close();
                        } else {
                            throw new Exception('Prepare insert failed: ' . $conn->error);
                        }

                        // Copy selected services to new record
                        if (isset($_POST['services']) && is_array($_POST['services'])) {
                            foreach ($_POST['services'] as $service_id) {
                                $sid = intval($service_id);
                                $res_check = $conn->query("SELECT id FROM services WHERE id = " . $sid . " AND is_deleted = 0");
                                $svc_ok = $res_check && $res_check->num_rows > 0;
                                if (!$svc_ok) continue;
                                $service_stmt = $conn->prepare("INSERT INTO contractor_services 
                                                              (contractor_id, service_id, user_id, saved_by, saved_at) 
                                                              VALUES (?, ?, ?, ?, ?) 
                                                              ON DUPLICATE KEY UPDATE is_deleted = 0, user_id = VALUES(user_id), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                                $service_stmt->bind_param("iiiis", $new_contractor_id, $sid, $current_user_id, $current_user_id, $current_timestamp);
                                $service_stmt->execute();
                                $service_stmt->close();
                            }
                        }

                        // Process contact persons: create new rows for the new contractor (do NOT update old CP rows)
                        $posted_names = $_POST['cp_name'] ?? [];
                        $posted_numbers = $_POST['cp_contact_no'] ?? [];
                        if (is_array($posted_names)) {
                            foreach ($posted_names as $i => $rawName) {
                                $name = trim($rawName);
                                if ($name === '') continue;
                                $number = trim($posted_numbers[$i] ?? '') ?: null;
                                $parts = preg_split('/\\s+/', $name, 2);
                                $first = $parts[0] ?? '';
                                $last = $parts[1] ?? null;
                                add_contact_person($conn, (int)$new_contractor_id, $first, $last, 0, $current_user_id, $current_timestamp, $number);
                            }
                        }

                        $conn->commit();
                        $success_message = "Contractor updated: new record created and old record archived (history preserved).";
                        $redirect_url = $_SERVER['PHP_SELF'];
                    } catch (Exception $e) {
                        $conn->rollback();
                        $error_message = "Error updating (preserving history): " . $e->getMessage();
                    }
                }
            } else {
                // Insert new contractor (initial record with record_status = 0)
                // Prevent duplicate company names (unique constraint)
                $dup_stmt = $conn->prepare("SELECT id FROM contractors WHERE company_name = ? AND record_status IN (0,1) LIMIT 1");
                $dup_stmt->bind_param("s", $company_name);
                $dup_stmt->execute();
                $dup_res = $dup_stmt->get_result();
                if ($dup_res && $dup_res->num_rows > 0) {
                    $form_error = "Company '$company_name' already exists. Please edit the existing record instead of adding a duplicate.";
                }
                $dup_stmt->close();

                if (empty($form_error)) {
                    $stmt = $conn->prepare("INSERT INTO contractors (
                    company_name, contact_person, contact_person2, contact_no, contact_no2, email, address, 
                    category, agreement_done, training_done, documents_submitted, active_status, 
                    remarks, user_id, saved_by, saved_at, contractor_services, record_status, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                    $new_record_status = 0;
                    $status_flag = 0;

                    $stmt->bind_param("ssssssssiiissiissii", 
                        $company_name,
                        $contact_person,
                        $contact_person2,
                        $contact_no,
                        $contact_no2,
                        $email,
                        $address,
                        $category,
                        $agreement_done,
                        $training_done,
                        $documents_submitted,
                        $active_status,
                        $remarks,
                        $current_user_id,
                        $current_user_id,
                        $current_timestamp,
                        $services_string,
                        $new_record_status,
                        $status_flag
                    );
                    
                    if ($stmt->execute()) {
                    $contractor_id = $stmt->insert_id;
                    // company_uid deprecated — nothing to store
                    
                    // Handle services for new contractor
                    if (isset($_POST['services']) && is_array($_POST['services'])) {
                        foreach ($_POST['services'] as $service_id) {
                            $sid = intval($service_id);
                            // skip invalid or deleted services to avoid FK constraint failures
                            $res_check = $conn->query("SELECT id FROM services WHERE id = " . $sid . " AND is_deleted = 0");
                            $svc_ok = $res_check && $res_check->num_rows > 0;
                            if (!$svc_ok) continue;
                            $service_stmt = $conn->prepare("INSERT INTO contractor_services 
                                                          (contractor_id, service_id, user_id, saved_by, saved_at) 
                                                          VALUES (?, ?, ?, ?, ?) 
                                                          ON DUPLICATE KEY UPDATE is_deleted = 0, user_id = VALUES(user_id), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                            $service_stmt->bind_param("iiiis", $contractor_id, $sid, $current_user_id, $current_user_id, $current_timestamp);
                            $service_stmt->execute();
                            $service_stmt->close();
                        }
                    }
                    
                    $success_message = "Contractor added successfully by User ID $current_user_id";
                    } else {
                        $error_message = "Error adding contractor: " . $conn->error;
                    }
                    
                    $stmt->close();
                }
            }

            // After successful insert/update, also process contact_persons submitted from the contractor form
            if (isset($new_contractor_id) || isset($contractor_id)) {
                $company_for = $new_contractor_id ?? $contractor_id;

                // Gather submitted contact person data
                $posted_ids = $_POST['cp_id'] ?? [];
                $posted_names = $_POST['cp_name'] ?? [];
                $posted_numbers = $_POST['cp_contact_no'] ?? [];

                // Normalize arrays
                $posted_ids = is_array($posted_ids) ? $posted_ids : [];
                $posted_names = is_array($posted_names) ? $posted_names : [];
                $posted_numbers = is_array($posted_numbers) ? $posted_numbers : [];

                // Fetch existing contact person ids for this contractor so we can detect deletions
                $existing = $conn->prepare("SELECT id FROM contact_persons WHERE contractor_id = ? AND status = 0");
                if ($existing) {
                    $existing->bind_param('i', $company_for);
                    $existing->execute();
                    $er = $existing->get_result();
                    $existing_ids = [];
                    while ($r = $er->fetch_assoc()) $existing_ids[] = $r['id'];
                    $existing->close();
                } else {
                    $existing_ids = [];
                }

                $kept_ids = [];

                // Process submitted rows: update existing or add new
                foreach ($posted_names as $i => $rawName) {
                    $name = trim($rawName);
                    $number = trim($posted_numbers[$i] ?? '') ?: null;
                    if ($name === '') continue;

                    // split into first/last
                    $parts = preg_split('/\s+/', $name, 2);
                    $first = $parts[0] ?? '';
                    $last = $parts[1] ?? null;

                    $pid = isset($posted_ids[$i]) && intval($posted_ids[$i]) > 0 ? intval($posted_ids[$i]) : null;
                    if ($pid && in_array($pid, $existing_ids)) {
                        // update only if the posted id belongs to THIS contractor's contact_persons
                        update_contact_person($conn, $pid, $first, $last, $number, 0, $current_user_id, $current_timestamp);
                        $kept_ids[] = $pid;
                    } else {
                        // treat as new contact person for the current (possibly new) contractor
                        add_contact_person($conn, (int)$company_for, $first, $last, 0, $current_user_id, $current_timestamp, $number);
                        // note: new contact_person IDs won't be in $posted_ids; they will be preserved
                    }
                }

                // Delete any existing contact persons that were removed from the form
                foreach ($existing_ids as $eid) {
                    if (!in_array($eid, $kept_ids)) {
                        delete_contact_person($conn, $eid, $current_user_id, $current_timestamp);
                    }
                }
            }
        }
    }
    
    // Handle status update
    if (isset($_POST['update_status']) && isset($_POST['contractor_id']) && isset($_POST['new_status'])) {
        $contractor_id = intval($_POST['contractor_id']);
        $new_status = $_POST['new_status'];
        
        // Get current record before update
        $current_record_stmt = $conn->prepare("SELECT * FROM contractors 
                                              WHERE id = ? AND record_status IN (0, 1)
                                              ORDER BY record_status DESC, created_at DESC 
                                              LIMIT 1");
        $current_record_stmt->bind_param("i", $contractor_id);
        $current_record_stmt->execute();
        $current_record_result = $current_record_stmt->get_result();
        $current_record = $current_record_result->fetch_assoc();
        
        if ($current_record) {
            // Archive previous record (mark status=1)
            $archive_prev = $conn->prepare("UPDATE contractors SET status = 1 WHERE id = ?");
            if ($archive_prev) {
                $archive_prev->bind_param('i', $current_record['id']);
                $archive_prev->execute();
                $archive_prev->close();
            }

            // Insert new row with updated status and record_status = 1
            $insert_stmt = $conn->prepare("INSERT INTO contractors (
                company_name, contact_person, contact_person2, contact_no, contact_no2, email, address, 
                category, agreement_done, training_done, documents_submitted, active_status, 
                remarks, contractor_services, user_id, saved_by, saved_at, 
                updated_by, updated_at, updated_at_status, previous_status, record_status, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $new_record_status = 1;
            $status_flag = 0; // new current record

            $insert_stmt->bind_param("ssssssssiiisssiisisssii",
                    $current_record['company_name'],
                    $current_record['contact_person'],
                    isset($current_record['contact_person2']) ? $current_record['contact_person2'] : null,
                    $current_record['contact_no'],
                    $current_record['contact_no2'],
                    $current_record['email'],
                    $current_record['address'],
                $current_record['category'],
                isset($current_record['agreement_done']) ? $current_record['agreement_done'] : 0,
                isset($current_record['training_done']) ? $current_record['training_done'] : 0,
                    $current_record['documents_submitted'],
                    $new_status,
                    $current_record['remarks'],
                    $current_record['contractor_services'],
                    $current_user_id,
                    $current_user_id,
                    $current_timestamp,
                    $current_user_id,
                    $current_timestamp,
                    $current_timestamp,
                    $current_record['active_status'],
                    $new_record_status,
                    $status_flag
                );

                   if ($insert_stmt->execute()) {
                $new_contractor_id = $insert_stmt->insert_id;
                // company_uid deprecated — nothing to store for status update
                
                // Copy services to new record
                $copy_services_stmt = $conn->prepare("SELECT service_id FROM contractor_services 
                                                     WHERE contractor_id = ? AND is_deleted = 0");
                $copy_services_stmt->bind_param("i", $current_record['id']);
                $copy_services_stmt->execute();
                $copy_services_result = $copy_services_stmt->get_result();
                
                while ($service_row = $copy_services_result->fetch_assoc()) {
                    $sid = intval($service_row['service_id']);
                    $res_check = $conn->query("SELECT id FROM services WHERE id = " . $sid . " AND is_deleted = 0");
                    $svc_ok = $res_check && $res_check->num_rows > 0;
                    if (!$svc_ok) continue;
                    $service_copy_stmt = $conn->prepare("INSERT INTO contractor_services 
                                                        (contractor_id, service_id, user_id, saved_by, saved_at) 
                                                        VALUES (?, ?, ?, ?, ?) 
                                                        ON DUPLICATE KEY UPDATE is_deleted = 0, user_id = VALUES(user_id), saved_by = VALUES(saved_by), saved_at = VALUES(saved_at)");
                    $service_copy_stmt->bind_param("iiiis", $new_contractor_id, $sid, $current_user_id, $current_user_id, $current_timestamp);
                    $service_copy_stmt->execute();
                    $service_copy_stmt->close();
                }
                $copy_services_stmt->close();
                
                $redirect_url = $_SERVER['PHP_SELF'] . "?status_updated=" . $new_contractor_id;
                $status_success_message = "Status updated successfully! (New record created)";
            } else {
                $status_error_message = "Error updating status: " . $conn->error;
            }
            
            $insert_stmt->close();
        }
        $current_record_stmt->close();
    }
    
    // Handle adding new service
    if (isset($_POST['add_service'])) {
        $service_name = trim($_POST['service_name']);
        
        if (!empty($service_name)) {
            $check_stmt = $conn->prepare("SELECT id FROM services WHERE service_name = ? AND is_deleted = 0");
            $check_stmt->bind_param("s", $service_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO services (service_name, user_id, saved_by, saved_at) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("siis", $service_name, $current_user_id, $current_user_id, $current_timestamp);
                
                if ($stmt->execute()) {
                    $service_success_message = "Service added successfully by User ID $current_user_id";
                } else {
                    $service_error_message = "Error adding service: " . $conn->error;
                }
                
                $stmt->close();
            } else {
                $service_error_message = "Service '$service_name' already exists!";
            }
            
            $check_stmt->close();
        }
    }

    // Handle adding new contact person
    if (isset($_POST['add_contact_person'])) {
        $posted_contractor = $_POST['cp_contractor_id'] ?? '';
        $contractor_id = intval($posted_contractor) > 0 ? intval($posted_contractor) : 0;
        $new_company_name = trim($_POST['cp_new_company_name'] ?? '');
        $names = $_POST['cp_name'] ?? [];
        $isAjax = !empty($_POST['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

        // if special new option selected, create a minimal contractor record
        if (($posted_contractor === '__new__' || $contractor_id === 0) && !empty($new_company_name)) {
            $ins = $conn->prepare("INSERT INTO contractors (company_name, user_id, saved_by, saved_at, record_status, status) VALUES (?, ?, ?, ?, ?, ?)");
            if ($ins) {
                $rs = 0;
                $status_flag = 0;
                $ins->bind_param('siisii', $new_company_name, $current_user_id, $current_user_id, $current_timestamp, $rs, $status_flag);
                    if ($ins->execute()) {
                    $contractor_id = $ins->insert_id;
                }
                $ins->close();
            }
        }

        $added = 0; $errors = [];
        $numbers = $_POST['cp_contact_no'] ?? [];
        if ($contractor_id > 0 && is_array($names)) {
            foreach ($names as $i => $name_raw) {
                $name = trim($name_raw);
                if ($name === '') continue;
                // split into first and last (first token and rest)
                $parts = preg_split('/\s+/', $name, 2);
                $first = $parts[0] ?? '';
                $last = $parts[1] ?? null;
                $number = trim($numbers[$i] ?? '') ?: null;

                if (add_contact_person($conn, $contractor_id, $first, $last, 0, $current_user_id, $current_timestamp, $number)) {
                    $added++;
                } else {
                    $errors[] = 'Row ' . ($i+1) . ': ' . $conn->error;
                }
            }
        }

        if ($added > 0) {
            $cp_success_message = "Added $added contact person(s).";
        }
        if (!empty($errors)) {
            $cp_error_message = implode('\n', $errors);
        }

        if (!empty($isAjax)) {
            header('Content-Type: application/json');
            if (!empty($cp_error_message)) {
                echo json_encode(['success' => false, 'message' => $cp_error_message]);
            } else {
                echo json_encode(['success' => true, 'message' => $cp_success_message ?? 'Saved']);
            }
            // stop further page rendering for AJAX
            exit;
        }
    }

    // Handle delete contact person
    if (isset($_POST['delete_contact_person']) && isset($_POST['contact_person_id'])) {
        $cp_id = intval($_POST['contact_person_id']);
        if (delete_contact_person($conn, $cp_id, $current_user_id, $current_timestamp)) {
            $cp_delete_success = "Contact person deleted successfully.";
        } else {
            $cp_delete_error = "Error deleting contact person: " . $conn->error;
        }
    }
    
    // Handle delete contractor (set record_status = 3)
    if (isset($_POST['delete_contractor']) && isset($_POST['contractor_id'])) {
        $contractor_id = intval($_POST['contractor_id']);
        
        // Check column availability for deleted_by / deleted_at
        $has_deleted_by = $conn->query("SHOW COLUMNS FROM contractors LIKE 'deleted_by'")->num_rows > 0;
        $has_deleted_at = $conn->query("SHOW COLUMNS FROM contractors LIKE 'deleted_at'")->num_rows > 0;
        if ($has_deleted_by && $has_deleted_at) {
            $delete_stmt = $conn->prepare("UPDATE contractors SET record_status = 3, deleted_by = ?, deleted_at = ? WHERE id = ? AND record_status IN (0, 1)");
            $delete_stmt->bind_param("isi", $current_user_id, $current_timestamp, $contractor_id);
        } elseif ($has_deleted_by) {
            $delete_stmt = $conn->prepare("UPDATE contractors SET record_status = 3, deleted_by = ? WHERE id = ? AND record_status IN (0, 1)");
            $delete_stmt->bind_param("ii", $current_user_id, $contractor_id);
        } else {
            $delete_stmt = $conn->prepare("UPDATE contractors SET record_status = 3 WHERE id = ? AND record_status IN (0, 1)");
            $delete_stmt->bind_param("i", $contractor_id);
        }
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Also delete associated services
        $has_deleted_by_cs = $conn->query("SHOW COLUMNS FROM contractor_services LIKE 'deleted_by'")->num_rows > 0;
        $has_deleted_at_cs = $conn->query("SHOW COLUMNS FROM contractor_services LIKE 'deleted_at'")->num_rows > 0;
        if ($has_deleted_by_cs && $has_deleted_at_cs) {
            $service_delete_stmt = $conn->prepare("UPDATE contractor_services SET is_deleted = 1, deleted_by = ?, deleted_at = ? WHERE contractor_id = ?");
            $service_delete_stmt->bind_param("isi", $current_user_id, $current_timestamp, $contractor_id);
        } elseif ($has_deleted_by_cs) {
            $service_delete_stmt = $conn->prepare("UPDATE contractor_services SET is_deleted = 1, deleted_by = ? WHERE contractor_id = ?");
            $service_delete_stmt->bind_param("ii", $current_user_id, $contractor_id);
        } else {
            $service_delete_stmt = $conn->prepare("UPDATE contractor_services SET is_deleted = 1 WHERE contractor_id = ?");
            $service_delete_stmt->bind_param("i", $contractor_id);
        }
        $service_delete_stmt->execute();
        $service_delete_stmt->close();
        
        $delete_success_message = "Contractor deleted successfully by User ID $current_user_id";
        $redirect_url = $_SERVER['PHP_SELF'] . "?deleted=" . $contractor_id;
    }
    
    // Handle delete service
    if (isset($_POST['delete_service']) && isset($_POST['service_id'])) {
        $service_id = intval($_POST['service_id']);
        
        $has_deleted_by_service = $conn->query("SHOW COLUMNS FROM services LIKE 'deleted_by'")->num_rows > 0;
        $has_deleted_at_service = $conn->query("SHOW COLUMNS FROM services LIKE 'deleted_at'")->num_rows > 0;
        if ($has_deleted_by_service && $has_deleted_at_service) {
            $stmt = $conn->prepare("UPDATE services SET is_deleted = 1, deleted_by = ?, deleted_at = ? WHERE id = ?");
            $stmt->bind_param("isi", $current_user_id, $current_timestamp, $service_id);
        } elseif ($has_deleted_by_service) {
            $stmt = $conn->prepare("UPDATE services SET is_deleted = 1, deleted_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $current_user_id, $service_id);
        } else {
            $stmt = $conn->prepare("UPDATE services SET is_deleted = 1 WHERE id = ?");
            $stmt->bind_param("i", $service_id);
        }
        
        if ($stmt->execute()) {
            $has_deleted_by_cs = $conn->query("SHOW COLUMNS FROM contractor_services LIKE 'deleted_by'")->num_rows > 0;
            $has_deleted_at_cs = $conn->query("SHOW COLUMNS FROM contractor_services LIKE 'deleted_at'")->num_rows > 0;
            if ($has_deleted_by_cs && $has_deleted_at_cs) {
                $cs_stmt = $conn->prepare("UPDATE contractor_services SET is_deleted = 1, deleted_by = ?, deleted_at = ? WHERE service_id = ?");
                $cs_stmt->bind_param("isi", $current_user_id, $current_timestamp, $service_id);
            } elseif ($has_deleted_by_cs) {
                $cs_stmt = $conn->prepare("UPDATE contractor_services SET is_deleted = 1, deleted_by = ? WHERE service_id = ?");
                $cs_stmt->bind_param("ii", $current_user_id, $service_id);
            } else {
                $cs_stmt = $conn->prepare("UPDATE contractor_services SET is_deleted = 1 WHERE service_id = ?");
                $cs_stmt->bind_param("i", $service_id);
            }
            $cs_stmt->execute();
            $cs_stmt->close();
            
            $service_delete_success = "Service deleted successfully by User ID $current_user_id";
            $redirect_url = $_SERVER['PHP_SELF'] . "?service_deleted=" . $service_id;
        } else {
            $service_delete_error = "Error deleting service: " . $conn->error;
        }
        
        $stmt->close();
    }
}

// Fetch all active services
$services_result = $conn->query("SELECT * FROM services WHERE is_deleted = 0 ORDER BY service_name");

// Get the total count of contractor records to calculate the next ID
$count_result = $conn->query("SELECT COUNT(*) as total FROM contractors");
$count_row = $count_result->fetch_assoc();
$total_contractors = $count_row['total'];

// Calculate the starting ID based on total count (this will ensure continuous numbering)
$starting_id = $total_contractors + 1;

// Fetch contractors - show the latest record per company, hide if latest is deleted
// Some installations may not have `contact_no` / `contact_no2` columns; detect and include them only when present.
$extra_cols = '';
$res = $conn->query("SHOW COLUMNS FROM contractors LIKE 'contact_no'");
if ($res && $res->num_rows > 0) $extra_cols .= "contact_no, ";
$res = $conn->query("SHOW COLUMNS FROM contractors LIKE 'contact_no2'");
if ($res && $res->num_rows > 0) $extra_cols .= "contact_no2, ";

$contractors_query = "
    SELECT c.*, 
           GROUP_CONCAT(s.service_name SEPARATOR ', ') as services_list
    FROM (
        SELECT * FROM (
        SELECT id, company_name, contact_person, contact_person2, " . $extra_cols . "email, address, 
               category, agreement_done, training_done, documents_submitted, active_status, 
               remarks, contractor_services, updated_by, record_status,
                   created_at, updated_at,
                   ROW_NUMBER() OVER (
                       PARTITION BY company_name 
                       ORDER BY COALESCE(updated_at, created_at) DESC, created_at DESC
                   ) as rn
            FROM contractors 
            WHERE record_status IN (0, 1, 3)
        ) t
        WHERE t.rn = 1 AND t.record_status <> 3
    ) c
    LEFT JOIN contractor_services cs ON c.id = cs.contractor_id AND cs.is_deleted = 0
    LEFT JOIN services s ON cs.service_id = s.id AND s.is_deleted = 0
    GROUP BY c.id
    ORDER BY c.id DESC
";

$contractors_result = $conn->query($contractors_query);

// Create an array to store contractor IDs with their display IDs
// First, we need to get all contractors in ID order to assign sequential numbers
$all_contractors_query = "
    SELECT c.id FROM (
        SELECT * FROM (
        SELECT id, company_name, record_status,
                   ROW_NUMBER() OVER (
                       PARTITION BY company_name 
                       ORDER BY COALESCE(updated_at, created_at) DESC, created_at DESC
                   ) as rn
            FROM contractors 
            WHERE record_status IN (0, 1, 3)
        ) t
        WHERE t.rn = 1 AND t.record_status <> 3
    ) c
    ORDER BY c.id
";

$all_contractors_result = $conn->query($all_contractors_query);

$contractor_ids_display = [];
$counter = 1;
while($row = $all_contractors_result->fetch_assoc()) {
    $contractor_ids_display[$row['id']] = sprintf('TBS/SC/%02d', $counter);
    $counter++;
}

// Reset pointer for the main result set
$contractors_result->data_seek(0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registered SubContractor List</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background-color: transparent;
            padding: 10px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: transparent;
            border-radius: 8px;
            padding: 15px;
        }
        
        h1 {
            text-align: center;
            margin-bottom: 20px;
            color: #333;
            font-size: 24px;
        }
        
        h2 {
            margin-bottom: 15px;
            color: #444;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
            font-size: 18px;
        }
        
        .button-group {
            display: flex;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
            transition: background-color 0.3s;
        }
        
        .btn-primary {
            background-color: #2196F3;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0b7dda;
        }
        
        .btn-success {
            background-color: #4CAF50;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #45a049;
        }
        
        .btn-info {
            background-color: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background-color: #138496;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        
        .btn-warning {
            background-color: #ff9800;
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #e68a00;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        th, td {
            padding: 8px 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }

        /* Make Contact Person column wider and keep first+last on the same line */
        th.col-contact-person, td.col-contact-person {
            width: 220px;
            max-width: 300px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 4px;
        }

        /* Email column: even tighter spacing to contact person */
        th.col-email, td.col-email {
            width: 140px;
            max-width: 180px;
            padding-left: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Contact person row: name + contact no in one line */
        .cp-row {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            margin-bottom: 8px;
        }

        .cp-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .cp-row .remove-cp {
            height: 36px;
        }

        @media (max-width: 800px) {
            .cp-row {
                flex-direction: column;
            }
            .cp-row .remove-cp {
                height: auto;
            }
        }

        .contact-name {
            display: block;
            margin-bottom: 6px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.4;
        }
        
        th {
            background-color: #f8f9fa;
            font-weight: bold;
            color: #333;
            padding: 10px;
        }
        
        tr:hover {
            background-color: #f9f9f9;
        }
        
        .category-A {
            background-color: #d4edda;
            color: #155724;
            padding: 3px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .category-B {
            background-color: #fff3cd;
            color: #856404;
            padding: 3px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .category-C {
            background-color: #f8d7da;
            color: #721c24;
            padding: 3px 6px;
            border-radius: 3px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .status-buttons-container {
            display: flex;
            gap: 8px;
            margin: 0;
        }
        
        .status-button {
            padding: 5px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            transition: all 0.3s;
            min-width: 70px;
        }
        
        .active-button {
            background-color: #4CAF50;
            color: white;
        }
        
        .active-button:hover {
            background-color: #45a049;
        }
        
        .inactive-button {
            background-color: #f44336;
            color: white;
        }
        
        .inactive-button:hover {
            background-color: #da190b;
        }
        
        .status-text {
            padding: 5px 12px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 12px;
            min-width: 70px;
            text-align: center;
            display: inline-block;
        }
        
        .status-text-pending {
            background-color: #ff9800;
            color: white;
        }
        
        .status-text-active {
            background-color: #4CAF50;
            color: white;
        }
        
        .status-text-inactive {
            background-color: #f44336;
            color: white;
        }
        
        .edit-btn {
            background-color: #ff9800;
            color: white;
        }
        
        .yes-badge {
            background-color: #4CAF50;
            color: white;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .no-badge {
            background-color: #f44336;
            color: white;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 11px;
        }
        
        .updated-badge {
            background-color: #17a2b8;
            color: white;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .record-status-badge {
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-left: 5px;
            font-weight: bold;
        }
        
        .record-status-0 {
            background-color: #6c757d;
            color: white;
        }
        
        .record-status-1 {
            background-color: #28a745;
            color: white;
        }
        
        .record-status-3 {
            background-color: #dc3545;
            color: white;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 22px;
            border-radius: 8px;
            width: 95%;
            max-width: 1000px;
            max-height: 95vh;
            overflow-y: auto;
        }
        
        .close-btn {
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            color: #aaa;
        }
        
        .close-btn:hover {
            color: #000;
        }
        
        .form-group {
            margin-bottom: 12px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 4px;
            font-weight: bold;
            font-size: 13px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 7px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 4px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: auto;
        }
        
        .submit-btn {
            background-color: #4CAF50;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
        }
        
        .message {
            padding: 8px;
            margin: 8px 0;
            border-radius: 4px;
            font-size: 13px;
        }
        
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .contact-numbers {
            display: flex;
            gap: 8px;
        }
        
        .contact-numbers .form-group {
            flex: 1;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .action-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
        }
        
        .user-info {
            background-color: #e7f3ff;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            margin-bottom: 10px;
            border-left: 4px solid #2196F3;
        }
        
        .selected-status {
            box-shadow: 0 0 0 2px #333;
            font-weight: bold;
        }
        
        .status-validation-error {
            color: #dc3545;
            font-size: 12px;
            margin-top: 4px;
            display: none;
        }
        
        .status-validation-error.show {
            display: block;
        }
        
        @media (max-width: 800px){
            table {
                display: block;
                overflow-x: auto;
            }
            
            .button-group {
                flex-direction: column;
            }
            
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
            
            .contact-numbers {
                flex-direction: column;
            }
            
            body {
                padding: 5px;
            }
            
            .container {
                padding: 10px;
            }
            
            th, td {
                padding: 6px 8px;
                font-size: 12px;
            }
        }
        
        /* Yellow highlighted buttons style */
        .btn-yellow {
            background-color: #5466d6ff !important;
            color: #f6f6f6ff !important;
            font-weight: bold;
        }
        
        .btn-yellow:hover {
            background-color: #5466d6ff !important;
            color: #f6f6f6ff !important;
        }

        /* Search bar */
        .search-bar {
            margin: 10px 0 5px 0;
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .search-bar input {
            flex: 1;
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 13px;
        }

        .row-highlight {
            background-color: #fff9c4;
        }
    </style>
</head>
<body>
    <?php if ($redirect_url): ?>
        <script>
            // JavaScript redirect after page loads
            window.location.href = "<?php echo $redirect_url; ?>";
        </script>
    <?php endif; ?>
    
    <div class="container">
        <h1>Registered Subcontractor List </h1>
        <div class="user-info">
            <strong>Current User:</strong> ID <?php echo $current_user_id; ?> | 
            <strong>Access:</strong> <?php echo userHasAccess() ? 'Full Access' : 'View Only'; ?>
        </div>
        
        <?php if (isset($success_message)): ?>
            <div class="message success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="message error"><?php echo $error_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($service_success_message)): ?>
            <div class="message success"><?php echo $service_success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($service_error_message)): ?>
            <div class="message error"><?php echo $service_error_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($status_success_message)): ?>
            <div class="message success"><?php echo $status_success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($status_error_message)): ?>
            <div class="message error"><?php echo $status_error_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($delete_success_message)): ?>
            <div class="message success"><?php echo $delete_success_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($delete_error_message)): ?>
            <div class="message error"><?php echo $delete_error_message; ?></div>
        <?php endif; ?>
        
        <?php if (isset($service_delete_success)): ?>
            <div class="message success"><?php echo $service_delete_success; ?></div>
        <?php endif; ?>

        <?php if (isset($cp_success_message)): ?>
            <div class="message success"><?php echo $cp_success_message; ?></div>
        <?php endif; ?>

        <?php if (isset($cp_error_message)): ?>
            <div class="message error"><?php echo $cp_error_message; ?></div>
        <?php endif; ?>

        <?php if (isset($cp_delete_success)): ?>
            <div class="message success"><?php echo $cp_delete_success; ?></div>
        <?php endif; ?>

        <?php if (isset($cp_delete_error)): ?>
            <div class="message error"><?php echo $cp_delete_error; ?></div>
        <?php endif; ?>
        
        <?php if (isset($service_delete_error)): ?>
            <div class="message error"><?php echo $service_delete_error; ?></div>
        <?php endif; ?>
        
        <?php if (userHasAccess()): ?>
            <div class="button-group">
                <button class="btn btn-primary btn-yellow" onclick="openContractorModal()">
                    <?php echo $edit_data ? 'Update Contractor' : 'Add New Contractor'; ?>
                </button>
                <button class="btn btn-success" onclick="openServiceModal()">Add New Service</button>
                <button class="btn btn-info btn-yellow" onclick="openServicesListModal()">Services List</button>
                <!-- Contact Persons button removed (contact persons managed inside contractor modal) -->
                <?php if ($edit_data): ?>
                    <button class="btn btn-secondary" onclick="window.location.href='<?php echo $_SERVER['PHP_SELF']; ?>'">Cancel Edit</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <h2>Contractor List</h2>

        <div class="search-bar">
            <input type="text" id="searchInput" placeholder="Search contractors..." oninput="filterTable()">
        </div>
        
        <table id="contractorTable">
            <thead>
                <tr>
                    <th>Contractor ID</th>
                    <th>Company Name</th>
                    <th>Services</th>
                    <th class="col-contact-person">Contact Person</th>
                        <!-- Contact numbers moved to Contact Persons panel; columns removed -->
                        <th class="col-email">Email</th>
                    <th>Address</th>
                    <th>Category</th>
                    <th>Agreement</th>
                    <th>Training</th>
                    <th>Documents Submitted</th>
                    <th>Active Status</th>
                    <th>Remarks</th>
                    <?php if (userHasAccess()): ?>
                        <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($contractors_result->num_rows > 0): ?>
                    <?php while($row = $contractors_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo '<strong>' . htmlspecialchars($contractor_ids_display[$row['id']] ?? 'TBS/SC/' . sprintf('%02d', $row['id'])) . '</strong>'; ?></td>
                        <td><?php echo htmlspecialchars($row['company_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['contractor_services'] ?: '-'); ?></td>
                        <td class="col-contact-person">
                            <?php
                                $cps = get_contact_persons($conn, $row['id']);
                                if (!empty($cps)) {
                                    foreach ($cps as $cp) {
                                        $displayName = trim(($cp['first_name'] ?? '') . ' ' . ($cp['last_name'] ?? '')) ?: '-';
                                        $number = isset($cp['contact_no']) && $cp['contact_no'] !== null && $cp['contact_no'] !== '' ? ' <small>(' . htmlspecialchars($cp['contact_no']) . ')</small>' : '';
                                        echo '<div class="contact-name">' . htmlspecialchars($displayName) . $number . '</div>';
                                    }
                                } else {
                                    echo '<div class="contact-name">-</div>';
                                }
                            ?>
                        </td>
                        <!-- contact_no/contact_no2 columns removed from contractor list -->
                        <td class="col-email"><?php echo htmlspecialchars($row['email']); ?></td>
                        <td><?php echo htmlspecialchars($row['address']); ?></td>
                        <td>
                            <span class="category-<?php echo $row['category']; ?>">
                                <?php echo $row['category']; ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo !empty($row['agreement_done']) ? 'yes-badge' : 'no-badge'; ?>">
                                <?php echo !empty($row['agreement_done']) ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo !empty($row['training_done']) ? 'yes-badge' : 'no-badge'; ?>">
                                <?php echo !empty($row['training_done']) ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td>
                            <span class="<?php echo $row['documents_submitted'] ? 'yes-badge' : 'no-badge'; ?>">
                                <?php echo $row['documents_submitted'] ? 'Yes' : 'No'; ?>
                            </span>
                        </td>
                        <td>
                            <?php if(isset($_GET['status_updated']) && $_GET['status_updated'] == $row['id']): ?>
                                <?php if($row['active_status'] == 'Active'): ?>
                                    <span class="status-text status-text-active">Active</span>
                                <?php elseif($row['active_status'] == 'Inactive'): ?>
                                    <span class="status-text status-text-inactive">Inactive</span>
                                <?php else: ?>
                                    <span class="status-text status-text-pending">Pending</span>
                                <?php endif; ?>
                            <?php elseif($row['active_status'] == 'Active' || $row['active_status'] == 'Inactive'): ?>
                                <?php if($row['active_status'] == 'Active'): ?>
                                    <span class="status-text status-text-active">Active</span>
                                <?php else: ?>
                                    <span class="status-text status-text-inactive">Inactive</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if (userHasAccess()): ?>
                                    <!-- Authorized users see status buttons -->
                                    <form method="POST" class="status-form" id="status-form-<?php echo $row['id']; ?>">
                                        <input type="hidden" name="contractor_id" value="<?php echo $row['id']; ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="new_status" id="new_status_<?php echo $row['id']; ?>" value="">
                                        
                                        <div class="status-buttons-container">
                                            <button type="button" 
                                                    class="status-button active-button"
                                                    onclick="setStatus(<?php echo $row['id']; ?>, 'Active')">
                                                Active
                                            </button>
                                            
                                            <button type="button" 
                                                    class="status-button inactive-button"
                                                    onclick="setStatus(<?php echo $row['id']; ?>, 'Inactive')">
                                                Inactive
                                            </button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <!-- Other users see status as text -->
                                    <span class="status-text status-text-pending">Pending</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['remarks'] ?: '-'); ?></td>
                        <?php if (userHasAccess()): ?>
                        <td>
                            <!-- Authorized users see action buttons -->
                            <div class="action-buttons">
                                <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>?edit=<?php echo intval($row['id']); ?>" class="action-btn btn-warning">Edit</a>
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="contractor_id" value="<?php echo $row['id']; ?>">
                                    <input type="hidden" name="delete_contractor" value="1">
                                    <button type="button" class="action-btn btn-danger" 
                                            onclick="confirmDelete(<?php echo $row['id']; ?>, 'contractor')">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo userHasAccess() ? 15 : 14; ?>" style="text-align: center;">No contractors found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Add/Edit Contractor Modal -->
    <div id="contractorModal" class="modal" style="<?php echo $edit_data ? 'display: block;' : ''; ?>">
        <div class="modal-content">
            <span class="close-btn" onclick="closeContractorModal()">&times;</span>
            <h2><?php echo $edit_data ? 'Update Contractor' : 'Add New Contractor'; ?> </h2>
            
            <?php if (isset($form_error)): ?>
                <div class="message error"><?php echo $form_error; ?></div>
            <?php endif; ?>
            
            <?php if (userHasAccess()): ?>
                <form method="POST" action="" id="contractorForm" onsubmit="return validateContractorForm()">
                    <div class="form-group">
                        <label for="company_name">Company Name *</label>
                        <input type="text" id="company_name" name="company_name" required 
                               value="<?php echo $edit_data ? htmlspecialchars($edit_data['company_name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="services">Services Provided *</label>
                        <div class="checkbox-group" id="services-checkbox-group">
                            <?php 
                            $services_modal_result = $conn->query("SELECT * FROM services WHERE is_deleted = 0 ORDER BY service_name");
                            if ($services_modal_result->num_rows > 0): 
                                while($service = $services_modal_result->fetch_assoc()): 
                                    $checked = $edit_data ? (in_array($service['id'], $edit_data['services']) ? 'checked' : '') : '';
                                ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="service_<?php echo $service['id']; ?>" 
                                               name="services[]" value="<?php echo $service['id']; ?>" <?php echo $checked; ?>>
                                        <label for="service_<?php echo $service['id']; ?>">
                                            <?php echo htmlspecialchars($service['service_name']); ?>
                                        </label>
                                    </div>
                                <?php endwhile; 
                                $services_modal_result->free();
                            else: ?>
                                <p>No services available. Please add services first.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3>Contact Persons</h3>
                    <div id="contractor_cp_rows">
                        <?php if ($edit_data):
                            $existing_cps = get_contact_persons($conn, $edit_contractor_id);
                            if (!empty($existing_cps)) {
                                foreach ($existing_cps as $ecp) {
                                    $full = htmlspecialchars(trim(($ecp['first_name'] ?? '') . ' ' . ($ecp['last_name'] ?? '')));
                                    $cno = htmlspecialchars($ecp['contact_no'] ?? '');
                                    echo '<div class="cp-row existing-cp">';
                                    echo '<input type="hidden" name="cp_id[]" value="' . intval($ecp['id']) . '">';
                                    echo '<div class="form-group"><label>Contact Person Name *</label><input type="text" name="cp_name[]" required value="' . $full . '"></div>';
                                    echo '<div class="form-group"><label>Contact No</label><input type="tel" name="cp_contact_no[]" pattern="[0-9]{10}" maxlength="10" value="' . $cno . '"></div>';
                                    echo '<div class="cp-actions"><button type="button" class="btn btn-secondary remove-cp">Remove</button></div>';
                                    echo '</div>';
                                }
                            }
                        endif; ?>

                        <!-- default empty row for new entries -->
                        <div class="cp-row <?php echo $edit_data && !empty($existing_cps) ? 'hidden' : ''; ?>">
                            <div class="form-group">
                                <label>Contact Person Name</label>
                                <input type="text" name="cp_name[]" placeholder="Full name (e.g., John Doe)">
                            </div>
                            <div class="form-group">
                                <label>Contact No</label>
                                <input type="tel" name="cp_contact_no[]" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" placeholder="0777123456 (Optional)">
                            </div>
                            <div class="cp-actions"><button type="button" class="btn btn-secondary remove-cp">Remove</button></div>
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <button type="button" class="btn btn-success" id="addContractorCpBtn">Add Contact Person</button>
                    </div>
                    
                    <!-- Contact numbers moved to Contact Persons panel; inputs removed from contractor form -->
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo $edit_data ? htmlspecialchars($edit_data['email']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="address">Address *</label>
                        <textarea id="address" name="address" required><?php echo $edit_data ? htmlspecialchars($edit_data['address']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="category">Category *</label>
                        <select id="category" name="category" required>
                            <option value="">Select Category</option>
                            <option value="A" <?php echo ($edit_data && $edit_data['category'] == 'A') ? 'selected' : ''; ?>>A</option>
                            <option value="B" <?php echo ($edit_data && $edit_data['category'] == 'B') ? 'selected' : ''; ?>>B</option>
                            <option value="C" <?php echo ($edit_data && $edit_data['category'] == 'C') ? 'selected' : ''; ?>>C</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <label class="checkbox-item">
                                <input type="checkbox" name="agreement_done" value="1"
                                    <?php echo ($edit_data && $edit_data['agreement_done'] == 1) ? 'checked' : ''; ?>>
                                Agreement Done
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="training_done" value="1"
                                    <?php echo ($edit_data && isset($edit_data['training_done']) && $edit_data['training_done'] == 1) ? 'checked' : ''; ?>>
                                Training Done
                            </label>
                            <label class="checkbox-item">
                                <input type="checkbox" name="documents_submitted" value="1"
                                    <?php echo ($edit_data && $edit_data['documents_submitted'] == 1) ? 'checked' : ''; ?>>
                                Documents Submitted
                            </label>
                        </div>
                    </div>
                    
                    <!-- Active Status Field with Required Selection -->
                    <div class="form-group">
                        <label>Active Status *</label>
                        <div class="status-buttons-container" style="margin-top: 5px;">
                            <input type="hidden" name="active_status" id="active_status" 
                                   value="<?php echo $edit_data ? htmlspecialchars($edit_data['active_status']) : ''; ?>" required>
                            
                            <!-- Active and Inactive buttons -->
                            <button type="button" 
                                    id="activeBtn"
                                    class="status-button active-button <?php echo ($edit_data && $edit_data['active_status'] == 'Active') ? 'selected-status' : ''; ?>"
                                    onclick="setActiveStatus('Active')">
                                Active
                            </button>
                            
                            <button type="button" 
                                    id="inactiveBtn"
                                    class="status-button inactive-button <?php echo ($edit_data && $edit_data['active_status'] == 'Inactive') ? 'selected-status' : ''; ?>"
                                    onclick="setActiveStatus('Inactive')">
                                Inactive
                            </button>
                        </div>
                        <div id="statusError" class="status-validation-error">
                            Please select an active status (Active or Inactive)
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="remarks">Remarks</label>
                        <textarea id="remarks" name="remarks"><?php echo $edit_data ? htmlspecialchars($edit_data['remarks']) : ''; ?></textarea>
                    </div>
                    
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="contractor_id" value="<?php echo $edit_contractor_id; ?>">
                        <input type="hidden" name="update_contractor" value="1">
                        <button type="submit" class="submit-btn">Update Contractor</button>
                    <?php else: ?>
                        <input type="hidden" name="add_contractor" value="1">
                        <button type="submit" class="submit-btn">Add Contractor</button>
                    <?php endif; ?>
                </form>
            <?php else: ?>
                <div style="text-align: center; padding: 20px;">
                    <h3 style="color: #dc3545;">Access Denied</h3>
                    <p>Only authorized users can add or update contractors.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Add Service Modal -->
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeServiceModal()">&times;</span>
            <h2>Add New Service </h2>
            <?php if (userHasAccess()): ?>
                <form method="POST" action="" id="serviceForm">
                    <div class="form-group">
                        <label for="service_name">Service Name *</label>
                        <input type="text" id="service_name" name="service_name" required 
                               placeholder="Enter service name (e.g., Electrical, Plumbing)">
                    </div>
                    
                    <input type="hidden" name="add_service" value="1">
                    <button type="submit" class="submit-btn">Add Service</button>
                </form>
            <?php else: ?>
                <div style="text-align: center; padding: 20px;">
                    <h3 style="color: #dc3545;">Access Denied</h3>
                    <p>Only authorized users can add new services.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Services List Modal -->
    <div id="servicesListModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeServicesListModal()">&times;</span>
            <h2>Services List</h2>
            
            <?php if (userHasAccess()): ?>
                <?php 
                $services_list_result = $conn->query("SELECT * FROM services WHERE is_deleted = 0 ORDER BY service_name");
                ?>
                
                <?php if ($services_list_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Service Name</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($service = $services_list_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $service['id']; ?></td>
                                <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                <td>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
                                        <input type="hidden" name="delete_service" value="1">
                                        <button type="button" class="action-btn btn-danger" 
                                                onclick="confirmDeleteModal(<?php echo $service['id']; ?>, 'service')">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p style="text-align: center; padding: 20px;">No services available yet.</p>
                <?php endif; ?>
            <?php else: ?>
                <div style="text-align: center; padding: 20px;">
                    <h3 style="color: #dc3545;">Access Denied</h3>
                    <p>Only authorized users can view and manage services.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Contact Persons Modal -->
    <div id="contactsModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeContactsModal()">&times;</span>
            <h2>Contact Persons </h2>
            <?php if (userHasAccess()): ?>
                <?php 
                $contractor_options_result = $conn->query("SELECT id, company_name FROM (
                    SELECT * FROM contractors WHERE record_status IN (0,1) ORDER BY COALESCE(updated_at, created_at) DESC
                ) t GROUP BY company_name ORDER BY company_name");
                ?>

                <form method="POST" action="" id="contactsForm">
                    <div class="form-group">
                        <label for="cp_contractor_id">Company *</label>
                        <select id="cp_contractor_id" name="cp_contractor_id" required>
                            <option value="">Select company</option>
                            <?php if ($contractor_options_result && $contractor_options_result->num_rows > 0): ?>
                                <?php while($co = $contractor_options_result->fetch_assoc()): ?>
                                    <option value="<?php echo $co['id']; ?>"><?php echo htmlspecialchars($co['company_name']); ?></option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            <option value="__new__">Add New Company</option>
                        </select>
                    </div>
                    <div class="form-group" id="newCompanyField" style="display: none;">
                        <label for="cp_new_company_name">New Company Name *</label>
                        <input type="text" id="cp_new_company_name" name="cp_new_company_name" placeholder="Enter new company name">
                    </div>

                    <div id="cp_rows_container">
                        <!-- dynamic contact person rows will be inserted here -->
                        <div class="cp-row">
                            <div class="form-group">
                                <label>Contact Person Name *</label>
                                <input type="text" name="cp_name[]" required placeholder="Full name (e.g., John Doe)">
                            </div>
                            <div class="form-group">
                                <label>Contact Person Contact No </label>
                                <input type="tel" name="cp_contact_no[]" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" placeholder="0777123456 (Optional)">
                            </div>
                            <div style="margin-bottom:8px;"><button type="button" class="btn btn-secondary remove-cp">Remove</button></div>
                        </div>
                    </div>

                    <div style="margin-bottom:12px;">
                        <button type="button" class="btn btn-success" id="addCpRowBtn">Add Contact Person</button>
                    </div>

                    <input type="hidden" name="add_contact_person" value="1">
                    <button type="submit" class="submit-btn">Save Contact Persons</button>
                </form>

                <hr>
                <h3>Existing Contact Persons</h3>
                <?php
                $all_cps = $conn->query("SELECT cp.*, c.company_name FROM contact_persons cp LEFT JOIN contractors c ON cp.contractor_id = c.id WHERE cp.status = 0 ORDER BY c.company_name, cp.id");
                ?>
                <?php if ($all_cps && $all_cps->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Company</th>
                                <th>Name</th>
                                <th>Contact No</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($cp = $all_cps->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($cp['company_name'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars(trim(($cp['first_name'] ?? '') . ' ' . ($cp['last_name'] ?? ''))); ?></td>
                                <td><?php echo htmlspecialchars($cp['contact_no'] ?? '-'); ?></td>
                                <td>
                                    <form method="POST" style="margin:0;">
                                        <input type="hidden" name="contact_person_id" value="<?php echo $cp['id']; ?>">
                                        <input type="hidden" name="delete_contact_person" value="1">
                                        <button type="button" class="action-btn btn-danger" onclick="confirmDeleteModal(<?php echo $cp['id']; ?>, 'contact_person')">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No contact persons found.</p>
                <?php endif; ?>

            <?php else: ?>
                <div style="text-align: center; padding: 20px;">
                    <h3 style="color: #dc3545;">Access Denied</h3>
                    <p>Only authorized users can view and manage contact persons.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Modal functions for contractor modal
        function openContractorModal() {
            document.getElementById('contractorModal').style.display = 'block';
        }
        
        function closeContractorModal() {
            document.getElementById('contractorModal').style.display = 'none';
            // Redirect to clear edit mode
            window.location.href = '<?php echo $_SERVER['PHP_SELF']; ?>';
        }
        
        // Modal functions for service modal
        function openServiceModal() {
            document.getElementById('serviceModal').style.display = 'block';
        }
        
        function closeServiceModal() {
            document.getElementById('serviceModal').style.display = 'none';
        }

        // Modal functions for contact persons modal
        function openContactsModal() {
            document.getElementById('contactsModal').style.display = 'block';
        }

        function closeContactsModal() {
            document.getElementById('contactsModal').style.display = 'none';
        }
        
        // Modal functions for services list modal
        function openServicesListModal() {
            document.getElementById('servicesListModal').style.display = 'block';
        }
        
        function closeServicesListModal() {
            document.getElementById('servicesListModal').style.display = 'none';
        }
        
        // Set status when button is clicked
        function setStatus(contractorId, newStatus) {
            if (confirm(`Are you sure you want to set status to ${newStatus}? This will create a new record.`)) {
                document.getElementById(`new_status_${contractorId}`).value = newStatus;
                const form = document.getElementById(`status-form-${contractorId}`);
                form.submit();
            }
        }
        
        // Set active status in edit form
        function setActiveStatus(status) {
            document.getElementById('active_status').value = status;
            
            // Update button styles
            document.getElementById('activeBtn').classList.remove('selected-status');
            document.getElementById('inactiveBtn').classList.remove('selected-status');
            
            if (status === 'Active') {
                document.getElementById('activeBtn').classList.add('selected-status');
            } else if (status === 'Inactive') {
                document.getElementById('inactiveBtn').classList.add('selected-status');
            }
            
            // Hide error message if status is selected
            document.getElementById('statusError').classList.remove('show');
        }
        
        // Form validation for contractor form
        function validateContractorForm() {
            const activeStatus = document.getElementById('active_status').value;
            const servicesCheckboxGroup = document.getElementById('services-checkbox-group');
            const checkboxes = servicesCheckboxGroup.querySelectorAll('input[type="checkbox"][name="services[]"]:checked');
            
            // Contact numbers are entered and validated in the Contact Persons panel now

            // Check if status is selected
            if (!activeStatus || (activeStatus !== 'Active' && activeStatus !== 'Inactive')) {
                document.getElementById('statusError').classList.add('show');
                return false;
            }

            // Check if at least one service is selected
            if (checkboxes.length === 0) {
                alert('Please select at least one service');
                return false;
            }

            return true;
        }
        
        // Confirm delete function for main page
        function confirmDelete(id, type) {
            let itemType = 'item';
            if (type === 'contractor') itemType = 'contractor';
            else if (type === 'service') itemType = 'service';
            else if (type === 'contact_person') itemType = 'contact person';

            if (confirm(`Are you sure you want to delete this ${itemType}? This will delete related records.`)) {
                const form = event.target.closest('form');
                form.submit();
            }
        }

        // Confirm delete function for modal
        function confirmDeleteModal(id, type) {
            let itemType = 'item';
            if (type === 'contractor') itemType = 'contractor';
            else if (type === 'service') itemType = 'service';
            else if (type === 'contact_person') itemType = 'contact person';

            if (confirm(`Are you sure you want to delete this ${itemType}? This will delete related records.`)) {
                const form = event.target.closest('form');
                form.submit();
            }
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const contractorModal = document.getElementById('contractorModal');
            const serviceModal = document.getElementById('serviceModal');
            const servicesListModal = document.getElementById('servicesListModal');
            const contactsModal = document.getElementById('contactsModal');
            
            if (event.target == contractorModal) {
                closeContractorModal();
            }
            if (event.target == serviceModal) {
                closeServiceModal();
            }
            if (event.target == servicesListModal) {
                closeServicesListModal();
            }
            if (event.target == contactsModal) {
                closeContactsModal();
            }
        }
        
        // Auto-close messages after 5 seconds
        setTimeout(function() {
            const messages = document.querySelectorAll('.message');
            messages.forEach(message => {
                message.style.display = 'none';
            });
        }, 5000);
        
        // Initialize form on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-open edit modal if in edit mode
            <?php if ($edit_data): ?>
                document.getElementById('contractorModal').style.display = 'block';
                
                // Set initial active status button style for Update mode
                const currentStatus = '<?php echo $edit_data["active_status"]; ?>';
                if (currentStatus === 'Active') {
                    setActiveStatus('Active');
                } else if (currentStatus === 'Inactive') {
                    setActiveStatus('Inactive');
                }
            <?php else: ?>
                // For Add New mode, set default status to Active
                setActiveStatus('Active');
            <?php endif; ?>
            
            // Scroll to updated row
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('status_updated')) {
                const contractorId = urlParams.get('status_updated');
                const row = document.querySelector(`#status-form-${contractorId}`)?.closest('tr');
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }

            // Add contact person rows management
            function createCpRow(existingId, name, number) {
                const row = document.createElement('div');
                row.className = 'cp-row';
                row.innerHTML = `
                    ${existingId ? '<input type="hidden" name="cp_id[]" value="' + existingId + '">' : ''}
                    <div class="form-group">
                        <label>Contact Person Name</label>
                        <input type="text" name="cp_name[]" placeholder="Full name (e.g., John Doe)" value="${name ? name.replace(/\"/g, '&quot;') : ''}">
                    </div>
                    <div class="form-group">
                        <label>Contact No</label>
                        <input type="tel" name="cp_contact_no[]" pattern="[0-9]{10}" maxlength="10" inputmode="numeric" placeholder="0777123456 (Optional)" value="${number ? number : ''}">
                    </div>
                    <div class="cp-actions"><button type="button" class="btn btn-secondary remove-cp">Remove</button></div>
                `;
                row.querySelector('.remove-cp').addEventListener('click', function() { row.remove(); });
                return row;
            }

            // Hook add button inside contacts modal (existing) and contractor modal
            const cpContainerMain = document.getElementById('cp_rows_container');
            const addCpBtnMain = document.getElementById('addCpRowBtn');
            if (addCpBtnMain && cpContainerMain) {
                addCpBtnMain.addEventListener('click', function() {
                    const r = createCpRow(null, '', '');
                    cpContainerMain.appendChild(r);
                });
            }

            const contractorCpContainer = document.getElementById('contractor_cp_rows');
            const addContractorCpBtn = document.getElementById('addContractorCpBtn');
            if (addContractorCpBtn && contractorCpContainer) {
                addContractorCpBtn.addEventListener('click', function() {
                    const r = createCpRow(null, '', '');
                    contractorCpContainer.appendChild(r);
                });
            }

            // Remove button functionality for any pre-rendered remove buttons
            document.querySelectorAll('.remove-cp').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.closest('.cp-row').remove();
                });
            });

            // Show/hide new company field based on select
            const cpSelect = document.getElementById('cp_contractor_id');
            const newCompanyField = document.getElementById('newCompanyField');
            if (cpSelect && newCompanyField) {
                cpSelect.addEventListener('change', function() {
                    if (this.value === '__new__') {
                        newCompanyField.style.display = 'block';
                        document.getElementById('cp_new_company_name').required = true;
                    } else {
                        newCompanyField.style.display = 'none';
                        document.getElementById('cp_new_company_name').required = false;
                    }
                });
            }

            // Form validation for contact persons form
            const contactsForm = document.getElementById('contactsForm');
            if (contactsForm) {
                contactsForm.addEventListener('submit', function(e) {
                    const cpSelect = document.getElementById('cp_contractor_id');
                    const newCompanyName = document.getElementById('cp_new_company_name');
                    
                    // Validate company selection
                    if (cpSelect.value === '__new__' && !newCompanyName.value.trim()) {
                        e.preventDefault();
                        alert('Please enter a new company name');
                        newCompanyName.focus();
                        return false;
                    }
                    
                    // Validate at least one contact person name is filled
                    const nameInputs = document.querySelectorAll('input[name="cp_name[]"]');
                    let hasValidName = false;
                    nameInputs.forEach(input => {
                        if (input.value.trim()) {
                            hasValidName = true;
                        }
                    });
                    
                    if (!hasValidName) {
                        e.preventDefault();
                        alert('Please enter at least one contact person name');
                        return false;
                    }
                    
                    return true;
                });
            }

            // Form validation for service form
            const serviceForm = document.getElementById('serviceForm');
            if (serviceForm) {
                serviceForm.addEventListener('submit', function(e) {
                    const serviceName = document.getElementById('service_name');
                    if (!serviceName.value.trim()) {
                        e.preventDefault();
                        alert('Please enter a service name');
                        serviceName.focus();
                        return false;
                    }
                    return true;
                });
            }
        });

        // Table search and highlight
        function filterTable() {
            const query = document.getElementById('searchInput').value.trim().toLowerCase();
            const table = document.getElementById('contractorTable');
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(row => {
                row.classList.remove('row-highlight');
                row.style.display = '';

                if (!query) {
                    return;
                }

                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.classList.add('row-highlight');
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
    
</body>
</html>

<?php
// Close connection
$conn->close();
?>