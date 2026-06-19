<?php
$current = basename($_SERVER['PHP_SELF']);
function active($name, $current)
{
    return $current === $name ? 'menu-active' : '';
}
?>

<div class="sb2-1" id="adminSidebar">
    <div class="sb2-12" style="position:relative;">
        <!-- Collapse toggle button -->
        <button id="sidebarToggleBtn" onclick="toggleSidebar()" title="Collapse/Expand sidebar" aria-label="Toggle sidebar">
            <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
        </button>
        <div class="admin-profile-container">
            <div class="admin-avatar-wrapper">
                <?php
                $avatarPath = 'images/default_admin_icon.png';
                if (isset($admin) && is_array($admin)) {
                    $aid = (int) ($admin['admin_id'] ?? 0);
                    $candidates = [
                        "uploads/admin_{$aid}_avatar.jpg",
                        "uploads/admin_{$aid}_avatar.jpeg",
                        "uploads/admin_{$aid}_avatar.png",
                        "uploads/admin_{$aid}_avatar.webp",
                    ];
                    foreach ($candidates as $p) {
                        if (file_exists($p)) {
                            $avatarPath = $p;
                            break;
                        }
                    }
                }
                ?>
                <?php if (file_exists($avatarPath)): ?>
                    <img src="<?= htmlspecialchars($avatarPath) ?>" alt="Admin avatar" class="admin-profile-image">
                    <div class="admin-status-indicator"></div>
                <?php else: ?>
                    <i class="fa-solid fa-circle-user admin-default-icon"></i>
                <?php endif; ?>
            </div>
            <div class="admin-info-wrapper">
                <?php if (isset($admin) && is_array($admin)): ?>
                    <div class="admin-name"><?= htmlspecialchars($admin['username']) ?></div>
                    <div class="admin-id">ID: <?= htmlspecialchars($admin['admin_id']) ?></div>
                <?php endif; ?>
            </div>
            <div class="admin-badge">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
        </div>
    </div>
    <div class="sb2-13">
        <ul class="nav flex-column sb-grouped-nav" role="navigation" aria-label="Sidebar Navigation">

            <!-- ── Overview ── -->
            <li class="sb-section-header" data-section="overview">
                <span class="sb-section-label"><i class="fa-solid fa-gauge"></i> <span class="sb-label">Overview</span></span>
            </li>
            <li class="sb-section-item s-overview <?php echo active('index.php', $current); ?>">
                <a href="index" data-label="Dashboard"><i class="fa-solid fa-chart-bar"></i>
                    <span class="sb-label"> Dashboard</span></a>
            </li>

            <!-- ── Operations ── -->
            <li class="sb-section-header" data-section="operations">
                <span class="sb-section-label"><i class="fa-solid fa-headset"></i> <span class="sb-label">Operations</span></span>
            </li>
            <li class="sb-section-item s-operations <?php echo active('chatlogs.php', $current); ?>">
                <a href="chatlogs" data-label="Chatlogs"><i class="fa-solid fa-comments"></i>
                    <span class="sb-label"> Chatlogs</span></a>
            </li>
            <li class="sb-section-item s-operations <?php echo active('pushed_query.php', $current); ?>">
                <a href="Inquiries" data-label="Open Inquiries"><i class="fa-solid fa-inbox"></i>
                    <span class="sb-label"> Open Inquiries</span></a>
            </li>
            <li class="sb-section-item s-operations <?php echo active('feedback.php', $current); ?>">
                <a href="feedback" data-label="Feedback"><i class="fa-solid fa-star"></i>
                    <span class="sb-label"> Feedback</span></a>
            </li>

            <!-- ── Knowledge Base ── -->
            <li class="sb-section-header" data-section="kb">
                <span class="sb-section-label"><i class="fa-solid fa-book-open"></i> <span class="sb-label">Knowledge Base</span></span>
            </li>
            <li class="sb-section-item s-kb <?php echo active('entity_manage.php', $current); ?>">
                <a href="entity_manage" data-label="Entity Manager"><i class="fa-solid fa-sitemap"></i>
                    <span class="sb-label"> Entity Manager</span></a>
            </li>
            <li class="sb-section-item s-kb <?php echo active('web_scraper.php', $current); ?>">
                <a href="web_scraper" data-label="Web Scraper"><i class="fa-solid fa-spider"></i>
                    <span class="sb-label"> Web Scraper</span></a>
            </li>
            <li class="sb-section-item s-kb <?php echo active('FAQ.php', $current); ?>">
                <a href="FAQ" data-label="FAQ Top"><i class="fa-solid fa-circle-question"></i>
                    <span class="sb-label"> FAQ Top</span></a>
            </li>

            <!-- ── Analytics & System ── -->
            <li class="sb-section-header" data-section="system">
                <span class="sb-section-label"><i class="fa-solid fa-server"></i> <span class="sb-label">Analytics & System</span></span>
            </li>
            <li class="sb-section-item s-system <?php echo active('user_interactions.php', $current); ?>">
                <a href="user_interactions" data-label="User Analytics"><i class="fa-solid fa-chart-line"></i>
                    <span class="sb-label"> User Analytics</span></a>
            </li>
            <li class="sb-section-item s-system <?php echo active('ai_models.php', $current); ?>">
                <a href="ai_models" data-label="AI Models"><i class="fa-solid fa-robot"></i>
                    <span class="sb-label"> AI Models</span></a>
            </li>
            <li class="sb-section-item s-system <?php echo active('system_logs.php', $current); ?>">
                <a href="system_logs" data-label="System Logs"><i class="fa-solid fa-heart-pulse"></i>
                    <span class="sb-label"> System Logs</span></a>
            </li>
            <li class="sb-section-item s-system <?php echo active('report.php', $current); ?>">
                <a href="report" data-label="Report"><i class="fa-solid fa-file-lines"></i>
                    <span class="sb-label"> Report</span></a>
            </li>
            <li class="sb-section-item s-system <?php echo active('admin-setting.php', $current); ?>">
                <a href="admin-setting" data-label="Account Settings"><i class="fa-solid fa-gears"></i>
                    <span class="sb-label"> Account Settings</span></a>
            </li>

            <!-- ── Quick Actions ── -->
            <li class="sb-section-header" style="margin-top:20px;">
                <span class="sb-section-label"><i class="fa-solid fa-bolt"></i> <span class="sb-label">Actions</span></span>
            </li>
            <li class="sb-section-item s-quick">
                <a href="http://<?php echo $_SERVER['HTTP_HOST']; ?>:5173" target="_blank" data-label="Launch Chatbot">
                    <i class="fa-solid fa-rocket" aria-hidden="true"></i>
                    <span class="sb-label"> Launch Chatbot</span></a>
            </li>

            <li class="sb-section-item s-quick">
                <a href="admin-logout" data-label="Logout" style="color:#ef4444;">
                    <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                    <span class="sb-label"> Logout</span></a>
            </li>

            <span style="height: 50px;"></span>
        </ul>
    </div>
