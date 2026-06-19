<?php
// Knowledge Base Management: categories + documents CRUD
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: ./admin-login.php');
    exit();
}
require_once 'db.php';
if (!$conn || $conn->connect_error) {
    die('Connection failed: ' . ($conn ? $conn->connect_error : 'No connection object.'));
}

// Fetch Admin Details
$admin_query = "SELECT admin_id, username, email FROM admins WHERE admin_id = ?";
if (!$conn->ping()) {
    die("Database connection is closed.");
}
$admin_stmt = $conn->prepare($admin_query);
if (!$admin_stmt) {
    die("Prepare failed: " . $conn->error);
}
$admin_stmt->bind_param('i', $_SESSION['admin_id']);
$admin_stmt->execute();
$admin_result = $admin_stmt->get_result();
$admin = $admin_result->fetch_assoc();

if (!$admin) {
    // Session holds an admin_id that no longer exists; reset and re-authenticate
    session_unset();
    session_destroy();
    header("Location: ./admin-login.php?err=account_missing");
    exit();
}

$notice = null;
$error = null;

// Handle CRUD actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_category') {
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $parent = isset($_POST['parent_category_id']) && $_POST['parent_category_id'] !== '' ? (int)$_POST['parent_category_id'] : null;
            if ($name === '')
                throw new Exception('Category name required.');
            $stmt = $conn->prepare('INSERT INTO knowledge_base_categories (category_name, description, parent_category_id, is_active) VALUES (?,?,?,TRUE)');
            $stmt->bind_param('ssi', $name, $desc, $parent);
            $stmt->execute();
            $notice = 'Category created.';
        }
        elseif ($action === 'update_category') {
            $id = (int)$_POST['category_id'];
            $name = trim($_POST['category_name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            $parent = isset($_POST['parent_category_id']) && $_POST['parent_category_id'] !== '' ? (int)$_POST['parent_category_id'] : null;
            $stmt = $conn->prepare('UPDATE knowledge_base_categories SET category_name=?, description=?, parent_category_id=?, is_active=? WHERE category_id=?');
            $stmt->bind_param('ssiii', $name, $desc, $parent, $active, $id);
            $stmt->execute();
            $notice = 'Category updated.';
        }
        elseif ($action === 'delete_category') {
            $id = (int)$_POST['category_id'];
            $stmt = $conn->prepare('DELETE FROM knowledge_base_categories WHERE category_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $notice = 'Category deleted.';
        }
        elseif ($action === 'create_doc') {
            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $content = $_POST['content'] ?? '';
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $source_type = $_POST['source_type'] ?? 'manual';
            $source_url = trim($_POST['source_url'] ?? '');
            $content_type = $_POST['content_type'] ?? 'general';
            $is_public = isset($_POST['is_public']) ? 1 : 0;
            if ($title === '')
                throw new Exception('Title required.');

            if ($desc !== '') {
                $content = "Description:\n" . $desc . "\n\n" . $content;
            }

            $file_path = null;
            if ($source_type === 'uploaded' && isset($_FILES['upload_file']) && is_uploaded_file($_FILES['upload_file']['tmp_name'])) {
                $baseDir = __DIR__ . '/uploads/kb';
                if (!is_dir($baseDir)) {
                    @mkdir($baseDir, 0775, true);
                }
                $orig = basename($_FILES['upload_file']['name']);
                $safe = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $orig);
                $destRel = 'uploads/kb/' . time() . '_' . $safe;
                $destAbs = __DIR__ . '/' . $destRel;
                if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $destAbs)) {
                    $file_path = $destRel;
                    $ext = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
                    if (in_array($ext, ['txt', 'md'])) {
                        $fileText = @file_get_contents($destAbs);
                        if ($fileText) {
                            $content .= "\n\n[Uploaded File Content]\n" . $fileText;
                        }
                    }
                    else {
                        $content .= "\n\n[Uploaded File] " . $destRel;
                    }
                }
            }
            elseif ($source_type === 'scraped' && $source_url !== '') {
                $urlEsc = $conn->real_escape_string($source_url);
                $r = $conn->query("SELECT cleaned_content, raw_content FROM scraped_content WHERE page_url='" . $urlEsc . "' LIMIT 1");
                if ($r && $r->num_rows > 0) {
                    $row = $r->fetch_assoc();
                    $content .= "\n\n[Scraped]\n" . ($row['cleaned_content'] ?: $row['raw_content']);
                }
            }

            $stmt = $conn->prepare('INSERT INTO knowledge_base_documents (category_id, title, content, source_type, source_url, file_path, content_type, is_public, created_by, updated_by) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $created_by = $_SESSION['admin_id'];
            $updated_by = $_SESSION['admin_id'];
            $stmt->bind_param('issssssiii', $category_id, $title, $content, $source_type, $source_url, $file_path, $content_type, $is_public, $created_by, $updated_by);
            $stmt->execute();
            $notice = 'Document created.';
        }
        elseif ($action === 'update_doc') {
            $id = (int)$_POST['document_id'];
            $title = trim($_POST['title'] ?? '');
            $content = $_POST['content'] ?? '';
            $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $source_type = $_POST['source_type'] ?? 'manual';
            $source_url = trim($_POST['source_url'] ?? '');
            $content_type = $_POST['content_type'] ?? 'general';
            $is_public = isset($_POST['is_public']) ? 1 : 0;

            $file_path = $_POST['existing_file_path'] ?? null;
            if ($source_type === 'uploaded' && isset($_FILES['upload_file']) && is_uploaded_file($_FILES['upload_file']['tmp_name'])) {
                $baseDir = __DIR__ . '/uploads/kb';
                if (!is_dir($baseDir)) {
                    @mkdir($baseDir, 0775, true);
                }
                $orig = basename($_FILES['upload_file']['name']);
                $safe = preg_replace('/[^A-Za-z0-9_\.-]/', '_', $orig);
                $destRel = 'uploads/kb/' . time() . '_' . $safe;
                $destAbs = __DIR__ . '/' . $destRel;
                if (move_uploaded_file($_FILES['upload_file']['tmp_name'], $destAbs)) {
                    $file_path = $destRel;
                }
            }

            $stmt = $conn->prepare('UPDATE knowledge_base_documents SET category_id=?, title=?, content=?, source_type=?, source_url=?, file_path=?, content_type=?, is_public=?, updated_by=? WHERE document_id=?');
            $updated_by = $_SESSION['admin_id'];
            $stmt->bind_param('issssssiii', $category_id, $title, $content, $source_type, $source_url, $file_path, $content_type, $is_public, $updated_by, $id);
            $stmt->execute();
            $notice = 'Document updated.';
        }
        elseif ($action === 'delete_doc') {
            $id = (int)$_POST['document_id'];
            $stmt = $conn->prepare('DELETE FROM knowledge_base_documents WHERE document_id=?');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $notice = 'Document deleted.';
        }
    }
    catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

