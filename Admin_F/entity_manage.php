<?php

/**
 * Entity Manager – manages entity types, entities (hierarchy), and RAG knowledge chunks.
 */
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ./admin-login.php');
    exit();
}
require_once 'db.php';
if (!$conn || $conn->connect_error)
    die('Connection failed: ' . ($conn ? $conn->connect_error : 'No connection object.'));

$admin_stmt = $conn->prepare("SELECT admin_id, username, email FROM admins WHERE admin_id = ?");
$admin_stmt->bind_param('i', $_SESSION['admin_id']);
$admin_stmt->execute();
$admin = $admin_stmt->get_result()->fetch_assoc();
if (!$admin) {
    session_unset();
    session_destroy();
    header("Location: ./admin-login.php?err=account_missing");
    exit();
}

$notice = null;
$error = null;

$ICON_OPTIONS = [
    'fa-university'      => 'University',
    'fa-building-columns' => 'Faculty / Building',
    'fa-building'        => 'Department',
    'fa-graduation-cap'  => 'Program / Degree',
    'fa-user-tie'        => 'Staff / Person',
    'fa-book'            => 'Course / Book',
    'fa-flask'           => 'Research / Lab',
    'fa-globe'           => 'Website / Global',
    'fa-calendar'        => 'Event / Calendar',
    'fa-award'           => 'Scholarship / Award',
    'fa-landmark'        => 'Landmark / Campus',
    'fa-briefcase'       => 'Career / Job',
    'fa-clipboard-list'  => 'Requirements / List',
    'fa-money-bill'      => 'Fee / Finance',
    'fa-users'           => 'Group / Community',
    'fa-sitemap'         => 'Hierarchy / Structure',
    'fa-cube'            => 'Generic',
    'fa-cog'             => 'Settings',
    'fa-map-marker-alt'  => 'Location',
    'fa-phone'           => 'Contact',
    'fa-envelope'        => 'Email / Mail',
    'fa-link'            => 'Link / URL',
    'fa-image'           => 'Media / Image',
    'fa-file-alt'        => 'Document / File',
];

// ---- Handle GET Actions ----
if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    $requested_type = $_GET['type'] ?? '';
    
    // Call python script to generate .xlsx file with Data Validation (dropdowns)
    $tmp_file = tempnam(sys_get_temp_dir(), 'excel_') . '.xlsx';
    $cmd = "/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python /home/bcodz/Desktop/pjt-chatbot/scripts/generate_excel_template.py --output " . escapeshellarg($tmp_file);
    if (!empty($requested_type)) {
        $cmd .= " --type " . escapeshellarg($requested_type);
        $filename = "{$requested_type}_import_template.xlsx";
    } else {
        $filename = "entity_import_template.xlsx";
    }
    
    $cmd .= " 2>&1"; // capture stderr for debugging
    exec($cmd, $output, $return_var);
    
    if ($return_var === 0 && file_exists($tmp_file)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp_file));
        readfile($tmp_file);
        unlink($tmp_file);
        exit();
    } else {
        $error_log = implode("\n", $output);
        die("Error generating Excel template: <pre>" . htmlspecialchars($error_log) . "</pre>");
    }
}

