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
        
        <div class="px-4 mt-3 mb-1 text-uppercase" style="font-size: 0.65rem; font-weight: 800; color: rgba(255,255,255,0.4); letter-spacing: 1px;">Management</div>
        
        <a href="manage-headmasters.php"
            class="sidebar-link <?php echo ($current_page == 'manage-headmasters.php') ? 'active' : ''; ?>">
            <i class="fas fa-user-tie"></i> Manage Headmasters
        </a>

        <div class="mt-auto px-4 pb-5 pt-5">
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