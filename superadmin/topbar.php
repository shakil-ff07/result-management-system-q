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
                echo htmlspecialchars($display_name); 
            ?>
                <span class="badge ms-2" style="background: rgba(180, 83, 9, 0.1); color: var(--prestige-gold); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid rgba(180, 83, 9, 0.2);">Superadmin</span>
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

            <div class="dropdown">
                <a href="#" class="d-flex align-items-center text-decoration-none" id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <div style="width: 34px; height: 34px; background: var(--prestige-navy); color: var(--prestige-gold-light); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; border: 2px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.1); font-size: 0.8rem;">
                        <?php 
                            $initials = 'A';
                            if(isset($_SESSION['admin_fullname'])) {
                                $words = explode(' ', $_SESSION['admin_fullname']);
                                $initials = strtoupper(substr($words[0], 0, 1));
                                if(count($words) > 1) {
                                    $initials .= strtoupper(substr($words[count($words)-1], 0, 1));
                                }
                            }
                            echo $initials;
                        ?>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm" aria-labelledby="userDropdown" style="border: 1px solid var(--prestige-border); border-radius: 8px; margin-top: 10px;">
                    <li>
                        <a class="dropdown-item py-2" href="dashboard.php" style="font-size: 0.85rem; font-weight: 500;">
                            <i class="fas fa-user-circle me-2 text-muted"></i> Profile Details
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item text-danger py-2" href="logout.php" style="font-size: 0.85rem; font-weight: 600;">
                            <i class="fas fa-sign-out-alt me-2"></i> Secure Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>