</div>

<style>
    /* Sidebar Section Headers */
    .sb-section-header {
        list-style: none;
        padding: 14px 16px 4px 16px;
        margin-top: 4px;
    }

    .sb-section-header:first-child {
        margin-top: 0;
    }

    .sb-section-label {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        color: rgba(255, 255, 255, 0.4);
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 6px;
        cursor: default;
        user-select: none;
    }

    .sb-section-label i {
        font-size: 0.62rem;
    }

    /* Hide section labels when collapsed */
    .sb-collapsed .sb-section-header {
        padding: 8px 0 2px;
    }

    .sb-collapsed .sb-section-header .sb-section-label {
        justify-content: center;
    }

    .sb-collapsed .sb-section-header .sb-section-label .sb-label {
        display: none;
    }

    .sb-collapsed .sb-section-header .sb-section-label i {
        font-size: 0.7rem;
        opacity: 0.4;
    }

    /* Subtle divider between groups */
    .sb-section-header+.sb-section-item {}

    .sb-section-header::before {
        content: '';
        display: block;
        height: 1px;
        background: rgba(255, 255, 255, 0.06);
        margin-bottom: 8px;
    }

    .sb-section-header:first-child::before {
        display: none;
    }
</style>

<script>
    /* ── Collapsible Sidebar ── */
    (function() {
        const sidebar = document.getElementById('adminSidebar');
        const icon = document.getElementById('sidebarToggleIcon');
        const STORAGE_KEY = 'admin_sidebar_collapsed';

        function applyCollapsed(collapsed) {
            if (collapsed) {
                sidebar.classList.add('sb-collapsed');
                document.body.classList.add('sb-collapsed-body');
                if (icon) {
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                }
            } else {
                sidebar.classList.remove('sb-collapsed');
                document.body.classList.remove('sb-collapsed-body');
                if (icon) {
                    icon.classList.remove('fa-chevron-right');
                    icon.classList.add('fa-chevron-left');
                }
            }
        }

        // Restore saved state
        applyCollapsed(sessionStorage.getItem(STORAGE_KEY) === '1');

        // Auto-collapse on mobile
        if (window.innerWidth <= 768) applyCollapsed(true);

        window.toggleSidebar = function() {
            const isCollapsed = sidebar.classList.contains('sb-collapsed');
            const next = !isCollapsed;
            sessionStorage.setItem(STORAGE_KEY, next ? '1' : '0');
            applyCollapsed(next);
        };
    })();


</script>