<?php
// Get the current page name
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div id="sidebar">
    <div class="sidebar-header">
        <div style="width: 50px; height: 50px; background: var(--prestige-navy); border: 2px solid var(--prestige-gold); border-radius: 10px; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; font-size: 1.5rem; color: var(--prestige-gold-light); box-shadow: 0 5px 15px rgba(0,0,0,0.2);">
            <i class="fas fa-university"></i>
        </div>
        <h5 class="mb-0 serif-font text-white" style="letter-spacing: 0.5px;">SRMS Portal</h5>
    </div>
    <nav class="mt-3 flex-grow-1">
        <a href="dashboard.php" class="sidebar-link <?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        
        <?php if (is_admin()): ?>
        <a href="analytics.php" class="sidebar-link <?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?>">
            <i class="fas fa-chart-pie"></i> Analytics
        </a>
        <?php endif; ?>

        <?php if (is_admin() || is_assistant()): ?>
            <div class="px-4 mt-3 mb-1 text-uppercase" style="font-size: 0.65rem; font-weight: 800; color: rgba(255,255,255,0.4); letter-spacing: 1px;">Management</div>
            
            <?php if (is_admin()): ?>
            <a href="manage-users.php"
                class="sidebar-link <?php echo ($current_page == 'manage-users.php') ? 'active' : ''; ?>">
                <i class="fas fa-users-cog"></i> Manage Users
            </a>
            <a href="manage-classes.php"
                class="sidebar-link <?php echo ($current_page == 'manage-classes.php') ? 'active' : ''; ?>">
                <i class="fas fa-layer-group"></i> Classes
            </a>
            <?php endif; ?>

            <a href="manage-students.php"
                class="sidebar-link <?php echo (in_array($current_page, ['manage-students.php', 'add-student.php', 'edit-student.php', 'bulk-import-students.php'])) ? 'active' : ''; ?>">
                <i class="fas fa-user-graduate"></i> Students
            </a>

            <a href="student-details.php"
                class="sidebar-link <?php echo ($current_page == 'student-details.php') ? 'active' : ''; ?>">
                <i class="fas fa-file-pdf"></i> Student Details
            </a>

            <?php if (is_admin()): ?>
            <a href="manage-subjects.php"
                class="sidebar-link <?php echo ($current_page == 'manage-subjects.php') ? 'active' : ''; ?>">
                <i class="fas fa-book"></i> Subjects
            </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!is_assistant()): ?>
        <div class="px-4 mt-3 mb-1 text-uppercase" style="font-size: 0.65rem; font-weight: 800; color: rgba(255,255,255,0.4); letter-spacing: 1px;">Academics</div>

        <a href="marks-entry.php"
            class="sidebar-link <?php echo ($current_page == 'marks-entry.php') ? 'active' : ''; ?>">
            <i class="fas fa-edit"></i> Marks Entry
        </a>

        <a href="teacher-marks-status.php"
            class="sidebar-link <?php echo ($current_page == 'teacher-marks-status.php') ? 'active' : ''; ?>">
            <i class="fas fa-clipboard-check"></i> Entry Status
        </a>

        <?php if (is_admin()): ?>
        <a href="admit-card.php"
            class="sidebar-link <?php echo ($current_page == 'admit-card.php' || $current_page == 'print-admit-card.php') ? 'active' : ''; ?>">
            <i class="fas fa-id-card"></i> Admit Card
        </a>
        <?php endif; ?>
        <?php endif; ?>


        <?php if (is_admin()): ?>
            <a href="generate-results.php"
                class="sidebar-link <?php echo ($current_page == 'generate-results.php') ? 'active' : ''; ?>">
                <i class="fas fa-cogs"></i> Results Processing
            </a>
            <a href="hall-of-fame.php"
                class="sidebar-link <?php echo ($current_page == 'hall-of-fame.php') ? 'active' : ''; ?>">
                <i class="fas fa-trophy"></i> Hall of Fame
            </a>
            <a href="promote-students.php"
                class="sidebar-link <?php echo ($current_page == 'promote-students.php') ? 'active' : ''; ?>">
                <i class="fas fa-arrow-up"></i> Promotion
            </a>
            <a href="assign-groups.php"
                class="sidebar-link <?php echo ($current_page == 'assign-groups.php') ? 'active' : ''; ?>">
                <i class="fas fa-users-cog"></i> Group Assignment
            </a>
            
            <div class="px-4 mt-3 mb-1 text-uppercase" style="font-size: 0.65rem; font-weight: 800; color: rgba(255,255,255,0.4); letter-spacing: 1px;">System</div>

            <a href="activity-logs.php"
                class="sidebar-link <?php echo ($current_page == 'activity-logs.php') ? 'active' : ''; ?>">
                <i class="fas fa-history"></i> Activity History
            </a>
            <a href="system-maintenance.php"
                class="sidebar-link <?php echo ($current_page == 'system-maintenance.php') ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i> Maintenance
            </a>
        <?php endif; ?>

        <div class="mt-auto px-4 pb-5">
            <a href="logout.php" class="btn logout-btn w-100">
                <i class="fas fa-sign-out-alt me-2 text-danger"></i> Secure Logout
            </a>
        </div>
    </nav>
</div>

<!-- Sidebar Scroll Preservation -->
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        
        // Restore scroll position
        const scrollPos = sessionStorage.getItem('sidebar-scroll');
        if (scrollPos) {
            sidebar.scrollTop = scrollPos;
        }

        // Save scroll position when any link is clicked
        sidebar.querySelectorAll('.sidebar-link').forEach(link => {
            link.addEventListener('click', () => {
                sessionStorage.setItem('sidebar-scroll', sidebar.scrollTop);
            });
        });

        // Also save on logout or other buttons
        sidebar.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => {
                sessionStorage.setItem('sidebar-scroll', sidebar.scrollTop);
            });
        });
    });
</script>