// Fetch data
$categories = [];
$res = $conn->query('SELECT * FROM knowledge_base_categories ORDER BY category_name');
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $categories[] = $r;
    }
}

$filter_cat = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$filter_q = trim($_GET['q'] ?? '');
$where = 'WHERE 1=1';
$params = [];
if ($filter_cat > 0) {
    $where .= ' AND category_id=' . $filter_cat;
}
if ($filter_q !== '') {
    $where .= " AND (title LIKE '%" . $conn->real_escape_string($filter_q) . "%' OR content LIKE '%" . $conn->real_escape_string($filter_q) . "%')";
}
$docs = [];
$res = $conn->query("SELECT d.*, c.category_name FROM knowledge_base_documents d LEFT JOIN knowledge_base_categories c ON d.category_id=c.category_id $where ORDER BY d.updated_at DESC LIMIT 200");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $docs[] = $r;
    }
}

// Recent scraped pages to assist linking
$recent_scraped = [];
$rs = $conn->query("SELECT s.scraped_id, s.page_url, s.page_title, s.scraped_at, src.source_name FROM scraped_content s LEFT JOIN scraping_sources src ON s.source_id=src.source_id ORDER BY s.scraped_at DESC LIMIT 50");
if ($rs) {
    while ($r = $rs->fetch_assoc()) {
        $recent_scraped[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>Knowledge Base Management</title>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=1775081173">
    <link href="css/admin.css" rel="stylesheet" />
    
</head>

<body>
        <!--== MAIN CONTAINER ==-->
      <!--== MAIN CONTAINER ==-->
    <?php include 'includes/topbar.php'; ?>

    </div>
    </div>

    <div class="container-fluid sb2">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            <div class="sb2-2 col-md-9">
              

                <div class="db-2">
                    <?php if ($notice): ?>
                        <div class="table-container" style="background:#ecfeff;color:#065f46">
                            <?php echo htmlspecialchars($notice); ?>
                        </div><?php
endif; ?>
                    <?php if ($error): ?>
                        <div class="table-container" style="background:#fee2e2;color:#991b1b">
                            <?php echo htmlspecialchars($error); ?>
                        </div><?php
endif; ?>

                    <div class="table-container">
                        <h2>Categories</h2>
                        <div style="display:flex;gap:10px;margin:10px 0;flex-wrap:wrap">
                            <button class="btn btn-info" type="button" onclick="enrichAllPendingKB(this)">
                                <i class="fa-solid fa-wand-magic-sparkles"></i> Enrich All
                            </button>
                            <button class="btn btn-primary" type="button" onclick="enrichAndReindexKB(this, {reloadOnSuccess:true})">
                                <i class="fa-solid fa-layer-group"></i> Enrich All &amp; Reindex
                            </button>
                            <button class="btn btn-secondary" type="button" onclick="reindexKB(this)">Rebuild Search Index</button>
                            <a href="web_scraper.php" class="btn btn-primary">
                                <i class="fa-solid fa-globe"></i> Web Scraper Management
                            </a>
                            <span id="reindex-status" class="badge" style="display:none"></span>
                        </div>
                        <form method="post"
                            style="display:grid;grid-template-columns:1fr 1fr 1fr 120px;gap:10px;margin-bottom:10px">
                            <input type="hidden" name="action" value="create_category">
                            <input type="text" name="category_name" placeholder="Category name" required>
                            <input type="text" name="description" placeholder="Description">
                            <select name="parent_category_id">
                                <option value="">No parent</option>
                                <?php foreach ($categories as $pc): ?>
                                    <option value="<?php echo (int)$pc['category_id']; ?>">
                                        <?php echo htmlspecialchars($pc['category_name']); ?>
                                    </option>
                                <?php
endforeach; ?>
                            </select>
                            <button class="btn btn-primary" type="submit">Add</button>
                        </form>
                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Description</th>
                                <th>Parent</th>
                                <th>Active</th>
                                <th>Actions</th>
                            </tr>
                            <?php foreach ($categories as $c): ?>
                                <tr>
                                    <td><?php echo (int)$c['category_id']; ?></td>
                                    <td><?php echo htmlspecialchars($c['category_name']); ?></td>
                                    <td><?php echo htmlspecialchars($c['description']); ?></td>
                                    <td><?php echo htmlspecialchars((string)$c['parent_category_id']); ?></td>
                                    <td><?php echo $c['is_active'] ? 'Yes' : 'No'; ?></td>
                                    <td>
                                        <form method="post"
                                            style="display:inline-grid;grid-template-columns:200px 260px 200px 100px 100px;gap:6px;align-items:center">
                                            <input type="hidden" name="action" value="update_category">
                                            <input type="hidden" name="category_id"
                                                value="<?php echo (int)$c['category_id']; ?>">
                                            <input type="text" name="category_name"
                                                value="<?php echo htmlspecialchars($c['category_name']); ?>">
                                            <input type="text" name="description"
                                                value="<?php echo htmlspecialchars((string)$c['description']); ?>">
                                            <select name="parent_category_id">
                                                <option value="">No parent</option>
                                                <?php foreach ($categories as $pc):
        if ((int)$pc['category_id'] === (int)$c['category_id'])
            continue; ?>
                                                    <option value="<?php echo (int)$pc['category_id']; ?>" <?php echo ((int)($c['parent_category_id'] ?? 0) === (int)$pc['category_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($pc['category_name']); ?></option>
                                                <?php
    endforeach; ?>
                                            </select>
                                            <label style="display:flex;gap:6px;align-items:center"><input type="checkbox"
                                                    name="is_active" <?php echo $c['is_active'] ? 'checked' : ''; ?>>
                                                Active</label>
                                            <button class="btn btn-secondary" type="submit">Save</button>
                                        </form>
                                        <form method="post" style="display:inline"
                                            onsubmit="return confirm('Delete category?')">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id"
                                                value="<?php echo (int)$c['category_id']; ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php
endforeach; ?>
                        </table>
                    </div>

                    <div class="table-container">
                        <h2>Documents</h2>
                        <form method="get" style="display:flex;gap:10px;margin-bottom:10px">
                            <select name="category_id" style="max-width:260px">
                                <option value="0">All Categories</option>
                                <?php foreach ($categories as $c): ?>
                                    <option value="<?php echo (int)$c['category_id']; ?>" <?php echo $filter_cat === (int)$c['category_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c['category_name']); ?>
                                    </option>
                                <?php
endforeach; ?>
                            </select>
                            <input type="text" name="q" placeholder="Search title/content"
                                value="<?php echo htmlspecialchars($filter_q); ?>">
                            <button class="btn btn-secondary" type="submit">Filter</button>
                            <a class="btn btn-secondary" href="export.php?table=knowledge_base_documents">Export</a>
                        </form>
                        <form method="post" enctype="multipart/form-data"
                            style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
                            <input type="hidden" name="action" value="create_doc">
                            <input type="text" name="title" placeholder="Document title" required>
                            <input type="hidden" name="category_id" id="category_id">
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;grid-column:1/3">
                                <select id="cat_lvl1">
                                    <option value="">Category</option>
                                </select>
                                <select id="cat_lvl2">
                                    <option value="">Subcategory</option>
                                </select>
                                <select id="cat_lvl3">
                                    <option value="">Sub-Subcategory</option>
                                </select>
                            </div>
                            <textarea name="description" placeholder="Short description (optional)" rows="3"
                                style="grid-column:1/3"></textarea>
                            <select name="source_type" id="source_type">
                                <option value="manual">Manual</option>
                                <option value="scraped">Scraped</option>
                                <option value="uploaded">Uploaded</option>
                                <option value="api">API</option>
                            </select>
                            <input type="url" name="source_url" id="source_url"
                                placeholder="Source URL (for scraped/api)" style="display:none">
                            <input type="file" name="upload_file" id="upload_file" accept=".txt,.md,.doc,.docx,.pdf"
                                style="display:none">
                            <div id="scraped_picker" style="grid-column:1/3;display:none">
                                <select name="scraped_select"
                                    onchange="if(this.value){document.querySelector('#source_url').value=this.value;}">
                                    <option value="">Select recent scraped page (optional)</option>
                                    <?php foreach ($recent_scraped as $s): ?>
                                        <option value="<?php echo htmlspecialchars($s['page_url']); ?>">
                                            <?php echo htmlspecialchars(($s['source_name'] ? ($s['source_name'] . ' - ') : '') . ($s['page_title'] ?: $s['page_url']) . ' [' . ($s['scraped_at']) . ']'); ?>
                                        </option>
                                    <?php
endforeach; ?>
                                </select>
                            </div>
                            <select name="content_type">
                                <option value="general">General</option>
                                <option value="program">Program</option>
                                <option value="admission">Admission</option>
                                <option value="fee">Fee</option>
                                <option value="service">Service</option>
                                <option value="policy">Policy</option>
                                <option value="event">Event</option>
                            </select>
                            <label style="display:flex;gap:6px;align-items:center"><input type="checkbox"
                                    name="is_public" checked> Public</label>
                            <textarea name="content" placeholder="Content... (or leave empty if using uploaded/scraped)"
                                rows="6" style="grid-column:1/3"></textarea>
                            <button class="btn btn-primary" type="submit" style="grid-column:1/3">Create
                                Document</button>
                        </form>

                        <table>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Category</th>
                                <th>Source</th>
                                <th>Public</th>
                                <th>Views</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                            <?php foreach ($docs as $d): ?>
                                <tr>
                                    <td><?php echo (int)$d['document_id']; ?></td>
                                    <td>
                                        <details>
                                            <summary><?php echo htmlspecialchars($d['title']); ?></summary>
                                            <div
                                                style="white-space:pre-wrap;max-height:200px;overflow:auto;margin-top:6px;border-top:1px dashed #eee;padding-top:6px">
                                                <?php echo htmlspecialchars(mb_substr($d['content'] ?? '', 0, 1000)); ?>
                                            </div>
                                        </details>
                                    </td>
                                    <td><?php echo htmlspecialchars($d['category_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($d['source_type']); ?><?php if (!empty($d['source_url'])): ?>
                                            - <a href="<?php echo htmlspecialchars($d['source_url']); ?>"
                                                target="_blank">link</a><?php
    endif; ?><?php if (!empty($d['file_path'])): ?> -
                                            <a href="<?php echo htmlspecialchars($d['file_path']); ?>"
                                                target="_blank">file</a><?php
    endif; ?>
                                    </td>
                                    <td><?php echo ($d['is_public'] ? 'Yes' : 'No'); ?></td>
                                    <td><?php echo (int)$d['view_count']; ?></td>
                                    <td><?php echo htmlspecialchars($d['updated_at']); ?></td>
                                    <td>
                                        <form method="post" enctype="multipart/form-data"
                                            style="display:grid;grid-template-columns:1fr 140px 140px 140px 160px 120px 90px;gap:6px;align-items:center">
                                            <input type="hidden" name="action" value="update_doc">
                                            <input type="hidden" name="document_id"
                                                value="<?php echo (int)$d['document_id']; ?>">
                                            <input type="text" name="title"
                                                value="<?php echo htmlspecialchars($d['title']); ?>">
                                            <select name="category_id">
                                                <option value="">No category</option>
                                                <?php foreach ($categories as $c): ?>
                                                    <option value="<?php echo (int)$c['category_id']; ?>" <?php echo ((int)$d['category_id'] === (int)$c['category_id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($c['category_name']); ?>
                                                    </option>
                                                <?php
    endforeach; ?>
                                            </select>
                                            <select name="source_type">
                                                <option value="manual" <?php echo $d['source_type'] === 'manual' ? 'selected' : ''; ?>>Manual</option>
                                                <option value="scraped" <?php echo $d['source_type'] === 'scraped' ? 'selected' : ''; ?>>Scraped</option>
                                                <option value="uploaded" <?php echo $d['source_type'] === 'uploaded' ? 'selected' : ''; ?>>Uploaded</option>
                                                <option value="api" <?php echo $d['source_type'] === 'api' ? 'selected' : ''; ?>>
                                                    API</option>
                                            </select>
                                            <input type="url" name="source_url" placeholder="Source URL"
                                                value="<?php echo htmlspecialchars((string)$d['source_url']); ?>">
                                            <input type="file" name="upload_file" accept=".txt,.md,.doc,.docx,.pdf">
                                            <input type="hidden" name="existing_file_path"
                                                value="<?php echo htmlspecialchars((string)$d['file_path']); ?>">
                                            <select name="content_type">
                                                <option value="general" <?php echo $d['content_type'] === 'general' ? 'selected' : ''; ?>>General</option>
                                                <option value="program" <?php echo $d['content_type'] === 'program' ? 'selected' : ''; ?>>Program</option>
                                                <option value="admission" <?php echo $d['content_type'] === 'admission' ? 'selected' : ''; ?>>Admission</option>
                                                <option value="fee" <?php echo $d['content_type'] === 'fee' ? 'selected' : ''; ?>>
                                                    Fee</option>
                                                <option value="service" <?php echo $d['content_type'] === 'service' ? 'selected' : ''; ?>>Service</option>
                                                <option value="policy" <?php echo $d['content_type'] === 'policy' ? 'selected' : ''; ?>>Policy</option>
                                                <option value="event" <?php echo $d['content_type'] === 'event' ? 'selected' : ''; ?>>Event</option>
                                            </select>
                                            <label style="display:flex;gap:6px;align-items:center"><input type="checkbox"
                                                    name="is_public" <?php echo $d['is_public'] ? 'checked' : ''; ?>>
                                                Public</label>
                                            <button class="btn btn-secondary" type="submit">Save</button>
                                        </form>
                                        <form method="post" style="display:inline"
                                            onsubmit="return confirm('Delete document?')">
                                            <input type="hidden" name="action" value="delete_doc">
                                            <input type="hidden" name="document_id"
                                                value="<?php echo (int)$d['document_id']; ?>">
                                            <button class="btn btn-danger" type="submit">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php
endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="js/main.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <script src="js/custom.js"></script>
    <script src="js/kb_admin.js"></script>
    <script>
        // Category cascading selects
        document.addEventListener('DOMContentLoaded', () => {
            try {
                const categories = <?php echo json_encode($categories, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
                const byParent = new Map();
                for (const c of categories) {
                    const p = (c.parent_category_id === null) ? 'root' : String(c.parent_category_id);
                    if (!byParent.has(p)) byParent.set(p, []);
                    byParent.get(p).push(c);
                }

                const lvl1 = document.getElementById('cat_lvl1');
                const lvl2 = document.getElementById('cat_lvl2');
                const lvl3 = document.getElementById('cat_lvl3');
                const hidden = document.getElementById('category_id');

                function fillOptions(sel, items, placeholder) {
                    sel.innerHTML = '';
                    const opt0 = document.createElement('option');
                    opt0.value = '';
                    opt0.textContent = placeholder;
                    sel.appendChild(opt0);
                    for (const it of (items || [])) {
                        const opt = document.createElement('option');
                        opt.value = String(it.category_id);
                        opt.textContent = it.category_name;
                        sel.appendChild(opt);
                    }
                }

                function updateHidden() {
                    hidden.value = lvl3.value || lvl2.value || lvl1.value || '';
                }

                function onLvl1Change() {
                    const id = lvl1.value;
                    fillOptions(lvl2, byParent.get(id || '') || [], 'Subcategory');
                    fillOptions(lvl3, [], 'Sub-Subcategory');
                    updateHidden();
                }
                function onLvl2Change() {
                    const id = lvl2.value;
                    fillOptions(lvl3, byParent.get(id || '') || [], 'Sub-Subcategory');
                    updateHidden();
                }
                function onLvl3Change() { updateHidden(); }

                // init
                fillOptions(lvl1, byParent.get('root') || [], 'Category');
                fillOptions(lvl2, [], 'Subcategory');
                fillOptions(lvl3, [], 'Sub-Subcategory');
                lvl1.addEventListener('change', onLvl1Change);
                lvl2.addEventListener('change', onLvl2Change);
                lvl3.addEventListener('change', onLvl3Change);
            } catch (e) {
                console.warn('Category selector init failed', e);
            }

            // Source inputs toggle
            const sourceSel = document.getElementById('source_type');
            const urlInput = document.getElementById('source_url');
            const fileInput = document.getElementById('upload_file');
            const scrapedPicker = document.getElementById('scraped_picker');
            function toggleSource() {
                const v = sourceSel ? sourceSel.value : 'manual';
                if (!urlInput || !fileInput || !scrapedPicker) return;
                urlInput.style.display = (v === 'scraped' || v === 'api') ? '' : 'none';
                scrapedPicker.style.display = (v === 'scraped') ? '' : 'none';
                fileInput.style.display = (v === 'uploaded') ? '' : 'none';
            }
            if (sourceSel) {
                sourceSel.addEventListener('change', toggleSource);
                toggleSource();
            }
        });

        // Notification update function
        function updateNotificationCount() {
            fetch('fetch_queries.php')
                .then(response => response.json())
                .then(data => {
                    const notYetCount = document.getElementById('not-yet-count');
                    if (notYetCount) {
                        if (data.not_yet_count > 0) {
                            notYetCount.textContent = data.not_yet_count;
                            notYetCount.style.display = 'inline';
                        } else {
                            notYetCount.style.display = 'none';
                        }
                    }
                })
                .catch(error => console.error('Error fetching notification count:', error));
        }

        updateNotificationCount();
        setInterval(updateNotificationCount, 60000);
    </script>
</body>

</html>