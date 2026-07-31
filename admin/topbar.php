<?php
if (isset($_SESSION['admin_id']) && isset($conn)) {
    try {
        $stmt_name = $conn->prepare("SELECT full_name, username FROM admins WHERE id = ?");
        $stmt_name->execute([$_SESSION['admin_id']]);
        $current_admin = $stmt_name->fetch();
        if ($current_admin) {
            $_SESSION['admin_fullname'] = $current_admin['full_name'];
            $_SESSION['admin_username'] = $current_admin['username'];
        }
    } catch (Exception $e) {
        // Silently ignore DB errors during topbar load
    }
}
?>
<nav class="navbar navbar-expand-lg navbar-light">
    <div class="container-fluid px-0 d-flex align-items-center flex-nowrap">
        <button class="btn btn-link d-md-none me-2" id="sidebarToggle" onclick="toggleSidebar()" style="color: var(--prestige-navy); text-decoration: none;">
            <i class="fa fa-bars" style="font-size: 1.25rem;"></i>
        </button>
        <span class="navbar-brand mb-0 h1 d-flex align-items-center gap-1 gap-sm-2" style="font-size: clamp(0.9rem, 3.5vw, 1.15rem); color: var(--prestige-navy); font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            <span class="d-none d-sm-inline" style="font-weight: 400; color: #64748b;">Welcome back,</span>
            <?php 
                $display_name = !empty($_SESSION['admin_fullname']) ? $_SESSION['admin_fullname'] : $_SESSION['admin_username'];
                if ($display_name === 'System Administrator' || $display_name === 'admin') {
                    $display_name = 'Principal';
                }
                echo htmlspecialchars($display_name); 
            ?>
            <?php if (is_headmaster()): ?>
                <span class="badge ms-2" style="background: rgba(180, 83, 9, 0.1); color: var(--prestige-gold); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid rgba(180, 83, 9, 0.2);">Principal</span>
            <?php elseif (is_teacher()): ?>
                <span class="badge ms-2" style="background: rgba(15, 23, 42, 0.05); color: var(--prestige-navy); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid rgba(15, 23, 42, 0.1);">Teacher</span>
            <?php endif; ?>
        </span>
        <div class="ms-auto d-flex align-items-center flex-shrink-0">
            <div class="d-none d-md-flex align-items-center bg-light px-3 py-2 rounded-pill" style="border: 1px solid var(--prestige-border);">
                <i class="far fa-calendar-alt me-2" style="color: var(--prestige-gold);"></i>
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--prestige-navy);">
                    <?php echo date('l, F j, Y'); ?>
                </span>
            </div>
            
            <div class="vr mx-3 d-none d-md-block" style="opacity: 0.1;"></div>

            <!-- Midnight Prestige Dark Mode Toggle -->
            <button id="dmToggleBtn" class="dm-toggle me-2" onclick="toggleDarkMode()" title="Toggle Dark Mode">
                <i class="fas fa-moon"></i>
            </button>
        </div>
    </div>
</nav>