// ---- Handle CRUD ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_entity_type') {
            $tn = trim($_POST['type_name'] ?? '');
            $tl = trim($_POST['type_label'] ?? '');
            $ic = trim($_POST['icon'] ?? 'fa-cube');
            $ds = trim($_POST['type_description'] ?? '');
            if ($tn === '' || $tl === '') throw new Exception('Type name and label required.');
            $stmt = $conn->prepare("INSERT INTO entity_types (type_name, type_label, icon, description) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $tn, $tl, $ic, $ds);
            $stmt->execute();
            $notice = "Entity type '$tl' created.";
        } elseif ($action === 'update_entity_type') {
            $tid = (int) $_POST['type_id'];
            $tn = trim($_POST['type_name'] ?? '');
            $tl = trim($_POST['type_label'] ?? '');
            $ic = trim($_POST['icon'] ?? 'fa-cube');
            $ds = trim($_POST['type_description'] ?? '');
            if ($tn === '' || $tl === '') throw new Exception('Type name and label required.');
            $stmt = $conn->prepare("UPDATE entity_types SET type_name=?, type_label=?, icon=?, description=? WHERE type_id=?");
            $stmt->bind_param('ssssi', $tn, $tl, $ic, $ds, $tid);
            $stmt->execute();
            $notice = "Entity type '$tl' updated.";
        } elseif ($action === 'delete_entity_type') {
            $tid = (int) $_POST['type_id'];
            $cnt = $conn->query("SELECT COUNT(*) AS c FROM university_entities WHERE entity_type_id=$tid")->fetch_assoc()['c'];
            if ($cnt > 0) throw new Exception("Cannot delete: $cnt entities use this type.");
            $conn->query("DELETE FROM entity_types WHERE type_id=$tid");
            $notice = 'Entity type deleted.';
        } elseif ($action === 'create_entity') {
            $type_id   = (int) $_POST['entity_type_id'];
            $code      = trim($_POST['entity_code'] ?? '') ?: null;
            $uni_id    = ($_POST['university_id'] ?? '') !== '' ? (int) $_POST['university_id'] : null;
            $parent_id = ($_POST['parent_entity_id'] ?? '') !== '' ? (int) $_POST['parent_entity_id'] : null;
            $name      = trim($_POST['entity_name'] ?? '');
            $short     = trim($_POST['short_name'] ?? '') ?: null;
            $desc      = trim($_POST['entity_description'] ?? '') ?: null;
            if ($name === '') throw new Exception('Entity name is required.');
            $sd = [];
            if (!empty($_POST['sd_key']) && is_array($_POST['sd_key'])) {
                foreach ($_POST['sd_key'] as $i => $k) {
                    $k = trim($k);
                    $v = trim($_POST['sd_val'][$i] ?? '');
                    if ($k !== '') $sd[$k] = $v;
                }
            }
            $sd_json  = !empty($sd) ? json_encode($sd) : '{}';
            $md_json  = '{"source_type":"manual","version":1}';
            $admin_id = $_SESSION['admin_id'];
            $stmt = $conn->prepare("INSERT INTO university_entities (entity_type_id, entity_code, university_id, parent_entity_id, name, short_name, description, structured_data, metadata, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('isiisisssi', $type_id, $code, $uni_id, $parent_id, $name, $short, $desc, $sd_json, $md_json, $admin_id);
            $stmt->execute();
            $notice = "Entity '$name' created.";
        } elseif ($action === 'update_entity') {
            $eid       = (int) $_POST['entity_id'];
            $type_id   = (int) $_POST['entity_type_id'];
            $code      = trim($_POST['entity_code'] ?? '') ?: null;
            $uni_id    = ($_POST['university_id'] ?? '') !== '' ? (int) $_POST['university_id'] : null;
            $parent_id = ($_POST['parent_entity_id'] ?? '') !== '' ? (int) $_POST['parent_entity_id'] : null;
            $name      = trim($_POST['entity_name'] ?? '');
            $short     = trim($_POST['short_name'] ?? '') ?: null;
            $desc      = trim($_POST['entity_description'] ?? '') ?: null;
            $active    = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') throw new Exception('Entity name is required.');
            $sd = [];
            if (!empty($_POST['sd_key']) && is_array($_POST['sd_key'])) {
                foreach ($_POST['sd_key'] as $i => $k) {
                    $k = trim($k);
                    $v = trim($_POST['sd_val'][$i] ?? '');
                    if ($k !== '') $sd[$k] = $v;
                }
            }
            $sd_json  = !empty($sd) ? json_encode($sd) : '{}';
            $md_json  = trim($_POST['metadata_json'] ?? '{}') ?: '{}';
            $admin_id = $_SESSION['admin_id'];
            $stmt = $conn->prepare("UPDATE university_entities SET entity_type_id=?, entity_code=?, university_id=?, parent_entity_id=?, name=?, short_name=?, description=?, structured_data=?, metadata=?, is_active=?, updated_by=? WHERE entity_id=?");
            $stmt->bind_param('isiisisssiis', $type_id, $code, $uni_id, $parent_id, $name, $short, $desc, $sd_json, $md_json, $active, $admin_id, $eid);
            $stmt->execute();
            $notice = "Entity '$name' updated.";
        } elseif ($action === 'delete_entity') {
            $eid = (int) $_POST['entity_id'];
            $cnt = $conn->query("SELECT COUNT(*) AS c FROM university_entities WHERE parent_entity_id=$eid")->fetch_assoc()['c'];
            if ($cnt > 0) throw new Exception("Cannot delete: entity has $cnt children. Delete children first.");
            $conn->query("DELETE FROM university_entities WHERE entity_id=$eid");
            $notice = 'Entity deleted.';
        } elseif ($action === 'save_chunk') {
            $eid        = (int) $_POST['chunk_entity_id'];
            $idx        = (int) $_POST['chunk_index'];
            $title      = trim($_POST['chunk_title'] ?? '');
            $content    = trim($_POST['chunk_content'] ?? '');
            if ($title === '' || $content === '') throw new Exception('Chunk title and content required.');
            $char_count = strlen($content);
            $stmt = $conn->prepare("INSERT INTO entity_knowledge_chunks (entity_id, chunk_index, title, content, char_count) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content), char_count=VALUES(char_count)");
            $stmt->bind_param('iissi', $eid, $idx, $title, $content, $char_count);
            $stmt->execute();
            $notice = 'Knowledge entry saved.';
        } elseif ($action === 'delete_chunk') {
            $cid = (int) $_POST['chunk_id'];
            $conn->query("DELETE FROM entity_knowledge_chunks WHERE chunk_id=$cid");
            $notice = 'Knowledge entry deleted.';
        } elseif ($action === 'update_chunk') {
            $cid        = (int) $_POST['chunk_id'];
            $title      = trim($_POST['chunk_title'] ?? '');
            $content    = trim($_POST['chunk_content'] ?? '');
            if ($title === '' || $content === '') throw new Exception('Chunk title and content required.');
            $char_count = strlen($content);
            $stmt = $conn->prepare("UPDATE entity_knowledge_chunks SET title=?, content=?, char_count=? WHERE chunk_id=?");
            $stmt->bind_param('ssii', $title, $content, $char_count, $cid);
            $stmt->execute();
            $notice = 'Knowledge entry updated.';
        } elseif ($action === 'bulk_delete') {
            if (!empty($_POST['entity_ids']) && is_array($_POST['entity_ids'])) {
                $ids    = array_map('intval', $_POST['entity_ids']);
                $idList = implode(',', $ids);
                $cnt    = $conn->query("SELECT COUNT(*) AS c FROM university_entities WHERE parent_entity_id IN ($idList)")->fetch_assoc()['c'];
                if ($cnt > 0) {
                    throw new Exception("Cannot delete selected: one or more entities have children ($cnt total). Delete children first.");
                } else {
                    $conn->query("DELETE FROM university_entities WHERE entity_id IN ($idList)");
                    $deleted = $conn->affected_rows;
                    $notice  = "Successfully deleted $deleted entit(ies).";
                }
            } else {
                throw new Exception("No entities selected.");
            }
        } elseif ($action === 'bulk_status') {
            if (!empty($_POST['entity_ids']) && is_array($_POST['entity_ids'])) {
                $ids    = array_map('intval', $_POST['entity_ids']);
                $idList = implode(',', $ids);
                $status = (int)($_POST['bulk_status_val'] ?? 0);
                $conn->query("UPDATE university_entities SET is_active = $status WHERE entity_id IN ($idList)");
                $updated = $conn->affected_rows;
                $notice  = "Successfully updated status for $updated entit(ies).";
            } else {
                throw new Exception("No entities selected.");
            }
        } elseif ($action === 'drop_all_entities') {
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            $conn->query("TRUNCATE TABLE entity_knowledge_chunks");
            $conn->query("TRUNCATE TABLE entity_relationships");
            $conn->query("TRUNCATE TABLE entity_history");
            $conn->query("TRUNCATE TABLE university_entities");
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            $notice = 'All entity data has been dropped successfully.';
        } elseif ($action === 'import_bulk_entities') {
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Error uploading file.");
            }
            $file = $_FILES['import_file']['tmp_name'];
            
            // Call Python parser for .xlsx
            $cmd = "/home/bcodz/Desktop/pjt-chatbot/backend/backend_env/bin/python /home/bcodz/Desktop/pjt-chatbot/scripts/parse_excel.py " . escapeshellarg($file) . " 2>&1";
            $json_output = shell_exec($cmd);
            $parsed_data = json_decode($json_output, true);
            
            if ($parsed_data === null) {
                throw new Exception("Failed to parse Excel file. Debug output: " . htmlspecialchars($json_output));
            }
            if (isset($parsed_data['error'])) {
                throw new Exception("Excel Parse Error: " . $parsed_data['error']);
            }
            
            $success_count = 0;
            $error_count = 0;
            $errors = [];
            
            // Read available entity types
            $types = [];
            $res = $conn->query("SELECT type_id, type_name FROM entity_types");
            while ($r = $res->fetch_assoc()) {
                $types[strtolower($r['type_name'])] = $r['type_id'];
            }
            
            $std_cols = ['Entity Name', 'Short Name', 'Entity Type Code', 'Entity Code', 'Parent Code', 'Description', 'Is Active'];
            
            foreach ($parsed_data as $row) {
                // Identify extra columns mapping to structured data
                $extra_col_keys = [];
                foreach ($row as $col_name => $val) {
                    $is_std = false;
                    foreach ($std_cols as $std) {
                        if (strtolower($col_name) === strtolower($std)) {
                            $is_std = true;
                            break;
                        }
                    }
                    if (!$is_std) {
                        $extra_col_keys[] = $col_name;
                    }
                }
                
                // Helper to get val case-insensitively
                $get_val = function($arr, $key) {
                    foreach ($arr as $k => $v) {
                        if (strtolower($k) === strtolower($key)) return $v;
                    }
                    return null;
                };
                
                $name = trim((string)($get_val($row, 'Entity Name') ?? ''));
                if (empty($name)) continue; // Skip malformed rows
                
                $short_name = trim((string)($get_val($row, 'Short Name') ?? '')) ?: null;
                $type_code = strtolower(trim((string)($get_val($row, 'Entity Type Code') ?? '')));
                $entity_code = trim((string)($get_val($row, 'Entity Code') ?? '')) ?: null;
                $parent_code = trim((string)($get_val($row, 'Parent Code') ?? '')) ?: null;
                $description = trim((string)($get_val($row, 'Description') ?? '')) ?: null;
                $is_act_val = $get_val($row, 'Is Active');
                $is_active = (trim((string)($is_act_val ?? '1')) === '1' || strtolower(trim((string)($is_act_val ?? ''))) === 'true') ? 1 : 0;
                
                // Extract structured data from extra columns
                $sd = [];
                foreach ($extra_col_keys as $key_name) {
                    $v = $row[$key_name];
                    if ($v !== null && trim((string)$v) !== '') {
                        $sd[$key_name] = trim((string)$v);
                    }
                }
                $sd_json = !empty($sd) ? json_encode($sd) : '{}';
                    
                    if (empty($name) || empty($type_code)) {
                        $error_count++;
                        $errors[] = "Row missing Name or Type Code.";
                        continue;
                    }
                    
                    if (!isset($types[$type_code])) {
                        $error_count++;
                        $errors[] = "Unknown Type Code: '$type_code' for Entity: '$name'.";
                        continue;
                    }
                    
                    $type_id = $types[$type_code];
                    $parent_id = null;
                    
                    if (!empty($parent_code)) {
                        $p_stmt = $conn->prepare("SELECT entity_id FROM university_entities WHERE entity_code = ? LIMIT 1");
                        $p_stmt->bind_param('s', $parent_code);
                        $p_stmt->execute();
                        $p_res = $p_stmt->get_result()->fetch_assoc();
                        if ($p_res) {
                            $parent_id = $p_res['entity_id'];
                        } else {
                            $error_count++;
                            $errors[] = "Parent Code '$parent_code' not found for Entity: '$name'.";
                            continue;
                        }
                    }
                    
                    $admin_id = $_SESSION['admin_id'];
                    
                    // Upsert based on entity_code if provided, else insert
                    if (!empty($entity_code)) {
                        $c_stmt = $conn->prepare("SELECT entity_id FROM university_entities WHERE entity_code = ? LIMIT 1");
                        $c_stmt->bind_param('s', $entity_code);
                        $c_stmt->execute();
                        $c_res = $c_stmt->get_result()->fetch_assoc();
                        
                        if ($c_res) {
                            // Update
                            $eid = $c_res['entity_id'];
                            $u_stmt = $conn->prepare("UPDATE university_entities SET entity_type_id=?, parent_entity_id=?, name=?, short_name=?, description=?, structured_data=?, is_active=?, updated_by=? WHERE entity_id=?");
                            $u_stmt->bind_param('iisssssiii', $type_id, $parent_id, $name, $short_name, $description, $sd_json, $is_active, $admin_id, $eid);
                            if ($u_stmt->execute()) $success_count++;
                            continue;
                        }
                    }
                    
                    // Insert
                    $md_json = '{"source_type":"bulk_import","version":1}';
                    $i_stmt = $conn->prepare("INSERT INTO university_entities (entity_type_id, entity_code, parent_entity_id, name, short_name, description, structured_data, metadata, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $i_stmt->bind_param('isisssssii', $type_id, $entity_code, $parent_id, $name, $short_name, $description, $sd_json, $md_json, $is_active, $admin_id);
                    if ($i_stmt->execute()) $success_count++;
                }
                
                if ($success_count > 0) {
                    $notice = "Bulk import completed: $success_count imported/updated.";
                }
                if ($error_count > 0) {
                    $error_msg = "$error_count rows failed to import. <br>" . implode("<br>", array_slice($errors, 0, 5)) . (count($errors) > 5 ? "<br>...and more." : "");
                    if ($success_count > 0) {
                        $notice .= "<br><span style='color:#dc2626;'>" . $error_msg . "</span>";
                    } else {
                        throw new Exception($error_msg);
                    }
                }
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// ---- Fetch Data ----
$entity_types = [];
$res = $conn->query("SELECT * FROM entity_types ORDER BY display_order, type_label");
if ($res) while ($r = $res->fetch_assoc()) $entity_types[] = $r;

$filter_type = isset($_GET['type']) ? (int) $_GET['type'] : 0;
$filter_q    = trim($_GET['q'] ?? '');
$where       = 'WHERE 1=1';
if ($filter_type > 0)  $where .= " AND e.entity_type_id=$filter_type";
if ($filter_q !== '')  $where .= " AND (e.name LIKE '%" . $conn->real_escape_string($filter_q) . "%' OR e.entity_code LIKE '%" . $conn->real_escape_string($filter_q) . "%')";

$entities = [];
$res = $conn->query("SELECT e.*, et.type_name, et.type_label, et.icon, p.name AS parent_name,
    (SELECT COUNT(*) FROM university_entities c WHERE c.parent_entity_id=e.entity_id) AS children_count,
    (SELECT COUNT(*) FROM entity_knowledge_chunks ck WHERE ck.entity_id=e.entity_id) AS chunk_count
    FROM university_entities e INNER JOIN entity_types et ON e.entity_type_id=et.type_id
    LEFT JOIN university_entities p ON e.parent_entity_id=p.entity_id $where ORDER BY e.display_order, e.name LIMIT 500");
if ($res) while ($r = $res->fetch_assoc()) $entities[] = $r;

$all_entities = [];
$res2 = $conn->query("SELECT e.entity_id, e.entity_type_id, e.entity_code, e.parent_entity_id, e.name, e.short_name, e.is_active, e.description,
    et.type_name, et.type_label, et.icon,
    (SELECT COUNT(*) FROM entity_knowledge_chunks ck WHERE ck.entity_id=e.entity_id) AS chunk_count
    FROM university_entities e INNER JOIN entity_types et ON e.entity_type_id=et.type_id ORDER BY e.display_order, e.name");
if ($res2) while ($r = $res2->fetch_assoc()) $all_entities[] = $r;

$edit_entity = null;
$edit_chunks = [];
if (isset($_GET['edit'])) {
    $eid = (int) $_GET['edit'];
    $res = $conn->query("SELECT e.*, et.type_name, et.type_label, et.icon, et.field_schema FROM university_entities e INNER JOIN entity_types et ON e.entity_type_id=et.type_id WHERE e.entity_id=$eid");
    if ($res) $edit_entity = $res->fetch_assoc();
    if ($edit_entity) {
        $cres = $conn->query("SELECT * FROM entity_knowledge_chunks WHERE entity_id=$eid ORDER BY chunk_index");
        if ($cres) while ($r = $cres->fetch_assoc()) $edit_chunks[] = $r;
    }
}

$edit_type = null;
if (isset($_GET['edit_type'])) {
    $tid = (int) $_GET['edit_type'];
    foreach ($entity_types as $et) {
        if ($et['type_id'] == $tid) {
            $edit_type = $et;
            break;
        }
    }
}

$type_stats = [];
$res = $conn->query("SELECT et.type_name, et.type_label, et.icon, COUNT(e.entity_id) AS cnt FROM entity_types et LEFT JOIN university_entities e ON et.type_id=e.entity_type_id GROUP BY et.type_id ORDER BY et.display_order");
if ($res) while ($r = $res->fetch_assoc()) $type_stats[] = $r;

// Aggregate totals for command bar
$total_entities = count($all_entities);
$total_types    = count($entity_types);
$total_chunks   = 0;
foreach ($all_entities as $ae) $total_chunks += (int)($ae['chunk_count'] ?? 0);
$active_entities = count(array_filter($all_entities, fn($e) => $e['is_active']));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Entity Manager — Knowledge Base</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"
        integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg=="
        crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link rel="stylesheet" href="css/admin.css">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ===================================================
           ENTERPRISE ENTITY MANAGER — Design System
        =================================================== */
        :root {
            --em-bg: #f0f2f7;
            --em-surface: #ffffff;
            --em-surface-2: #f8f9fc;
            --em-border: #e2e6ef;
            --em-border-soft: #eef0f6;
            --em-primary: #002147;
            --em-primary-mid: #05356b;
            --em-accent: #1a6ef7;
            --em-accent-soft: #e8f0fe;
            --em-success: #059669;
            --em-success-bg: #ecfdf5;
            --em-warn: #d97706;
            --em-warn-bg: #fffbeb;
            --em-danger: #dc2626;
            --em-danger-bg: #fef2f2;
            --em-text: #111827;
            --em-text-2: #374151;
            --em-text-3: #6b7280;
            --em-text-4: #9ca3af;
            --em-mono: 'IBM Plex Mono', monospace;
            --em-sans: 'Sora', sans-serif;
            --em-radius: 10px;
            --em-radius-lg: 14px;
            --em-shadow: 0 1px 4px rgba(0, 0, 0, .07), 0 4px 18px rgba(0, 0, 0, .04);
            --em-shadow-md: 0 2px 8px rgba(0, 0, 0, .09), 0 8px 28px rgba(0, 0, 0, .06);
        }

        /* ─── Page Wrapper ─── */
        .em-page {
            font-family: var(--em-sans);
            background: var(--em-bg);
            min-height: 100vh;
            padding: 010px 20px;
        }

        /* ─── Command Bar ─── */
        .em-command-bar {
            background: #05356b;
            border-bottom: 3px solid var(--em-accent);
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .em-command-bar .em-title {
            font-family: var(--em-sans);
            font-weight: 700;
            font-size: 1.05rem;
            color: #fff;
            letter-spacing: -.01em;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .em-command-bar .em-title i {
            color: #7db3ff;
            font-size: .95rem;
        }

        .em-command-bar .em-stats-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            flex: 1;
        }

        .em-stat-pill {
            background: rgba(255, 255, 255, .1);
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 20px;
            padding: 4px 12px;
            font-size: .72rem;
            color: rgba(255, 255, 255, .85);
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: var(--em-mono);
        }

        .em-stat-pill strong {
            color: #fff;
        }

        .em-command-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
        }

        .em-btn-cmd {
            font-size: .78rem;
            padding: 5px 12px;
            border-radius: 6px;
            font-family: var(--em-sans);
            font-weight: 500;
            border: 1px solid rgba(255, 255, 255, .2);
            background: rgba(255, 255, 255, .08);
            color: #fff;
            cursor: pointer;
            transition: all .15s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .em-btn-cmd:hover {
            background: rgba(255, 255, 255, .18);
            color: #fff;
        }

        .em-btn-cmd.em-btn-cmd--danger {
            border-color: rgba(239, 68, 68, .5);
            color: #fca5a5;
        }

        .em-btn-cmd.em-btn-cmd--danger:hover {
            background: rgba(239, 68, 68, .2);
        }

        .em-btn-cmd.em-btn-cmd--accent {
            background: var(--em-accent);
            border-color: var(--em-accent);
        }

        .em-btn-cmd.em-btn-cmd--accent:hover {
            background: #1558d0;
        }

        /* ─── Alert Banner ─── */
        .em-alert {
            margin: 16px 24px 0;
            padding: 12px 18px;
            border-radius: var(--em-radius);
            font-size: .85rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .em-alert--success {
            background: var(--em-success-bg);
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .em-alert--error {
            background: var(--em-danger-bg);
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .em-alert i {
            flex-shrink: 0;
        }

        /* ─── Main Layout ─── */
        .em-body {
            display: flex;
            gap: 0;
            height: 100%;
            overflow: hidden;
        }

        /* ─── Left Navigator Panel ─── */
        .em-nav-panel {
            width: 220px;
            flex-shrink: 0;
            background: var(--em-surface);
            border-right: 1px solid var(--em-border);
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .em-nav-section {
            padding: 16px 12px 6px;
        }

        .em-nav-section-label {
            font-size: .65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--em-text-4);
            padding: 0 6px;
            margin-bottom: 4px;
        }

        .em-nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 7px;
            cursor: pointer;
            font-size: .82rem;
            font-weight: 500;
            color: var(--em-text-2);
            text-decoration: none;
            transition: all .13s;
            position: relative;
        }

        .em-nav-item:hover {
            background: var(--em-accent-soft);
            color: var(--em-accent);
        }

        .em-nav-item.active {
            background: var(--em-accent-soft);
            color: var(--em-accent);
            font-weight: 600;
        }

        .em-nav-item.active::before {
            content: '';
            position: absolute;
            left: -1px;
            top: 20%;
            height: 60%;
            width: 3px;
            background: var(--em-accent);
            border-radius: 0 3px 3px 0;
        }

        .em-nav-item i {
            width: 18px;
            text-align: center;
            font-size: .8rem;
            flex-shrink: 0;
        }

        .em-nav-badge {
            margin-left: auto;
            background: var(--em-accent-soft);
            color: var(--em-accent);
            border-radius: 10px;
            font-size: .65rem;
            padding: 1px 7px;
            font-weight: 700;
            font-family: var(--em-mono);
        }

        .em-nav-divider {
            height: 1px;
            background: var(--em-border-soft);
            margin: 8px 12px;
        }

        /* ─── Main Content Area ─── */
        .em-content-area {
            flex: 1;
            overflow-y: auto;
            background: var(--em-bg);
        }

        /* ─── Section Header ─── */
        .em-section-header {
            background: var(--em-surface);
            border-bottom: 1px solid var(--em-border);
            padding: 18px 28px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .em-section-header .em-section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--em-text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .em-section-header .em-section-title i {
            width: 32px;
            height: 32px;
            background: var(--em-primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .8rem;
            flex-shrink: 0;
        }

        .em-section-subtitle {
            font-size: .78rem;
            color: var(--em-text-3);
            font-weight: 400;
            margin-top: 1px;
            margin-left:50px;
            padding: 10px;
        }

        /* ─── Panels / Cards ─── */
        .em-panel {
            background: var(--em-surface);
            border-radius: var(--em-radius-lg);
            border: 1px solid var(--em-border);
            box-shadow: var(--em-shadow);
            overflow: hidden;
        }

        .em-panel-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--em-border-soft);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--em-surface-2);
        }

        .em-panel-header .em-panel-title {
            font-size: .88rem;
            font-weight: 700;
            color: var(--em-text);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .em-panel-header .em-panel-title i {
            color: var(--em-accent);
        }

        .em-panel-body {
            padding: 20px;
        }

        /* ─── Stat Grid ─── */
        .em-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            justify-content: center;
        }

        .em-stat-card {
            background: var(--em-surface);
            border: 1px solid var(--em-border);
            border-radius: var(--em-radius);
            padding: 20px 22px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: box-shadow .15s;
            cursor: default;
        }

        .em-stat-card:hover {
            box-shadow: var(--em-shadow-md);
        }

        .em-stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--em-primary), var(--em-primary-mid));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .em-stat-body .em-stat-value {
            font-size: 1.95rem;
            font-weight: 700;
            color: var(--em-text);
            line-height: 1;
            font-family: var(--em-mono);
        }

        .em-stat-body .em-stat-label {
            font-size: .8rem;
            color: var(--em-text-3);
            margin-top: 5px;
            font-weight: 500;
        }

        /* ─── Toolbar ─── */
        .em-toolbar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            padding: 14px 20px;
            border-bottom: 1px solid var(--em-border-soft);
            background: var(--em-surface-2);
        }

        .em-search-box {
            position: relative;
            flex: 1;
            min-width: 200px;
            max-width: 340px;
        }

        .em-search-box i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--em-text-4);
            font-size: .8rem;
        }

        .em-search-box input {
            width: 100%;
            padding: 7px 12px 7px 32px;
            border: 1px solid var(--em-border);
            border-radius: 7px;
            font-size: .82rem;
            background: var(--em-surface);
            color: var(--em-text);
            outline: none;
            transition: border-color .15s;
            font-family: var(--em-sans);
        }

        .em-search-box input:focus {
            border-color: var(--em-accent);
            box-shadow: 0 0 0 3px rgba(26, 110, 247, .1);
        }

        .em-select {
            border: 1px solid var(--em-border);
            border-radius: 7px;
            padding: 6px 10px;
            font-size: .82rem;
            font-family: var(--em-sans);
            color: var(--em-text);
            background: var(--em-surface);
            outline: none;
            cursor: pointer;
        }

        .em-select:focus {
            border-color: var(--em-accent);
        }

        /* ─── Buttons ─── */
        .em-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 7px;
            font-size: .8rem;
            font-weight: 600;
            font-family: var(--em-sans);
            cursor: pointer;
            border: 1px solid transparent;
            transition: all .13s;
            text-decoration: none;
            white-space: nowrap;
        }

        .em-btn--primary {
            background: var(--em-accent);
            color: #fff;
            border-color: var(--em-accent);
        }

        .em-btn--primary:hover {
            background: #1558d0;
            color: #fff;
        }

        .em-btn--outline {
            background: transparent;
            color: var(--em-text-2);
            border-color: var(--em-border);
        }

        .em-btn--outline:hover {
            border-color: var(--em-accent);
            color: var(--em-accent);
            background: var(--em-accent-soft);
        }

        .em-btn--danger {
            background: transparent;
            color: var(--em-danger);
            border-color: #fca5a5;
        }

        .em-btn--danger:hover {
            background: #05356b;
        }

        .em-btn--success {
            background: var(--em-success);
            color: #fff;
            border-color: var(--em-success);
        }

        .em-btn--success:hover {
            background: #047857;
            color: #fff;
        }

        .em-btn--ghost {
            background: transparent;
            border-color: transparent;
            color: var(--em-text-3);
        }

        .em-btn--ghost:hover {
            background: var(--em-bg);
            color: var(--em-text-2);
        }

        .em-btn--sm {
            padding: 4px 10px;
            font-size: .75rem;
        }

        .em-btn--icon {
            padding: 6px 8px;
        }

        /* ─── Data Table ─── */
        .em-table-wrap {
            overflow-x: auto;
        }

        .em-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .82rem;
        }

        .em-table thead th {
            background: var(--em-surface-2);
            border-bottom: 2px solid var(--em-border);
            padding: 10px 14px;
            text-align: left;
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--em-text-3);
            white-space: nowrap;
            position: sticky;
            top: 0;
        }

        .em-table thead th:first-child {
            width: 36px;
        }

        .em-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--em-border-soft);
            vertical-align: middle;
            color: var(--em-text-2);
        }

        .em-table tbody tr:last-child td {
            border-bottom: none;
        }

        .em-table tbody tr:hover td {
            background: var(--em-bg);
        }

        /* ─── Badges ─── */
        .em-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: .68rem;
            font-weight: 600;
            font-family: var(--em-mono);
        }

        .em-badge--green {
            background: var(--em-success-bg);
            color: var(--em-success);
        }

        .em-badge--blue {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .em-badge--red {
            background: var(--em-danger-bg);
            color: var(--em-danger);
        }

        .em-badge--gray {
            background: #f3f4f6;
            color: var(--em-text-3);
        }

        .em-badge--yellow {
            background: var(--em-warn-bg);
            color: var(--em-warn);
        }

        /* ─── Type Icon Circle ─── */
        .em-type-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: linear-gradient(135deg, var(--em-primary), var(--em-primary-mid));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: .7rem;
            flex-shrink: 0;
        }

        /* ─── Entity Name cell ─── */
        .em-entity-name {
            font-weight: 400;
            font-size: 0.8rem;
            color: var(--em-text);
        }

        .em-entity-code {
            font-family: var(--em-mono);
            font-size: .72rem;
            color: var(--em-text-4);
            background: var(--em-bg);
            padding: 1px 6px;
            border-radius: 4px;
            border: 1px solid var(--em-border);
        }

        /* ─── Hierarchy Tree ─── */
        .em-tree {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .em-tree-branch {
            margin-left: 24px;
            border-left: 2px solid var(--em-border);
        }

        .em-tree-node {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: all .12s;
            margin: 2px 0;
            border: 1px solid transparent;
        }

        .em-tree-node:hover {
            background: var(--em-accent-soft);
            border-color: #c7d2fe;
        }

        .em-tree-toggle {
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            color: var(--em-text-4);
            flex-shrink: 0;
            background: var(--em-bg);
            font-size: .65rem;
        }

        .em-tree-info {
            flex: 1;
            min-width: 0;
        }

        .em-tree-name {
            font-weight: 600;
            font-size: .85rem;
            color: var(--em-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .em-tree-desc {
            font-size: .72rem;
            color: var(--em-text-3);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .em-tree-type {
            font-size: .68rem;
            color: var(--em-accent);
            background: var(--em-accent-soft);
            padding: 2px 7px;
            border-radius: 10px;
            font-weight: 600;
            flex-shrink: 0;
        }

        /* ─── Form ─── */
        .em-form-section {
            margin-bottom: 24px;
        }

        .em-form-section-title {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .09em;
            color: var(--em-text-3);
            border-bottom: 1px solid var(--em-border-soft);
            padding-bottom: 8px;
            margin-bottom: 14px;
        }

        .em-form-row {
            display: grid;
            gap: 14px;
            margin-bottom: 14px;
        }

        .em-form-row.cols-2 {
            grid-template-columns: 1fr 1fr;
        }

        .em-form-row.cols-3 {
            grid-template-columns: 1fr 1fr 1fr;
        }

        .em-form-row.cols-4 {
            grid-template-columns: 1fr 1fr 1fr 1fr;
        }

        .em-label {
            display: block;
            font-size: .76rem;
            font-weight: 600;
            color: var(--em-text-2);
            margin-bottom: 5px;
        }

        .em-label .em-req {
            color: var(--em-danger);
            margin-left: 2px;
        }

        .em-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--em-border);
            border-radius: 7px;
            font-size: .83rem;
            font-family: var(--em-sans);
            color: var(--em-text);
            background: var(--em-surface);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }

        .em-input:focus {
            border-color: var(--em-accent);
            box-shadow: 0 0 0 3px rgba(26, 110, 247, .1);
        }

        .em-textarea {
            resize: vertical;
            min-height: 80px;
        }

        .em-checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .82rem;
            color: var(--em-text-2);
            padding: 8px 0;
        }

        .em-checkbox-row input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
            accent-color: var(--em-accent);
        }

        /* ─── Structured Data Rows ─── */
        .em-sd-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            align-items: center;
        }

        .em-sd-row .em-input {
            flex: 1;
        }

        .em-sd-label-hint {
            font-size: .72rem;
            color: var(--em-text-4);
            margin-bottom: 10px;
        }

        /* ─── Knowledge Chunks ─── */
        .em-chunk-card {
            border: 1px solid var(--em-border);
            border-radius: var(--em-radius);
            overflow: hidden;
            margin-bottom: 10px;
            transition: box-shadow .13s;
        }

        .em-chunk-card:hover {
            box-shadow: var(--em-shadow);
        }

        .em-chunk-header {
            padding: 10px 16px;
            background: var(--em-surface-2);
            border-bottom: 1px solid var(--em-border-soft);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .em-chunk-index {
            font-family: var(--em-mono);
            font-size: .7rem;
            font-weight: 700;
            color: var(--em-accent);
            background: var(--em-accent-soft);
            padding: 2px 8px;
            border-radius: 4px;
            flex-shrink: 0;
        }

        .em-chunk-title {
            font-weight: 600;
            font-size: .85rem;
            color: var(--em-text);
            flex: 1;
        }

        .em-chunk-body {
            padding: 12px 16px;
        }

        .em-chunk-text {
            font-size: .82rem;
            color: var(--em-text-2);
            line-height: 1.6;
        }

        .em-chunk-meta {
            display: flex;
            gap: 12px;
            padding: 8px 16px;
            background: var(--em-bg);
            border-top: 1px solid var(--em-border-soft);
            font-size: .7rem;
            color: var(--em-text-4);
            font-family: var(--em-mono);
        }

        /* ─── Empty State ─── */
        .em-empty {
            text-align: center;
            padding: 48px 24px;
            color: var(--em-text-4);
        }

        .em-empty i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            display: block;
            opacity: .4;
        }

        .em-empty p {
            font-size: .85rem;
        }

        /* ─── Content Panes ─── */
        .em-pane {
            display: none;
            height: 100%;
        }

        .em-pane.active {
            display: flex;
            flex-direction: column;
        }

        .em-pane-inner {
            flex: 1;
            overflow-y: auto;
            padding: 24px 28px;
        }

        /* ─── Responsive Adjustments ─── */
        @media (max-width: 768px) {
            .em-nav-panel {
                display: none;
            }

            .em-form-row.cols-2,
            .em-form-row.cols-3,
            .em-form-row.cols-4 {
                grid-template-columns: 1fr;
            }

            .em-pane-inner {
                padding: 16px;
            }
        }

        /* ─── Danger Zone ─── */
        .em-danger-zone {
            border: 2px solid var(--em-danger);
            border-radius: var(--em-radius-lg);
            padding: 24px 28px;
            background: #fffafa;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.1);
        }

        .em-danger-zone::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 6px;
            height: 100%;
            background: var(--em-danger);
        }

        .em-danger-zone-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--em-danger);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            letter-spacing: .02em;
        }

        .em-danger-zone-desc {
            font-size: .88rem;
            color: #7f1d1d;
            margin-bottom: 24px;
            line-height: 1.5;
        }

        /* ─── Edit Entity two-column layout ─── */
        .em-edit-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
            align-items: flex-start;
        }

        @media (max-width: 1100px) {
            .em-edit-layout {
                grid-template-columns: 1fr;
            }
        }

        /* ─── Tab active flash ─── */
        .em-nav-item {
            outline: none;
        }
    </style>
