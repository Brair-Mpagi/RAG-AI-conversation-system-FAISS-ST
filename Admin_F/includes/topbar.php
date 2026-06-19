<!--== MAIN CONTAINER ==-->
    <div class="container-fluid sb1">
        <div class="ent-nav-inner">
            
            <!-- Left Side: Brand and Search -->
            <div class="ent-nav-left">
                <div class="ent-brand">
                    <!-- <img src="images/mmu_logo_- no bg.png" alt="MMU Logo" class="ent-logo"> -->
                    <span class="ent-brand-text">Admin Panel</span>
                </div>
            </div>
            
            <!-- Right Side: Status, Actions, Profile -->
            <div class="ent-nav-right">
                
                <!-- System Status Indicator (Hooked to rasa health check) -->
                <div class="ent-status-badge" onclick="fetchBackendHealth()" title="Click to refresh status">
                    <span class="rasa-status-indicator nav-rasa-indicator" style="width:8px; height:8px; border-radius:50%; background:#94a3b8;"></span>
                    <span id="navHealthStatusText" style="color:#e2e8f0;">Checking API...</span>
                </div>
                
                <!-- System Alerts -->
                <a href="system_logs.php" class="ent-icon-btn" style="width: auto; padding: 0 16px; gap: 8px; border-radius: 20px; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); text-decoration: none;">
                    <i class="fa-solid fa-bell" style="color: #60a5fa;"></i>
                    <span style="font-size: 0.82rem; font-weight: 600; color: #fff;">System Alerts</span>
                </a>

                <!-- User Feedback -->
                <a href="feedback.php" class="ent-icon-btn" style="width: auto; padding: 0 16px; gap: 8px; border-radius: 20px; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); text-decoration: none;">
                    <i class="fa-solid fa-comments" style="color: #60a5fa;"></i>
                    <span style="font-size: 0.82rem; font-weight: 600; color: #fff;">User Feedback</span>
                </a>

                <!-- Incoming Queries -->
                <a href="pushed_query.php?status=pending" class="ent-icon-btn" style="width: auto; padding: 0 16px; gap: 8px; border-radius: 20px; background: rgba(59, 130, 246, 0.15); border: 1px solid rgba(59, 130, 246, 0.3); text-decoration: none;">
                    <i class="fa-solid fa-envelope-open-text" style="color: #60a5fa;"></i>
                    <span style="font-size: 0.82rem; font-weight: 600; color: #fff;">Incoming Queries</span>
                    <!-- This matches the exact ID the existing JS looks for -->
                    <span class="ent-badge" id="not-yet-count" style="position: relative; top: auto; right: auto; margin-left: 2px;">0</span>
                </a>

                <!-- Admin Profile Dropdown -->
                <div class="ent-dropdown-wrapper" style="margin-left: 8px;">
                    <div class="ent-profile-toggle">
                        <div class="ent-avatar">
                            <?= strtoupper(substr($admin["username"] ?? "A", 0, 1)) ?>
                        </div>
                        <div style="line-height:1.2;">
                            <div style="font-size:0.85rem; font-weight:600; color:#fff;">
                                <?= htmlspecialchars($admin["username"] ?? "Admin") ?>
                            </div>
                            <span class="ent-role">Super Administrator</span>
                        </div>
                        <i class="fa-solid fa-chevron-down" style="font-size: 0.7rem; color: #94a3b8; margin-left:4px;"></i>
                    </div>
                    
                    <div class="ent-dropdown-menu" style="width: 220px;">
                        <a href="admin-setting.php" class="ent-dropdown-item">
                            <i class="fa-solid fa-user-gear"></i>
                            <div>Account Settings</div>
                        </a>
                        <a href="system_logs.php" class="ent-dropdown-item">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            <div>Activity Logs</div>
                        </a>
                        <div style="height:1px; background:#e2e8f0; margin:4px 0;"></div>
                        <a href="admin-logout.php" class="ent-dropdown-item danger-link">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                            <div>Secure Logout</div>
                        </a>
                    </div>
                </div>

            </div>

<script>
                    // Backend Health Check
                    function fetchBackendHealth() {
                        const indicators = document.querySelectorAll('.rasa-status-indicator');
                        const spinner = document.querySelector('.spinner');
                        const textEl = document.getElementById('healthStatusText');
                        const navTextEl = document.getElementById('navHealthStatusText');

                        if (textEl) {
                            textEl.textContent = 'Checking...';
                            textEl.style.color = '#6b7280';
                        }
                        if (navTextEl) {
                            navTextEl.textContent = 'Checking API...';
                            navTextEl.style.color = '#94a3b8';
                        }
                        if (spinner) spinner.style.display = 'inline-block';

                        fetch('http://localhost:8000/health', {
                                signal: AbortSignal.timeout(5000)
                            })
                            .then(r => r.json())
                            .then(data => {
                                if (spinner) spinner.style.display = 'none';
                                if (data.status === 'ok' || data.status === 'healthy') {
                                    indicators.forEach(ind => {
                                        ind.classList.remove('rasa-offline');
                                        ind.classList.add('rasa-online');
                                    });
                                    if (textEl) {
                                        textEl.textContent = 'Online — uptime ' + (data.uptime_seconds ? Math.round(data.uptime_seconds) + 's' : 'N/A');
                                        textEl.style.color = '#16a34a';
                                    }
                                    if (navTextEl) {
                                        navTextEl.textContent = 'Backend Online';
                                        navTextEl.style.color = '#10b981';
                                    }
                                } else {
                                    indicators.forEach(ind => {
                                        ind.classList.remove('rasa-online');
                                        ind.classList.add('rasa-offline');
                                    });
                                    if (textEl) {
                                        textEl.textContent = 'Degraded — ' + (data.status || 'unknown');
                                        textEl.style.color = '#f59e0b';
                                    }
                                    if (navTextEl) {
                                        navTextEl.textContent = 'API Degraded';
                                        navTextEl.style.color = '#f59e0b';
                                    }
                                }
                            })
                            .catch(() => {
                                if (spinner) spinner.style.display = 'none';
                                indicators.forEach(ind => {
                                    ind.classList.remove('rasa-online');
                                    ind.classList.add('rasa-offline');
                                });
                                if (textEl) {
                                    textEl.textContent = 'Offline';
                                    textEl.style.color = '#dc2626';
                                }
                                if (navTextEl) {
                                    navTextEl.textContent = 'Backend offline';
                                    navTextEl.style.color = '#ef4444';
                                }
                            });
                    }
                    // Auto-check on load
                    fetchBackendHealth();
                </script>
