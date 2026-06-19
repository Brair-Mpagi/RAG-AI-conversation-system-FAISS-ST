<?php
/**
 * Migration: Add scraper hierarchy support
 * Run once via browser: http://localhost/Admin-F/run_migration.php
 * DELETE this file after running.
 */
session_start();
require_once 'db.php';

if (!isset($_SESSION['admin_id'])) {
    die('<p>Not logged in. <a href="admin-login.php">Login first</a></p>');
}

$results = [];
$errors  = [];

function run_sql($conn, $label, $sql) {
    global $results, $errors;
    if ($conn->query($sql)) {
        $results[] = "✓ $label";
    } else {
        $errors[] = "✗ $label — " . $conn->error;
    }
}

// ── 1. Add hierarchy columns to scraped_content ──────────────────────────────
$check = $conn->query("SHOW COLUMNS FROM scraped_content LIKE 'parent_url'");
if ($check && $check->num_rows === 0) {
    run_sql($conn, "ADD parent_url column",
        "ALTER TABLE scraped_content ADD COLUMN parent_url VARCHAR(2048) DEFAULT NULL");
    run_sql($conn, "ADD crawl_depth column",
        "ALTER TABLE scraped_content ADD COLUMN crawl_depth INT DEFAULT 0");
    run_sql($conn, "ADD page_category column",
        "ALTER TABLE scraped_content ADD COLUMN page_category VARCHAR(100) DEFAULT NULL");
    run_sql($conn, "ADD INDEX idx_crawl_depth",
        "ALTER TABLE scraped_content ADD INDEX idx_crawl_depth (crawl_depth)");
} else {
    $results[] = "= parent_url column already exists — skipped";
}

// ── 2. Create scrape_link_queue ───────────────────────────────────────────────
run_sql($conn, "CREATE scrape_link_queue table", "
    CREATE TABLE IF NOT EXISTS scrape_link_queue (
      queue_id            INT AUTO_INCREMENT PRIMARY KEY,
      source_id           INT NOT NULL,
      page_url            VARCHAR(2048) NOT NULL,
      discovered_from_url VARCHAR(2048) DEFAULT NULL,
      crawl_depth         INT DEFAULT 0,
      status              ENUM('pending','scraping','done','failed','skipped') DEFAULT 'pending',
      discovered_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      processed_at        TIMESTAMP NULL,
      FOREIGN KEY (source_id) REFERENCES scraping_sources(source_id) ON DELETE CASCADE,
      UNIQUE KEY uk_source_url (source_id, page_url(500)),
      INDEX idx_status (status),
      INDEX idx_source_id (source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── 3. Backfill crawl_depth=0 for existing rows that have no depth ────────────
run_sql($conn, "Backfill crawl_depth=0 for existing rows",
    "UPDATE scraped_content SET crawl_depth = 0 WHERE crawl_depth IS NULL");

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Migration Result</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container" style="max-width:700px">
    <h3 class="mb-4">🛠 Scraper Migration</h3>

    <?php foreach ($results as $r): ?>
      <div class="alert alert-success py-2"><?= htmlspecialchars($r) ?></div>
    <?php endforeach; ?>

    <?php foreach ($errors as $e): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php if (empty($errors)): ?>
      <div class="alert alert-info mt-3">
        ✅ Migration complete. <strong>Delete this file now:</strong><br>
        <code>rm <?= __FILE__ ?></code>
      </div>
    <?php else: ?>
      <div class="alert alert-warning mt-3">⚠ Some steps failed — check errors above.</div>
    <?php endif; ?>

    <a href="web_scraper.php" class="btn btn-primary mt-3">← Back to Scraper</a>
  </div>
</body>
</html>