</head>

<body>
      <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>
    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <div class="sb2-2 col-md-9" style="padding:0;">

                <!-- =================== ENTITY MANAGER APP =================== -->
                <div class="em-page">

                    <!-- ── Command Bar ── -->
                    <div class="em-command-bar">
                        <div class="em-title">
                            <i class="fa-solid fa-database"></i>
                            Entity &amp; Knowledge Manager
                        </div>
                        <div class="em-stats-pills">
                            <div class="em-stat-pill"><strong><?= $total_entities ?></strong> entities</div>
                            <div class="em-stat-pill"><strong><?= $active_entities ?></strong> active</div>
                            <div class="em-stat-pill"><strong><?= $total_types ?></strong> types</div>
                            <div class="em-stat-pill"><strong><?= $total_chunks ?></strong> KB chunks</div>
                        </div>
                        <div class="em-command-actions">
                            <a href="web_scraper.php" class="em-btn-cmd"><i class="fa-solid fa-spider"></i> Web Scraper</a>
                            <button class="em-btn-cmd" onclick="enrichAllPendingKB(this, {reloadOnSuccess:true})"><i class="fa-solid fa-wand-magic-sparkles"></i> Enrich All</button>
                            <button class="em-btn-cmd" onclick="enrichAndReindexKB(this, {reloadOnSuccess:true})"><i class="fa-solid fa-layer-group"></i> Enrich All &amp; Reindex</button>
                            <button class="em-btn-cmd" onclick="reindexKB(this)"><i class="fa-solid fa-rotate"></i> Rebuild Index</button>
                            <button class="em-btn-cmd em-btn-cmd--danger" data-bs-toggle="modal" data-bs-target="#dangerZoneModal" title="Danger Zone">
                                <i class="fa-solid fa-triangle-exclamation"></i> Drop ALL Data
                            </button>
                        </div>
                    </div>

                    <!-- ── Alerts ── -->
                    <?php if ($notice): ?>
                        <div class="em-alert em-alert--success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($notice) ?></div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="em-alert em-alert--error"><i class="fa-solid fa-circle-xmark"></i><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <!-- ── App Body ── -->
                    <div class="em-body">

                        <!-- ════ LEFT NAVIGATOR ════ -->
                        <nav class="em-nav-panel">
                            <div class="em-nav-section">
                                <div class="em-nav-section-label">Views</div>
                                <a class="em-nav-item <?= !isset($_GET['edit']) && !isset($_GET['edit_type']) && (!isset($_GET['tab']) || $_GET['tab'] === 'tree') ? 'active' : '' ?>"
                                    href="#" onclick="switchPane('pane-tree',this);return false;">
                                    <i class="fa-solid fa-sitemap"></i> Hierarchy
                                    <span class="em-nav-badge"><?= $total_entities ?></span>
                                </a>
                                <a class="em-nav-item <?= isset($_GET['tab']) && $_GET['tab'] === 'table' ? 'active' : '' ?>"
                                    href="#" onclick="switchPane('pane-table',this);return false;">
                                    <i class="fa-solid fa-table-list"></i> All Entities
                                    <span class="em-nav-badge"><?= count($entities) ?></span>
                                </a>
                                <a class="em-nav-item <?= isset($_GET['edit_type']) ? 'active' : '' ?>"
                                    href="#" onclick="switchPane('pane-types',this);return false;">
                                    <i class="fa-solid fa-tags"></i> Entity Types
                                    <span class="em-nav-badge"><?= $total_types ?></span>
                                </a>
                            </div>
                            <div class="em-nav-divider"></div>
                            <div class="em-nav-section">
                                <div class="em-nav-section-label">Actions</div>
                                <a class="em-nav-item"
                                    href="#" onclick="switchPane('pane-add',this);return false;">
                                    <i class="fa-solid fa-plus"></i> New Entity
                                </a>
                                <a class="em-nav-item"
                                    href="#" onclick="openImportModal();return false;">
                                    <i class="fa-solid fa-file-excel"></i> Bulk Import
                                </a>
                                <?php if ($edit_entity): ?>
                                    <a class="em-nav-item active" href="#" onclick="switchPane('pane-edit',this);return false;">
                                        <i class="fa-solid fa-pen-to-square"></i> Editing
                                        <span class="em-nav-badge" style="background:#fff3cd;color:#92400e;">open</span>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="em-nav-divider"></div>
                            <!-- Type breakdown -->
                            <div class="em-nav-section">
                                <div class="em-nav-section-label">By Type</div>
                                <?php foreach ($type_stats as $ts): ?>
                                    <a class="em-nav-item" href="?type=<?= urlencode($ts['type_name']) ?>&tab=table"
                                        style="font-size:.75rem;">
                                        <i class="fa-solid <?= htmlspecialchars($ts['icon']) ?>" style="color:var(--em-accent)"></i>
                                        <?= htmlspecialchars($ts['type_label']) ?>
                                        <span class="em-nav-badge"><?= $ts['cnt'] ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </nav>

                        <!-- ════ MAIN CONTENT ════ -->
                        <div class="em-content-area" id="emContentArea">

                            <!-- ===== PANE: HIERARCHY ===== -->
                            <div class="em-pane <?= !isset($_GET['edit']) && !isset($_GET['edit_type']) && (!isset($_GET['tab']) || $_GET['tab'] === 'tree') ? 'active' : '' ?>" id="pane-tree">
                                <div class="em-section-header">
                                    <div>
                                        <div class="em-section-title">
                                            <i class="fa-solid fa-sitemap"></i>
                                            Entity Hierarchy
                                        </div>
                                        <div class="em-section-subtitle">Visual tree of all entities and their parent-child relationships</div>
                                    </div>
                                    <div class="em-search-box">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                        <input type="text" id="treeSearch" placeholder="Search hierarchy…" oninput="filterTree(this.value)">
                                    </div>
                                </div>
                                <div class="em-pane-inner">
                                    <div id="entityTree"></div>
                                    <div id="treeEmpty" style="display:none">
                                        <div class="em-empty">
                                            <i class="fa-solid fa-folder-open"></i>
                                            <p>No entities yet. Use <strong>New Entity</strong> to add one.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===== PANE: ALL ENTITIES TABLE ===== -->
                            <div class="em-pane <?= isset($_GET['tab']) && $_GET['tab'] === 'table' ? 'active' : '' ?>" id="pane-table">
                                <div class="em-section-header">
                                    <div>
                                        <div class="em-section-title">
                                            <i class="fa-solid fa-table-list"></i>
                                            All Entities
                                        </div>
                                        <div class="em-section-subtitle">Filter, search, bulk-manage and export entity records</div>
                                    </div>
                                </div>

                                <!-- Stats row -->
                                <div style="padding:16px 28px 0;">
                                    <div class="em-stat-grid">
                                        <?php foreach (array_slice($type_stats, 0, 6) as $ts): ?>
                                            <div class="em-stat-card">
                                                <div class="em-stat-icon"><i class="fa-solid <?= htmlspecialchars($ts['icon']) ?>"></i></div>
                                                <div class="em-stat-body">
                                                    <div class="em-stat-value"><?= $ts['cnt'] ?></div>
                                                    <div class="em-stat-label"><?= htmlspecialchars($ts['type_label']) ?></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <!-- Toolbar -->
                                <form class="em-toolbar" method="GET" id="filterForm">
                                    <input type="hidden" name="tab" value="table">
                                    <div class="em-search-box">
                                        <i class="fa-solid fa-magnifying-glass"></i>
                                        <input type="text" name="q" placeholder="Search name or code…" value="<?= htmlspecialchars($filter_q) ?>">
                                    </div>
                                    <select name="type" class="em-select">
                                        <option value="0">All Types</option>
                                        <?php foreach ($entity_types as $et): ?>
                                            <option value="<?= $et['type_id'] ?>" <?= $filter_type == $et['type_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($et['type_label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="em-btn em-btn--primary em-btn--sm"><i class="fa-solid fa-filter"></i> Filter</button>
                                    <?php if ($filter_q || $filter_type): ?>
                                        <a href="?tab=table" class="em-btn em-btn--ghost em-btn--sm"><i class="fa-solid fa-xmark"></i> Clear</a>
                                    <?php endif; ?>
                                    <div style="margin-left:auto; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <!-- Bulk Action Controls -->
                                        <select class="em-select" id="bulkActionSelect" style="font-size:.75rem;">
                                            <option value="">Bulk Actions…</option>
                                            <option value="bulk_status">Change Status</option>
                                            <option value="bulk_delete">Delete Selected</option>
                                        </select>
                                        <select class="em-select" id="bulkStatusSelect" style="display:none; font-size:.75rem;">
                                            <option value="1">Set Active</option>
                                            <option value="0">Set Inactive</option>
                                        </select>
                                        <button type="button" class="em-btn em-btn--outline em-btn--sm" onclick="executeBulkAction()">Apply</button>
                                    </div>
                                </form>

                                <form method="POST" id="bulkActionsForm">
                                    <div class="em-table-wrap">
                                        <table class="em-table">
                                            <thead>
                                                <tr>
                                                    <th><input type="checkbox" id="selectAllEntities" style="accent-color:var(--em-accent)"></th>
                                                    <th>ID</th>
                                                    <th>Type</th>
                                                    <th>Code</th>
                                                    <th>Name</th>
                                                    <th>Parent</th>
                                                    <th>Details</th>
                                                    <th>KB Chunks</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($entities as $e):
                                                    $sd = json_decode($e['structured_data'] ?? '{}', true) ?: [];
                                                    $details_parts = [];
                                                    foreach ($sd as $k => $v) {
                                                        if ($v !== '' && $v !== null)
                                                            $details_parts[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $v;
                                                    }
                                                    $details_str = implode(' · ', array_slice($details_parts, 0, 2));
                                                ?>
                                                    <tr>
                                                        <td><input type="checkbox" name="entity_ids[]" value="<?= $e['entity_id'] ?>" class="entity-checkbox" style="accent-color:var(--em-accent)"></td>
                                                        <td><span class="em-entity-code"><?= $e['entity_id'] ?></span></td>
                                                        <td>
                                                            <div style="display:flex;align-items:center;gap:7px;">
                                                                <div class="em-type-icon" style="width:22px;height:22px;border-radius:5px;font-size:.62rem;">
                                                                    <i class="fa-solid <?= htmlspecialchars($e['icon']) ?>"></i>
                                                                </div>
                                                                <span style="font-size:.78rem;color:var(--em-text-3)"><?= htmlspecialchars($e['type_label']) ?></span>
                                                            </div>
                                                        </td>
                                                        <td><code class="em-entity-code"><?= htmlspecialchars($e['entity_code'] ?? '—') ?></code></td>
                                                        <td>
                                                            <div class="em-entity-name"><?= htmlspecialchars($e['name']) ?></div>
                                                            <?php if ($e['short_name']): ?>
                                                                <div style="font-size:.72rem;color:var(--em-text-4)"><?= htmlspecialchars($e['short_name']) ?></div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td style="color:var(--em-text-3);font-size:.78rem;"><?= htmlspecialchars($e['parent_name'] ?? '—') ?></td>
                                                        <td><small style="color:var(--em-text-3)"><?= htmlspecialchars($details_str ?: '—') ?></small></td>
                                                        <td>
                                                            <?php if ($e['chunk_count'] > 0): ?>
                                                                <span class="em-badge em-badge--green"><i class="fa-solid fa-book-open"></i><?= $e['chunk_count'] ?></span>
                                                            <?php else: ?>
                                                                <span class="em-badge em-badge--gray">0</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= $e['is_active']
                                                                ? '<span class="em-badge em-badge--green"><i class="fa-solid fa-circle" style="font-size:.45rem"></i> Active</span>'
                                                                : '<span class="em-badge em-badge--red"><i class="fa-solid fa-circle" style="font-size:.45rem"></i> Inactive</span>' ?>
                                                        </td>
                                                        <td>
                                                            <div style="display:flex;gap:5px;">
                                                                <a href="?edit=<?= $e['entity_id'] ?>" class="em-btn em-btn--outline em-btn--sm em-btn--icon" title="Edit">
                                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                                </a>
                                                                <form method="POST" style="display:inline" id="delEntityForm-<?= $e['entity_id'] ?>">
                                                                    <input type="hidden" name="action" value="delete_entity">
                                                                    <input type="hidden" name="entity_id" value="<?= $e['entity_id'] ?>">
                                                                </form>
                                                                <button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon" title="Delete"
                                                                    onclick="showConfirmModal({title:'Delete Entity',message:'Delete <strong><?= htmlspecialchars($e['name']) ?></strong>? This cannot be undone.',confirmText:'DELETE',formId:'delEntityForm-<?= $e['entity_id'] ?>'})">
                                                                    <i class="fa-solid fa-trash"></i>
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                <?php if (empty($entities)): ?>
                                                    <tr>
                                                        <td colspan="10">
                                                            <div class="em-empty" style="padding:32px">
                                                                <i class="fa-solid fa-inbox"></i>
                                                                <p>No entities found matching your criteria.</p>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </form>
                            </div>

                            <!-- ===== PANE: ENTITY TYPES ===== -->
                            <div class="em-pane <?= isset($_GET['edit_type']) ? 'active' : '' ?>" id="pane-types">
                                <div class="em-section-header">
                                    <div>
                                        <div class="em-section-title">
                                            <i class="fa-solid fa-tags"></i>
                                            Entity Types
                                        </div>
                                        <div class="em-section-subtitle">Define the taxonomy of entity categories used across the system</div>
                                    </div>
                                </div>
                                <div class="em-pane-inner">
                                    <div class="em-panel" style="margin-bottom:24px;">
                                        <div class="em-panel-header">
                                            <div class="em-panel-title"><i class="fa-solid fa-list"></i> All Types</div>
                                        </div>
                                        <div class="em-table-wrap">
                                            <table class="em-table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Icon</th>
                                                        <th>Machine Name</th>
                                                        <th>Display Label</th>
                                                        <th>Description</th>
                                                        <th>Entities</th>
                                                        <th>Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($entity_types as $et):
                                                        $cnt = 0;
                                                        foreach ($type_stats as $ts) {
                                                            if ($ts['type_name'] === $et['type_name']) {
                                                                $cnt = $ts['cnt'];
                                                                break;
                                                            }
                                                        } ?>
                                                        <tr>
                                                            <td><span class="em-entity-code"><?= $et['type_id'] ?></span></td>
                                                            <td>
                                                                <div class="em-type-icon">
                                                                    <i class="fa-solid <?= htmlspecialchars($et['icon']) ?>"></i>
                                                                </div>
                                                            </td>
                                                            <td><code style="font-family:var(--em-mono);font-size:.78rem;color:var(--em-text-3)"><?= htmlspecialchars($et['type_name']) ?></code></td>
                                                            <td><strong><?= htmlspecialchars($et['type_label']) ?></strong></td>
                                                            <td><small style="color:var(--em-text-3)"><?= htmlspecialchars(mb_strimwidth($et['description'] ?? '', 0, 70, '…')) ?></small></td>
                                                            <td><span class="em-badge em-badge--blue"><?= $cnt ?></span></td>
                                                            <td>
                                                                <div style="display:flex;gap:5px;">
                                                                    <a href="?edit_type=<?= $et['type_id'] ?>#pane-types" class="em-btn em-btn--outline em-btn--sm em-btn--icon"><i class="fa-solid fa-pen"></i></a>
                                                                    <form method="POST" style="display:inline" id="delTypeForm-<?= $et['type_id'] ?>">
                                                                        <input type="hidden" name="action" value="delete_entity_type">
                                                                        <input type="hidden" name="type_id" value="<?= $et['type_id'] ?>">
                                                                    </form>
                                                                    <button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon"
                                                                        onclick="showConfirmModal({title:'Delete Type',message:'Delete type <strong><?= htmlspecialchars($et['type_label']) ?></strong>?',confirmText:'DELETE',formId:'delTypeForm-<?= $et['type_id'] ?>'})">
                                                                        <i class="fa-solid fa-trash"></i>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <?php if ($edit_type): ?>
                                        <div class="em-panel" style="margin-bottom:24px;border-color:var(--em-accent);">
                                            <div class="em-panel-header">
                                                <div class="em-panel-title"><i class="fa-solid fa-pen"></i> Edit Type: <?= htmlspecialchars($edit_type['type_label']) ?></div>
                                            </div>
                                            <div class="em-panel-body">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_entity_type">
                                                    <input type="hidden" name="type_id" value="<?= $edit_type['type_id'] ?>">
                                                    <div class="em-form-row cols-4">
                                                        <div>
                                                            <label class="em-label">Machine Name <span class="em-req">*</span></label>
                                                            <input type="text" name="type_name" class="em-input" value="<?= htmlspecialchars($edit_type['type_name']) ?>" required>
                                                        </div>
                                                        <div>
                                                            <label class="em-label">Display Label <span class="em-req">*</span></label>
                                                            <input type="text" name="type_label" class="em-input" value="<?= htmlspecialchars($edit_type['type_label']) ?>" required>
                                                        </div>
                                                        <div>
                                                            <label class="em-label">Icon</label>
                                                            <select name="icon" class="em-input">
                                                                <?php foreach ($ICON_OPTIONS as $ic => $lbl): ?>
                                                                    <option value="<?= $ic ?>" <?= $edit_type['icon'] === $ic ? 'selected' : '' ?>><?= $lbl ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="em-label">Description</label>
                                                            <input type="text" name="type_description" class="em-input" value="<?= htmlspecialchars($edit_type['description'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                    <button type="submit" class="em-btn em-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                                                    <a href="?tab=types" class="em-btn em-btn--ghost">Cancel</a>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="em-panel">
                                        <div class="em-panel-header">
                                            <div class="em-panel-title"><i class="fa-solid fa-plus"></i> Add New Entity Type</div>
                                        </div>
                                        <div class="em-panel-body">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="create_entity_type">
                                                <div class="em-form-row cols-4">
                                                    <div>
                                                        <label class="em-label">Machine Name <span class="em-req">*</span></label>
                                                        <input type="text" name="type_name" class="em-input" placeholder="e.g. department" required>
                                                    </div>
                                                    <div>
                                                        <label class="em-label">Display Label <span class="em-req">*</span></label>
                                                        <input type="text" name="type_label" class="em-input" placeholder="e.g. Department" required>
                                                    </div>
                                                    <div>
                                                        <label class="em-label">Icon</label>
                                                        <select name="icon" class="em-input">
                                                            <?php foreach ($ICON_OPTIONS as $ic => $lbl): ?>
                                                                <option value="<?= $ic ?>"><?= $lbl ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="em-label">Description</label>
                                                        <input type="text" name="type_description" class="em-input" placeholder="Short description">
                                                    </div>
                                                </div>
                                                <button type="submit" class="em-btn em-btn--primary"><i class="fa-solid fa-plus"></i> Create Type</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===== PANE: ADD ENTITY ===== -->
                            <div class="em-pane" id="pane-add">
                                <div class="em-section-header">
                                    <div>
                                        <div class="em-section-title">
                                            <i class="fa-solid fa-plus"></i>
                                            New Entity
                                        </div>
                                        <div class="em-section-subtitle">Create a new entity and assign it to the hierarchy</div>
                                    </div>
                                </div>
                                <div class="em-pane-inner">
                                    <div class="em-panel">
                                        <div class="em-panel-header">
                                            <div class="em-panel-title"><i class="fa-solid fa-circle-info"></i> Entity Details</div>
                                        </div>
                                        <div class="em-panel-body">
                                            <form method="POST">
                                                <input type="hidden" name="action" value="create_entity">

                                                <div class="em-form-section">
                                                    <div class="em-form-section-title">Identity</div>
                                                    <div class="em-form-row cols-3">
                                                        <div>
                                                            <label class="em-label">Entity Type <span class="em-req">*</span></label>
                                                            <select name="entity_type_id" class="em-input" required>
                                                                <option value="">— Select Type —</option>
                                                                <?php foreach ($entity_types as $et): ?>
                                                                    <option value="<?= $et['type_id'] ?>"><?= htmlspecialchars($et['type_label']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="em-label">Full Name <span class="em-req">*</span></label>
                                                            <input type="text" name="entity_name" class="em-input" placeholder="e.g. Faculty of Engineering" required>
                                                        </div>
                                                        <div>
                                                            <label class="em-label">Short Name / Acronym</label>
                                                            <input type="text" name="short_name" class="em-input" placeholder="e.g. FoE">
                                                        </div>
                                                    </div>
                                                    <div class="em-form-row cols-3">
                                                        <div>
                                                            <label class="em-label">Entity Code</label>
                                                            <input type="text" name="entity_code" class="em-input" placeholder="e.g. F001">
                                                        </div>
                                                        <div>
                                                            <label class="em-label">Parent Entity</label>
                                                            <select name="parent_entity_id" class="em-input">
                                                                <option value="">— None (top level) —</option>
                                                                <?php foreach ($all_entities as $ae): ?>
                                                                    <option value="<?= $ae['entity_id'] ?>">[<?= htmlspecialchars($ae['type_label']) ?>] <?= htmlspecialchars($ae['name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label class="em-label">University</label>
                                                            <select name="university_id" class="em-input">
                                                                <option value="">— None —</option>
                                                                <?php foreach ($all_entities as $ae):
                                                                    if ($ae['type_name'] === 'university'): ?>
                                                                        <option value="<?= $ae['entity_id'] ?>"><?= htmlspecialchars($ae['name']) ?></option>
                                                                <?php endif;
                                                                endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="em-form-section">
                                                    <div class="em-form-section-title">Description</div>
                                                    <textarea name="entity_description" class="em-input em-textarea" rows="3" placeholder="Describe this entity…"></textarea>
                                                </div>

                                                <div class="em-form-section">
                                                    <div class="em-form-section-title">Additional Information</div>
                                                    <p class="em-sd-label-hint">Add contact details, links, or any extra attributes (e.g. Phone → +256 700 263030)</p>
                                                    <div id="sdFieldsCreate">
                                                        <div class="em-sd-row">
                                                            <input type="text" name="sd_key[]" class="em-input" placeholder="Label (e.g. Phone)">
                                                            <input type="text" name="sd_val[]" class="em-input" placeholder="Value (e.g. +256 700 263030)">
                                                            <button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>
                                                        </div>
                                                    </div>
                                                    <button type="button" class="em-btn em-btn--outline em-btn--sm" style="margin-top:6px" onclick="addSdRow('sdFieldsCreate')">
                                                        <i class="fa-solid fa-plus"></i> Add Field
                                                    </button>
                                                </div>

                                                <div style="display:flex;gap:10px;padding-top:8px;">
                                                    <button type="submit" class="em-btn em-btn--primary"><i class="fa-solid fa-circle-plus"></i> Create Entity</button>
                                                    <button type="reset" class="em-btn em-btn--ghost"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- ===== PANE: EDIT ENTITY ===== -->
                            <?php if ($edit_entity):
                                $sd_data = json_decode($edit_entity['structured_data'] ?? '{}', true) ?: [];
                            ?>
                                <div class="em-pane active" id="pane-edit">
                                    <div class="em-section-header">
                                        <div>
                                            <div class="em-section-title">
                                                <i class="fa-solid <?= htmlspecialchars($edit_entity['icon']) ?>"></i>
                                                <?= htmlspecialchars(mb_strimwidth($edit_entity['name'], 0, 45, '…')) ?>
                                            </div>
                                            <div class="em-section-subtitle">
                                                <span class="em-badge em-badge--blue" style="vertical-align:middle;"><?= htmlspecialchars($edit_entity['type_label']) ?></span>
                                                <?php if ($edit_entity['entity_code']): ?>
                                                    &nbsp;<span class="em-entity-code"><?= htmlspecialchars($edit_entity['entity_code']) ?></span>
                                                <?php endif; ?>
                                                &nbsp;· ID <?= $edit_entity['entity_id'] ?>
                                            </div>
                                        </div>
                                        <a href="entity_manage.php" class="em-btn em-btn--outline"><i class="fa-solid fa-xmark"></i> Close Edit</a>
                                    </div>
                                    <div class="em-pane-inner">
                                        <div class="em-edit-layout">

                                            <!-- LEFT: Entity Form -->
                                            <div>
                                                <div class="em-panel" style="margin-bottom:20px;">
                                                    <div class="em-panel-header">
                                                        <div class="em-panel-title"><i class="fa-solid fa-pen-to-square"></i> Entity Properties</div>
                                                        <span class="em-badge <?= $edit_entity['is_active'] ? 'em-badge--green' : 'em-badge--red' ?>">
                                                            <?= $edit_entity['is_active'] ? 'Active' : 'Inactive' ?>
                                                        </span>
                                                    </div>
                                                    <div class="em-panel-body">
                                                        <form method="POST">
                                                            <input type="hidden" name="action" value="update_entity">
                                                            <input type="hidden" name="entity_id" value="<?= $edit_entity['entity_id'] ?>">
                                                            <input type="hidden" name="metadata_json" value="<?= htmlspecialchars($edit_entity['metadata'] ?? '{}') ?>">

                                                            <div class="em-form-section">
                                                                <div class="em-form-section-title">Identity</div>
                                                                <div class="em-form-row cols-2">
                                                                    <div>
                                                                        <label class="em-label">Entity Type</label>
                                                                        <select name="entity_type_id" class="em-input">
                                                                            <?php foreach ($entity_types as $et): ?>
                                                                                <option value="<?= $et['type_id'] ?>" <?= $et['type_id'] == $edit_entity['entity_type_id'] ? 'selected' : '' ?>>
                                                                                    <?= htmlspecialchars($et['type_label']) ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <label class="em-label">Full Name <span class="em-req">*</span></label>
                                                                        <input type="text" name="entity_name" class="em-input" value="<?= htmlspecialchars($edit_entity['name']) ?>" required>
                                                                    </div>
                                                                </div>
                                                                <div class="em-form-row cols-2">
                                                                    <div>
                                                                        <label class="em-label">Short Name</label>
                                                                        <input type="text" name="short_name" class="em-input" value="<?= htmlspecialchars($edit_entity['short_name'] ?? '') ?>">
                                                                    </div>
                                                                    <div>
                                                                        <label class="em-label">Entity Code</label>
                                                                        <input type="text" name="entity_code" class="em-input" value="<?= htmlspecialchars($edit_entity['entity_code'] ?? '') ?>">
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="em-form-section">
                                                                <div class="em-form-section-title">Hierarchy</div>
                                                                <div class="em-form-row cols-2">
                                                                    <div>
                                                                        <label class="em-label">Parent Entity</label>
                                                                        <select name="parent_entity_id" class="em-input">
                                                                            <option value="">— None —</option>
                                                                            <?php foreach ($all_entities as $ae):
                                                                                if ($ae['entity_id'] != $edit_entity['entity_id']): ?>
                                                                                    <option value="<?= $ae['entity_id'] ?>" <?= $ae['entity_id'] == $edit_entity['parent_entity_id'] ? 'selected' : '' ?>>
                                                                                        [<?= htmlspecialchars($ae['type_label']) ?>] <?= htmlspecialchars($ae['name']) ?>
                                                                                    </option>
                                                                            <?php endif;
                                                                            endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div>
                                                                        <label class="em-label">University</label>
                                                                        <select name="university_id" class="em-input">
                                                                            <option value="">— None —</option>
                                                                            <?php foreach ($all_entities as $ae):
                                                                                if ($ae['type_name'] === 'university'): ?>
                                                                                    <option value="<?= $ae['entity_id'] ?>" <?= $ae['entity_id'] == $edit_entity['university_id'] ? 'selected' : '' ?>>
                                                                                        <?= htmlspecialchars($ae['name']) ?>
                                                                                    </option>
                                                                            <?php endif;
                                                                            endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="em-form-section">
                                                                <div class="em-form-section-title">Description &amp; Status</div>
                                                                <textarea name="entity_description" class="em-input em-textarea" rows="3"><?= htmlspecialchars($edit_entity['description'] ?? '') ?></textarea>
                                                                <div class="em-checkbox-row" style="margin-top:10px;">
                                                                    <input type="checkbox" name="is_active" id="isActiveChk" <?= $edit_entity['is_active'] ? 'checked' : '' ?>>
                                                                    <label for="isActiveChk" style="font-size:.82rem;font-weight:500;cursor:pointer;">Entity is Active and visible to the chatbot</label>
                                                                </div>
                                                            </div>

                                                            <div class="em-form-section">
                                                                <div class="em-form-section-title">Additional Information</div>
                                                                <p class="em-sd-label-hint">Structured key-value data such as Phone, Email, Address, Website</p>
                                                                <div id="sdFieldsEdit">
                                                                    <?php foreach ($sd_data as $k => $v): ?>
                                                                        <div class="em-sd-row">
                                                                            <input type="text" name="sd_key[]" class="em-input" value="<?= htmlspecialchars($k) ?>" placeholder="Label">
                                                                            <input type="text" name="sd_val[]" class="em-input" value="<?= htmlspecialchars($v) ?>" placeholder="Value">
                                                                            <button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                    <?php if (empty($sd_data)): ?>
                                                                        <div class="em-sd-row">
                                                                            <input type="text" name="sd_key[]" class="em-input" placeholder="Label (e.g. Phone)">
                                                                            <input type="text" name="sd_val[]" class="em-input" placeholder="Value (e.g. +256 700 263030)">
                                                                            <button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <button type="button" class="em-btn em-btn--outline em-btn--sm" style="margin-top:6px" onclick="addSdRow('sdFieldsEdit')">
                                                                    <i class="fa-solid fa-plus"></i> Add Field
                                                                </button>
                                                            </div>

                                                            <div style="display:flex;gap:10px;">
                                                                <button type="submit" class="em-btn em-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                                                                <a href="entity_manage.php" class="em-btn em-btn--ghost">Cancel</a>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- RIGHT: Knowledge Base Panel -->
                                            <div>
                                                <div class="em-panel">
                                                    <div class="em-panel-header">
                                                        <div class="em-panel-title">
                                                            <i class="fa-solid fa-book-open"></i> Knowledge Base
                                                        </div>
                                                        <span class="em-badge em-badge--green"><?= count($edit_chunks) ?> entries</span>
                                                    </div>
                                                    <div style="padding:16px;">
                                                        <p style="font-size:.76rem;color:var(--em-text-3);margin-bottom:12px;">
                                                            Each entry is a piece of information the chatbot uses to answer questions about this entity.
                                                        </p>

                                                        <!-- Add new chunk form -->
                                                        <div style="background:var(--em-surface-2);border:1px solid var(--em-border-soft);border-radius:var(--em-radius);padding:14px;margin-bottom:16px;">
                                                            <div style="font-size:.75rem;font-weight:700;color:var(--em-text-3);text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px;">
                                                                <i class="fa-solid fa-plus" style="color:var(--em-accent)"></i> Add New Entry
                                                            </div>
                                                            <form method="POST">
                                                                <input type="hidden" name="action" value="save_chunk">
                                                                <input type="hidden" name="chunk_entity_id" value="<?= $edit_entity['entity_id'] ?>">
                                                                <div style="display:grid;grid-template-columns:80px 1fr;gap:8px;margin-bottom:8px;">
                                                                    <div>
                                                                        <label class="em-label">Order #</label>
                                                                        <input type="number" name="chunk_index" class="em-input" value="<?= count($edit_chunks) ?>" min="0" style="font-family:var(--em-mono)">
                                                                    </div>
                                                                    <div>
                                                                        <label class="em-label">Topic Title <span class="em-req">*</span></label>
                                                                        <input type="text" name="chunk_title" class="em-input" placeholder="e.g. Admission Requirements" required>
                                                                    </div>
                                                                </div>
                                                                <div style="margin-bottom:8px;">
                                                                    <label class="em-label">Content <span class="em-req">*</span></label>
                                                                    <textarea name="chunk_content" class="em-input em-textarea" rows="4" placeholder="Enter the knowledge content the chatbot should use…" required></textarea>
                                                                </div>
                                                                <button type="submit" class="em-btn em-btn--success em-btn--sm"><i class="fa-solid fa-floppy-disk"></i> Save Entry</button>
                                                            </form>
                                                        </div>

                                                        <!-- Search chunks -->
                                                        <?php if (count($edit_chunks) > 0): ?>
                                                            <div class="em-search-box" style="max-width:100%;margin-bottom:12px;">
                                                                <i class="fa-solid fa-magnifying-glass"></i>
                                                                <input type="text" id="kbSearch" placeholder="Search knowledge entries…">
                                                            </div>
                                                        <?php endif; ?>

                                                        <!-- Chunk list -->
                                                        <div id="kbChunkList">
                                                            <?php foreach ($edit_chunks as $ck): ?>
                                                                <div class="em-chunk-card kb-chunk-card"
                                                                    data-title="<?= strtolower(htmlspecialchars($ck['title'])) ?>"
                                                                    data-content="<?= strtolower(htmlspecialchars(mb_strimwidth($ck['content'], 0, 500))) ?>">
                                                                    <div class="em-chunk-header">
                                                                        <span class="em-chunk-index">#<?= $ck['chunk_index'] ?></span>
                                                                        <span class="em-chunk-title"><?= htmlspecialchars($ck['title']) ?></span>
                                                                        <div style="display:flex;gap:5px;flex-shrink:0;">
                                                                            <button type="button" class="em-btn em-btn--outline em-btn--sm em-btn--icon"
                                                                                onclick="openEditChunk(<?= $ck['chunk_id'] ?>, <?= htmlspecialchars(json_encode($ck['title'])) ?>, <?= htmlspecialchars(json_encode($ck['content'])) ?>)"
                                                                                title="Edit">
                                                                                <i class="fa-solid fa-pen"></i>
                                                                            </button>
                                                                            <form method="POST" style="display:inline" id="delChunkForm-<?= $ck['chunk_id'] ?>">
                                                                                <input type="hidden" name="action" value="delete_chunk">
                                                                                <input type="hidden" name="chunk_id" value="<?= $ck['chunk_id'] ?>">
                                                                            </form>
                                                                            <button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon"
                                                                                onclick="showConfirmModal({title:'Delete Entry',message:'Delete knowledge entry <strong><?= htmlspecialchars(mb_strimwidth($ck['title'], 0, 40, '…')) ?></strong>?',confirmText:'DELETE',formId:'delChunkForm-<?= $ck['chunk_id'] ?>'})"
                                                                                title="Delete">
                                                                                <i class="fa-solid fa-trash"></i>
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                    <div class="em-chunk-body">
                                                                        <div class="em-chunk-text kb-content-preview">
                                                                            <span class="kb-short"><?= nl2br(htmlspecialchars(mb_strimwidth($ck['content'], 0, 260, ''))) ?><?php if (strlen($ck['content']) > 260): ?><span class="kb-ellipsis">…</span><?php endif; ?></span>
                                                                            <?php if (strlen($ck['content']) > 260): ?>
                                                                                <span class="kb-full" style="display:none"><?= nl2br(htmlspecialchars($ck['content'])) ?></span>
                                                                                <br><a href="#" class="kb-viewmore" onclick="toggleKbFull(this);return false;" style="font-size:.72rem;color:var(--em-accent);">
                                                                                    <i class="fa-solid fa-chevron-down fa-xs"></i> View more
                                                                                </a>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="em-chunk-meta">
                                                                        <span><i class="fa-solid fa-text-width"></i> <?= $ck['char_count'] ?? strlen($ck['content']) ?> chars</span>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                            <?php if (empty($edit_chunks)): ?>
                                                                <div class="em-empty" style="padding:24px;">
                                                                    <i class="fa-solid fa-book"></i>
                                                                    <p>No knowledge entries yet.<br>Add the first one above.</p>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                        </div><!-- /em-edit-layout -->
                                    </div>
                                </div><!-- /pane-edit -->
                            <?php endif; ?>

                        </div><!-- /em-content-area -->
                    </div><!-- /em-body -->
                </div><!-- /em-page -->

            </div><!-- /sb2-2 -->
        </div>
    </div>

    <!-- ═══ Edit Chunk Modal ═══ -->
    <div class="modal fade" id="editChunkModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="border-radius:12px;border:1px solid var(--em-border);">
                <div class="modal-header" style="border-bottom:1px solid var(--em-border-soft);padding:16px 20px;">
                    <h5 class="modal-title" style="font-family:var(--em-sans);font-size:.95rem;font-weight:700;">
                        <i class="fa-solid fa-pen" style="color:var(--em-accent);margin-right:8px;"></i>Edit Knowledge Entry
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body" style="padding:20px;">
                        <input type="hidden" name="action" value="update_chunk">
                        <input type="hidden" name="chunk_id" id="editChunkId">
                        <div style="margin-bottom:14px;">
                            <label class="em-label">Topic Title <span class="em-req">*</span></label>
                            <input type="text" name="chunk_title" id="editChunkTitle" class="em-input" required>
                        </div>
                        <div>
                            <label class="em-label">Knowledge Content <span class="em-req">*</span></label>
                            <textarea name="chunk_content" id="editChunkContent" class="em-input em-textarea" rows="9" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer" style="border-top:1px solid var(--em-border-soft);padding:12px 20px;">
                        <button type="button" class="em-btn em-btn--ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="em-btn em-btn--primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══ Danger Zone Modal ═══ -->
    <div class="modal fade" id="dangerZoneModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:12px;">
                <div class="modal-header" style="background:var(--em-danger-bg);border-bottom:1px solid #fca5a5;">
                    <h5 class="modal-title" style="color:var(--em-danger);font-family:var(--em-sans);font-size:.95rem;font-weight:700;">
                        <i class="fa-solid fa-triangle-exclamation"></i> Danger Zone
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="background: red !important;
                        background: #b10505 !important;
                        background-image: none !important;
                        opacity: 1 !important;
                        color: #fff !important;
                        padding: 8px 15px !important;
                        margin-right: 5px !important;
                        border: none !important;
                        border-radius: 4px !important;
                        cursor: pointer !important;
                        box-sizing: border-box !important;
                        width: auto !important;
                        height: auto !important;"> close</button>
                </div>
                <div class="modal-body" style="padding:20px;">
                    <div class="em-danger-zone">
                        <div class="em-danger-zone-title"><i class="fa-solid fa-skull-crossbones"></i> Drop All Entity Data</div>
                        <div class="em-danger-zone-desc">Permanently deletes ALL entities, relationships, history, and knowledge chunks. This action <strong>cannot be undone</strong>.</div>
                        <form method="POST" id="dropAllForm" style="display:inline">
                            <input type="hidden" name="action" value="drop_all_entities">
                        </form>
                        <button class="em-btn em-btn--danger" onclick="showConfirmModal({title:'Drop ALL Data',message:'This will permanently delete ALL entities, relationships, history, and knowledge chunks. This action CANNOT be undone.',confirmText:'DROP ALL',formId:'dropAllForm'});var mx=bootstrap.Modal.getInstance(document.getElementById('dangerZoneModal'));if(mx)mx.hide();">
                            <i class="fa-solid fa-trash-can"></i> Drop All Entity Data
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bulk Import Modal -->
    <div class="modal fade" id="importEntityModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content" style="border-radius:12px;border:1px solid var(--em-border);">
                <div class="modal-header" style="border-bottom:1px solid var(--em-border-soft);padding:16px 20px;">
                    <h5 class="modal-title" style="font-weight:700;font-size:1.05rem;color:var(--em-text);">
                        <i class="fa-solid fa-file-excel" style="color:var(--em-accent);margin-right:8px;"></i> Bulk Import Entities
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" style="font-size:.8rem;"></button>
                </div>
                <div class="modal-body" style="padding:20px;">
                    <div class="em-alert em-alert--success" style="margin:0 0 16px 0; display:block;">
                        <p style="margin:0 0 8px 0;"><strong>1. Download the Template</strong></p>
                        <p style="margin:0 0 10px 0; font-size:.8rem;">Use the provided Excel template format. Select an entity type to get a template tailored with dropdowns for its specific data fields.</p>
                        <div style="display:flex; gap:10px; align-items:center;">
                            <select id="templateTypeSelect" class="em-select" style="min-width:200px;">
                                <option value="">Generic (No Custom Fields)</option>
                                <?php foreach ($entity_types as $et): ?>
                                    <option value="<?= htmlspecialchars($et['type_name']) ?>"><?= htmlspecialchars($et['type_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <a href="?action=download_template" id="downloadTemplateBtn" class="em-btn em-btn--primary" style="text-decoration:none;"><i class="fa-solid fa-download"></i> Download Excel (.xlsx)</a>
                        </div>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data" id="importEntitiesForm">
                        <input type="hidden" name="action" value="import_bulk_entities">
                        
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:.85rem; font-weight:600; margin-bottom:6px;"><strong>2. Upload Completed File</strong></label>
                            <input type="file" name="import_file" accept=".xlsx" required style="width:100%; border:1px dashed var(--em-border); padding:10px; border-radius:8px; background:var(--em-surface-2);">
                            <small style="color:var(--em-text-3); display:block; margin-top:6px;"><i class="fa-solid fa-circle-info"></i> Valid entity type codes include: <em>university, faculty, department, program, course, person, generic, etc.</em></small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--em-border-soft);padding:12px 20px;">
                    <button type="button" class="em-btn em-btn--outline" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="em-btn em-btn--primary" onclick="document.getElementById('importEntitiesForm').submit(); this.disabled=true; this.innerHTML='<i class=\'fa-solid fa-spinner fa-spin\'></i> Importing...';"><i class="fa-solid fa-upload"></i> Upload & Import</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"></script>

    <?php include 'includes/global_toasts.php'; ?>
    <script src="js/kb_admin.js"></script>
    <?php include 'includes/confirm_modal.php'; ?>

    <script>
        // ── Bulk Import Modal ──
        function openImportModal() {
            new bootstrap.Modal(document.getElementById('importEntityModal')).show();
        }
        
        document.getElementById('templateTypeSelect')?.addEventListener('change', function() {
            const btn = document.getElementById('downloadTemplateBtn');
            if (this.value) {
                btn.href = "?action=download_template&type=" + encodeURIComponent(this.value);
            } else {
                btn.href = "?action=download_template";
            }
        });

        /* =====================================================
       ENTITY MANAGER — Client-side Logic
    ===================================================== */

        // ── Pane Navigation ──
        function switchPane(paneId, navItem) {
            document.querySelectorAll('.em-pane').forEach(p => p.classList.remove('active'));
            document.querySelectorAll('.em-nav-item').forEach(n => n.classList.remove('active'));
            const pane = document.getElementById(paneId);
            if (pane) pane.classList.add('active');
            if (navItem) navItem.classList.add('active');
        }

        // ── SD Row ──
        function addSdRow(containerId) {
            const c = document.getElementById(containerId);
            const row = document.createElement('div');
            row.className = 'em-sd-row';
            row.innerHTML = '<input type="text" name="sd_key[]" class="em-input" placeholder="Label (e.g. Phone)">' +
                '<input type="text" name="sd_val[]" class="em-input" placeholder="Value (e.g. +256 700 263030)">' +
                '<button type="button" class="em-btn em-btn--danger em-btn--sm em-btn--icon" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>';
            c.appendChild(row);
        }

        // ── Hierarchy Tree ──
        const treeData = <?= json_encode($all_entities, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        function buildTree(items, parentId) {
            const children = items.filter(i => (i.parent_entity_id || null) == parentId);
            if (!children.length) return null;
            const wrapper = document.createElement('div');
            if (parentId !== null) wrapper.className = 'em-tree-branch';

            children.forEach(item => {
                const subItems = items.filter(i => i.parent_entity_id == item.entity_id);
                const hasKids = subItems.length > 0;

                const node = document.createElement('div');
                node.className = 'em-tree-node';
                const desc = (item.description || '').substring(0, 70);
                node.innerHTML = `
                <span class="em-tree-toggle">${hasKids ? '<i class="fa-solid fa-chevron-down"></i>' : '<span style="opacity:.25;font-size:.5rem;">●</span>'}</span>
                <div class="em-type-icon" style="width:24px;height:24px;font-size:.65rem;border-radius:5px;flex-shrink:0;">
                    <i class="fa-solid ${item.icon || 'fa-cube'}"></i>
                </div>
                <div class="em-tree-info">
                    <div class="em-tree-name">${item.name}${item.entity_code ? ' <code style="font-size:.65rem;color:var(--em-text-4);background:var(--em-bg);padding:1px 5px;border-radius:3px;border:1px solid var(--em-border);">' + item.entity_code + '</code>' : ''}</div>
                    ${desc ? '<div class="em-tree-desc">' + desc + '</div>' : ''}
                </div>
                <span class="em-tree-type">${item.type_label}</span>
                <div style="display:flex;gap:4px;align-items:center;flex-shrink:0;">
                    ${item.chunk_count > 0 ? '<span class="em-badge em-badge--green" style="font-size:.62rem;">' + item.chunk_count + ' KB</span>' : ''}
                    ${!item.is_active ? '<span class="em-badge em-badge--red" style="font-size:.62rem;">Off</span>' : ''}
                </div>
            `;
                node.addEventListener('click', (e) => {
                    if (e.target.closest('.em-tree-toggle') && hasKids) {
                        const branch = node.nextElementSibling;
                        if (branch && branch.classList.contains('em-tree-branch')) {
                            const hidden = branch.style.display === 'none';
                            branch.style.display = hidden ? '' : 'none';
                            const icon = node.querySelector('.em-tree-toggle i');
                            if (icon) icon.className = hidden ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right';
                        }
                    } else if (!e.target.closest('.em-tree-toggle')) {
                        window.location.href = '?edit=' + item.entity_id;
                    }
                });
                wrapper.appendChild(node);
                if (hasKids) {
                    const sub = buildTree(items, item.entity_id);
                    if (sub) wrapper.appendChild(sub);
                }
            });
            return wrapper;
        }

        const treeContainer = document.getElementById('entityTree');
        const treeEmpty = document.getElementById('treeEmpty');
        if (treeContainer) {
            const tree = buildTree(treeData, null);
            if (tree && tree.childNodes.length) {
                treeContainer.appendChild(tree);
            } else {
                treeEmpty.style.display = '';
            }
        }

        // ── Hierarchy Search ──
        function filterTree(q) {
            q = q.toLowerCase();
            document.querySelectorAll('#entityTree .em-tree-node').forEach(node => {
                const text = (node.textContent || '').toLowerCase();
                node.style.display = q === '' || text.indexOf(q) !== -1 ? '' : 'none';
            });
        }


        // ── Notifications ──
        function updateNotificationCount() {
            fetch('fetch_queries.php').then(r => r.json()).then(d => {
                const el = document.getElementById('not-yet-count');
                if (el) {
                    el.textContent = d.not_yet_count;
                    el.style.display = d.not_yet_count > 0 ? 'inline' : 'none';
                }
            }).catch(() => {});
        }
        updateNotificationCount();
        setInterval(updateNotificationCount, 60000);

        // ── KB Search ──
        const kbSearchInput = document.getElementById('kbSearch');
        if (kbSearchInput) {
            kbSearchInput.addEventListener('input', function() {
                const q = this.value.toLowerCase().trim();
                document.querySelectorAll('.kb-chunk-card').forEach(card => {
                    const t = card.dataset.title || '';
                    const c = card.dataset.content || '';
                    card.style.display = (!q || t.includes(q) || c.includes(q)) ? '' : 'none';
                });
            });
        }

        // ── Edit Chunk Modal ──
        function openEditChunk(id, title, content) {
            document.getElementById('editChunkId').value = id;
            document.getElementById('editChunkTitle').value = title;
            document.getElementById('editChunkContent').value = content;
            new bootstrap.Modal(document.getElementById('editChunkModal')).show();
        }

        // ── View More Toggle ──
        function toggleKbFull(link) {
            const preview = link.closest('.kb-content-preview');
            const short = preview.querySelector('.kb-short');
            const full = preview.querySelector('.kb-full');
            const ellipsis = preview.querySelector('.kb-ellipsis');
            const expanded = full.style.display !== 'none';
            full.style.display = expanded ? 'none' : 'inline';
            if (ellipsis) ellipsis.style.display = expanded ? '' : 'none';
            link.innerHTML = expanded ?
                '<i class="fa-solid fa-chevron-down fa-xs"></i> View more' :
                '<i class="fa-solid fa-chevron-up fa-xs"></i> View less';
        }

        // ── Bulk Actions ──
        document.getElementById('bulkActionSelect')?.addEventListener('change', function() {
            document.getElementById('bulkStatusSelect').style.display = this.value === 'bulk_status' ? 'inline-block' : 'none';
        });
        document.getElementById('selectAllEntities')?.addEventListener('change', function() {
            document.querySelectorAll('.entity-checkbox').forEach(cb => cb.checked = this.checked);
        });

        function executeBulkAction() {
            const action = document.getElementById('bulkActionSelect').value;
            const checked = document.querySelectorAll('.entity-checkbox:checked');
            if (action === '') return alert('Select an action first.');
            if (checked.length === 0) return alert('Select at least one entity.');
            if (action === 'bulk_delete') {
                showConfirmModal({
                    title: 'Bulk Delete',
                    message: 'Delete <strong>' + checked.length + '</strong> entities? This cannot be undone.',
                    confirmText: 'DELETE',
                    formId: 'bulkActionsForm'
                });
            } else {
                document.getElementById('bulkActionsForm').submit();
            }
        }

        // ── Activate correct pane on page load from URL ──
        (function() {
            const params = new URLSearchParams(window.location.search);
            if (params.has('edit')) switchPane('pane-edit', null);
            else if (params.has('edit_type')) switchPane('pane-types', document.querySelector('.em-nav-item[onclick*="pane-types"]'));
            else if (params.get('tab') === 'table') switchPane('pane-table', document.querySelector('.em-nav-item[onclick*="pane-table"]'));
        })();
    </script>
</body>

</html>