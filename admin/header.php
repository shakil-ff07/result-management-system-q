<script>(function(){var t=localStorage.getItem('srms-theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
<link rel="icon" type="image/png" href="../logo/logo.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --sidebar-width: 280px;
        --prestige-navy: #0f172a;
        --prestige-gold: #b45309;
        --prestige-gold-light: #fef3c7;
        --prestige-slate: #f8fafc;
        --prestige-border: #e2e8f0;
        --prestige-text: #1e293b;
        --primary-color: var(--prestige-navy);
        --dark-color: var(--prestige-navy);
    }

    body {
        background-color: var(--prestige-slate);
        font-family: 'Inter', sans-serif;
        color: var(--prestige-text);
    }

    .serif-font {
        font-family: 'Playfair Display', serif;
    }

    #sidebar {
        width: var(--sidebar-width);
        height: 100vh; /* Fallback */
        height: 100dvh;
        position: fixed;
        left: 0;
        top: 0;
        background: var(--prestige-navy);
        color: white;
        transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        z-index: 1000;
        box-shadow: 4px 0 24px rgba(15, 23, 42, 0.1);
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        padding-bottom: env(safe-area-inset-bottom, 20px);
    }

    /* Custom Scrollbar for Light Theme */
    ::-webkit-scrollbar {
        width: 10px;
    }
    ::-webkit-scrollbar-track {
        background: #f1f5f9;
    }
    ::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
        border: 2px solid #f1f5f9;
    }
    ::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    /* Custom Scrollbar for Sidebar (Light) */
    #sidebar::-webkit-scrollbar {
        width: 6px;
    }
    #sidebar::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.02);
    }
    #sidebar::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 10px;
    }
    #sidebar::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, 0.2);
    }

    .sidebar-header {
        padding: 30px 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }

    .sidebar-link {
        padding: 14px 24px;
        display: block;
        color: rgba(255, 255, 255, 0.6);
        text-decoration: none;
        transition: 0.2s;
        border-left: 4px solid transparent;
        font-weight: 500;
        font-size: 0.95rem;
    }

    .sidebar-link:hover {
        background: rgba(255, 255, 255, 0.05);
        color: white;
    }

    .sidebar-link.active {
        background: rgba(180, 83, 9, 0.15);
        color: white;
        border-left: 4px solid var(--prestige-gold);
    }

    .sidebar-link i {
        margin-right: 12px;
        width: 20px;
        text-align: center;
        opacity: 0.8;
    }
    
    .sidebar-link.active i {
        color: var(--prestige-gold-light);
        opacity: 1;
    }

    .logout-btn {
        background: rgba(255, 255, 255, 0.05);
        color: white;
        border: 1px solid rgba(255, 255, 255, 0.1);
        font-size: 0.85rem;
        font-weight: 600;
        padding: 12px;
        transition: all 0.3s ease;
    }

    .logout-btn:hover {
        background: rgba(220, 38, 38, 0.1);
        border-color: rgba(220, 38, 38, 0.4);
        color: #f87171;
    }

    #sidebar nav {
        display: flex;
        flex-direction: column;
        flex-grow: 1;
    }

    #content {
        margin-left: var(--sidebar-width);
        padding: 30px;
        min-height: 100vh;
        transition: margin-left 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    }

    #sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100vw;
        height: 100vh;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(4px);
        z-index: 999;
        display: none;
    }

    .navbar {
        background: white !important;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.03);
        margin-bottom: 30px;
        border: 1px solid var(--prestige-border);
        border-radius: 12px;
        padding: 15px 20px;
    }

    .card {
        background: white;
        border: 1px solid var(--prestige-border);
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.04);
        border-radius: 12px;
        margin-bottom: 24px;
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.06);
    }

    .stat-card {
        padding: 24px;
        border-left: 4px solid var(--prestige-navy);
    }

    /* Global Table and Form Prestige Styles */
    .table th {
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 1px;
        color: #64748b;
        font-weight: 700;
        padding: 16px;
        border-bottom: 2px solid var(--prestige-border) !important;
    }

    .table td {
        padding: 16px;
        vertical-align: middle;
        color: var(--prestige-text);
        border-bottom: 1px dashed var(--prestige-border) !important;
    }

    thead.table-light, thead.bg-light, .table-light th {
        background-color: #f8fafc !important;
        border-bottom: 2px solid var(--prestige-border) !important;
    }

    .form-label {
        font-weight: 600;
        font-size: 0.85rem;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-control, .form-select {
        padding: 10px 16px;
        border: 1px solid var(--prestige-border);
        border-radius: 8px;
        font-weight: 500;
        color: var(--prestige-navy);
    }

    .form-control:focus, .form-select:focus {
        border-color: var(--prestige-gold);
        box-shadow: 0 0 0 4px rgba(180, 83, 9, 0.05);
    }

    .alert {
        border-radius: 8px;
    }

    /* Global Page Header Styles */
    .page-header {
        background-color: transparent;
        color: var(--prestige-navy);
        padding: 0 0 20px 0;
        border-radius: 0;
        margin-bottom: 24px;
        border: none;
        border-bottom: 1px solid var(--prestige-border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .page-header h1 {
        font-family: 'Playfair Display', serif;
        font-size: 1.75rem;
        margin-bottom: 0.25rem;
        color: var(--prestige-navy);
        font-weight: 700;
    }

    .page-header p {
        font-size: 0.85rem;
        color: #64748b;
        margin-bottom: 0;
        font-weight: 500;
    }

    .header-icon-box {
        background: var(--prestige-slate);
        border: 1px solid var(--prestige-border);
        width: 50px;
        height: 50px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        margin-right: 1.25rem;
        color: var(--prestige-gold);
        flex-shrink: 0;
        font-size: 1.2rem;
    }

    /* Global Buttons */
    .btn-primary {
        background-color: var(--prestige-navy);
        border-color: var(--prestige-navy);
        font-weight: 600;
        letter-spacing: 0.5px;
    }
    
    .btn-primary:hover {
        background-color: #1e293b;
        border-color: #1e293b;
        box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
    }

    @media (max-width: 576px) {
        .navbar {
            padding: 10px 12px !important;
            margin-bottom: 15px !important;
        }
        .header-icon-box {
            width: 40px;
            height: 40px;
            margin-right: 1rem;
            font-size: 1rem;
        }
        .page-header h1 {
            font-size: 1.35rem;
        }
        .page-header p {
            font-size: 0.8rem;
        }
    }

    @media (max-width: 768px) {
        #sidebar {
            left: calc(-1 * var(--sidebar-width));
        }

        #sidebar.active {
            left: 0;
        }

        #sidebar.active~#sidebar-overlay {
            display: block;
        }

        #content {
            margin-left: 0 !important;
            padding: 15px;
            padding-top: 85px !important; /* Offset for fixed topbar on mobile */
        }

        .navbar {
            position: fixed !important;
            top: 0;
            left: 0;
            right: 0;
            z-index: 998;
            margin-bottom: 0 !important;
            border-radius: 0 !important;
            border-top: none !important;
            border-left: none !important;
            border-right: none !important;
            padding: 12px 16px !important;
        }

        .page-header {
            flex-direction: row !important;
            flex-wrap: wrap !important;
            align-items: center !important;
            justify-content: space-between !important;
            padding-bottom: 12px;
        }

        .header-controls {
            position: static !important;
            margin-top: 0;
            width: auto;
            order: 2;
        }

        .page-header > .d-flex.align-items-center {
            flex: 1;
            min-width: 0; /* Important for ellipsis if needed, but we'll try to fit first */
        }

        .page-header h1 {
            font-size: 1.25rem;
            white-space: nowrap;
            overflow: visible; /* Show full text */
            text-overflow: clip;
            max-width: none;
        }

        .header-controls .admit-status-card {
            background: rgba(15, 23, 42, 0.03) !important;
            border: 1px solid rgba(15, 23, 42, 0.08) !important;
            padding: 4px 12px;
            border-radius: 50px;
            box-shadow: none !important;
        }

        [data-theme="dark"] .header-controls .admit-status-card {
            background: rgba(255, 255, 255, 0.05) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
        }

        .header-controls .text-muted.text-uppercase {
            display: none; /* Hide "Entry Status:" on mobile */
        }

        .header-controls .form-check-label {
            font-size: 0.7rem !important;
            letter-spacing: 0.5px;
        }

        .page-header .header-icon-box {
            width: 38px;
            height: 38px;
            margin-right: 0.75rem;
        }

        .page-header p {
            font-size: 0.75rem;
            opacity: 0.8;
        }
    }

        [data-theme="dark"] .page-header .header-controls .admit-status-card {
            background: rgba(180, 83, 9, 0.1) !important;
        }
    }

    /* ═══════════════════════════════════════
       MIDNIGHT PRESTIGE — Dark Mode System
       ═══════════════════════════════════════ */

    /* Smooth theme transitions */
    body, .card, .navbar, .form-control, .form-select,
    .table, .dropdown-menu, .modal-content, .alert,
    .page-header, .input-group-text, .list-group-item {
        transition: background-color 0.3s ease, border-color 0.3s ease,
                    color 0.3s ease, box-shadow 0.3s ease !important;
    }

    /* Dark Mode Variables */
    [data-theme="dark"] {
        --prestige-navy:      #0f172a;
        --prestige-gold:      #f59e0b;
        --prestige-gold-light:#fef3c7;
        --prestige-slate:     #060c18;
        --prestige-border:    rgba(255,255,255,0.07);
        --prestige-text:      #cbd5e1;
    }

    /* Body */
    [data-theme="dark"] body { background-color: #060c18 !important; color: #cbd5e1 !important; }

    /* Sidebar */
    [data-theme="dark"] #sidebar { background: #050a14 !important; box-shadow: 4px 0 24px rgba(0,0,0,0.5) !important; }
    [data-theme="dark"] .sidebar-header { border-bottom-color: rgba(255,255,255,0.05) !important; }
    [data-theme="dark"] .sidebar-link { color: rgba(255,255,255,0.5); }
    [data-theme="dark"] .sidebar-link:hover { background: rgba(255,255,255,0.04); color: white; }
    [data-theme="dark"] .sidebar-link.active { background: rgba(245,158,11,0.15) !important; border-left-color: #f59e0b !important; }
    [data-theme="dark"] .sidebar-link.active i { color: #f59e0b !important; }

    /* Topbar Navbar */
    [data-theme="dark"] .navbar { background: #111827 !important; border-color: rgba(255,255,255,0.06) !important; box-shadow: 0 4px 20px rgba(0,0,0,0.3) !important; }
    [data-theme="dark"] .navbar .bg-light { background: #0a1020 !important; border-color: rgba(255,255,255,0.07) !important; }
    [data-theme="dark"] .navbar span, [data-theme="dark"] .navbar-brand { color: #cbd5e1 !important; }
    [data-theme="dark"] #sidebarToggle { color: #cbd5e1 !important; }
    [data-theme="dark"] .vr { color: rgba(255,255,255,0.08) !important; }

    /* Cards */
    [data-theme="dark"] .card { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; box-shadow: 0 15px 40px rgba(0,0,0,0.25) !important; }
    [data-theme="dark"] .card:hover { border-color: rgba(245,158,11,0.25) !important; box-shadow: 0 20px 45px rgba(0,0,0,0.4) !important; }

    /* Tables */
    [data-theme="dark"] .table th { color: #6b7280 !important; border-bottom-color: rgba(255,255,255,0.08) !important; }
    [data-theme="dark"] .table td { color: #cbd5e1 !important; border-bottom-color: rgba(255,255,255,0.04) !important; }
    [data-theme="dark"] thead.table-light th,
    [data-theme="dark"] thead.bg-light th,
    [data-theme="dark"] .table-light { background-color: #0a1020 !important; color: #6b7280 !important; border-bottom-color: rgba(255,255,255,0.08) !important; }
    [data-theme="dark"] .table-striped > tbody > tr:nth-of-type(odd) > * { background-color: rgba(255,255,255,0.02) !important; }
    [data-theme="dark"] .table-hover > tbody > tr:hover > * { background-color: rgba(245,158,11,0.05) !important; }

    /* Forms */
    [data-theme="dark"] .form-control, [data-theme="dark"] .form-select { 
        background-color: #1e293b !important; 
        border-color: rgba(255,255,255,0.15) !important; 
        color: #f1f5f9 !important; 
    }
    [data-theme="dark"] .form-control:focus, [data-theme="dark"] .form-select:focus { 
        background-color: #0f172a !important; 
        border-color: #f59e0b !important; 
        box-shadow: 0 0 0 4px rgba(245,158,11,0.15) !important; 
        color: #fff !important; 
    }
    [data-theme="dark"] .form-select {
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23f59e0b' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e") !important;
    }
    [data-theme="dark"] .form-control::placeholder { color: #64748b !important; }
    [data-theme="dark"] .form-label { color: #94a3b8 !important; }
    [data-theme="dark"] .form-text { color: #64748b !important; }
    [data-theme="dark"] .input-group-text { background-color: #1e293b !important; border-color: rgba(255,255,255,0.15) !important; color: #94a3b8 !important; }
    [data-theme="dark"] .form-check-input { background-color: #1e293b !important; border-color: rgba(255,255,255,0.3) !important; }
    [data-theme="dark"] .form-check-input:checked { background-color: #f59e0b !important; border-color: #f59e0b !important; }

    /* Gold Icons for Date & Time Pickers in Dark Mode */
    [data-theme="dark"] input[type="date"]::-webkit-calendar-picker-indicator,
    [data-theme="dark"] input[type="time"]::-webkit-calendar-picker-indicator {
        filter: invert(62%) sepia(88%) saturate(3786%) hue-rotate(1deg) brightness(101%) contrast(97%);
        cursor: pointer;
        transition: transform 0.2s ease;
    }
    [data-theme="dark"] input[type="date"]::-webkit-calendar-picker-indicator:hover,
    [data-theme="dark"] input[type="time"]::-webkit-calendar-picker-indicator:hover {
        transform: scale(1.2);
    }

    /* Page Header */
    [data-theme="dark"] .page-header { border-bottom-color: rgba(255,255,255,0.07) !important; }
    [data-theme="dark"] .page-header h1 { color: #e2e8f0 !important; }
    [data-theme="dark"] .page-header p { color: #6b7280 !important; }
    [data-theme="dark"] .header-icon-box { background: #0a1020 !important; border-color: rgba(255,255,255,0.08) !important; color: #f59e0b !important; }

    /* Buttons */
    [data-theme="dark"] .btn-primary { background-color: #f59e0b !important; border-color: #f59e0b !important; color: #0f172a !important; }
    [data-theme="dark"] .btn-primary:hover { background-color: #d97706 !important; border-color: #d97706 !important; box-shadow: 0 4px 15px rgba(245,158,11,0.35) !important; }
    [data-theme="dark"] .btn-secondary { background-color: #1e2d45 !important; border-color: rgba(255,255,255,0.1) !important; color: #94a3b8 !important; }
    [data-theme="dark"] .btn-light { background-color: #1e2d45 !important; border-color: rgba(255,255,255,0.1) !important; color: #cbd5e1 !important; }
    [data-theme="dark"] .btn-outline-primary { color: #f59e0b !important; border-color: #f59e0b !important; }
    [data-theme="dark"] .btn-outline-primary:hover { background-color: #f59e0b !important; color: #0f172a !important; }
    [data-theme="dark"] .btn-outline-secondary { color: #94a3b8 !important; border-color: rgba(255,255,255,0.15) !important; }
    [data-theme="dark"] .btn-outline-secondary:hover { background: rgba(255,255,255,0.08) !important; color: #e2e8f0 !important; }
    [data-theme="dark"] .btn-outline-danger { color: #f87171 !important; border-color: rgba(239,68,68,0.4) !important; }
    [data-theme="dark"] .btn-outline-danger:hover { background: rgba(239,68,68,0.1) !important; }

    /* Alerts */
    [data-theme="dark"] .alert-success { background-color: rgba(16,185,129,0.1) !important; border-color: rgba(16,185,129,0.2) !important; color: #34d399 !important; }
    [data-theme="dark"] .alert-danger  { background-color: rgba(239,68,68,0.1) !important;  border-color: rgba(239,68,68,0.2) !important;  color: #f87171 !important; }
    [data-theme="dark"] .alert-warning { background-color: rgba(245,158,11,0.1) !important; border-color: rgba(245,158,11,0.2) !important; color: #fbbf24 !important; }
    [data-theme="dark"] .alert-info    { background-color: rgba(59,130,246,0.1) !important;  border-color: rgba(59,130,246,0.2) !important;  color: #60a5fa !important; }

    /* Badges */
    [data-theme="dark"] .badge.bg-success  { background-color: rgba(16,185,129,0.2) !important; color: #34d399 !important; }
    [data-theme="dark"] .badge.bg-danger   { background-color: rgba(239,68,68,0.2)  !important; color: #f87171 !important; }
    [data-theme="dark"] .badge.bg-warning  { background-color: rgba(245,158,11,0.2) !important; color: #fbbf24 !important; }
    [data-theme="dark"] .badge.bg-info     { background-color: rgba(59,130,246,0.2)  !important; color: #60a5fa !important; }
    [data-theme="dark"] .badge.bg-primary  { background-color: rgba(245,158,11,0.2) !important; color: #f59e0b !important; }
    [data-theme="dark"] .badge.bg-secondary{ background-color: rgba(100,116,139,0.2)!important; color: #94a3b8 !important; }
    [data-theme="dark"] .badge.bg-light    { background-color: rgba(255,255,255,0.08)!important; color: #94a3b8 !important; }

    /* Dropdowns */
    [data-theme="dark"] .dropdown-menu { background-color: #111827 !important; border-color: rgba(255,255,255,0.08) !important; box-shadow: 0 10px 30px rgba(0,0,0,0.5) !important; }
    [data-theme="dark"] .dropdown-item { color: #cbd5e1 !important; }
    [data-theme="dark"] .dropdown-item:hover { background-color: rgba(245,158,11,0.1) !important; color: #f59e0b !important; }
    [data-theme="dark"] .dropdown-item.active { background-color: rgba(245,158,11,0.2) !important; color: #f59e0b !important; }
    [data-theme="dark"] .dropdown-divider { border-color: rgba(255,255,255,0.07) !important; }

    /* Modals */
    [data-theme="dark"] .modal-content { background-color: #111827 !important; border-color: rgba(255,255,255,0.08) !important; color: #cbd5e1 !important; }
    [data-theme="dark"] .modal-header { border-bottom-color: rgba(255,255,255,0.08) !important; }
    [data-theme="dark"] .modal-footer { border-top-color: rgba(255,255,255,0.08) !important; }
    [data-theme="dark"] .modal-title { color: #e2e8f0 !important; }
    [data-theme="dark"] .btn-close { filter: invert(1) !important; }

    /* Misc helpers */
    [data-theme="dark"] .text-muted { color: #6b7280 !important; }
    [data-theme="dark"] .text-dark   { color: #e2e8f0 !important; }
    [data-theme="dark"] .bg-white    { background-color: #111827 !important; }
    [data-theme="dark"] .bg-light    { background-color: #0a1020 !important; }
    [data-theme="dark"] hr           { border-color: rgba(255,255,255,0.07) !important; opacity: 1 !important; }
    [data-theme="dark"] .border      { border-color: rgba(255,255,255,0.07) !important; }
    [data-theme="dark"] .list-group-item { background-color: #111827 !important; border-color: rgba(255,255,255,0.07) !important; color: #cbd5e1 !important; }
    [data-theme="dark"] .nav-tabs    { border-bottom-color: rgba(255,255,255,0.08) !important; }
    [data-theme="dark"] .nav-tabs .nav-link { color: #94a3b8 !important; }
    [data-theme="dark"] .nav-tabs .nav-link.active { background-color: #111827 !important; border-color: rgba(255,255,255,0.08) rgba(255,255,255,0.08) #111827 !important; color: #f59e0b !important; }
    [data-theme="dark"] .progress { background-color: rgba(255,255,255,0.08) !important; }
    [data-theme="dark"] .page-link  { background-color: #111827 !important; border-color: rgba(255,255,255,0.08) !important; color: #94a3b8 !important; }
    [data-theme="dark"] .page-link:hover { background-color: rgba(245,158,11,0.1) !important; color: #f59e0b !important; }
    [data-theme="dark"] .page-item.active .page-link { background-color: #f59e0b !important; border-color: #f59e0b !important; color: #0f172a !important; }

    /* Dashboard-specific */
    [data-theme="dark"] .prestige-stat-card { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; }
    [data-theme="dark"] .prestige-stat-card::before { background: #f59e0b !important; }
    [data-theme="dark"] .stat-details h3 { color: #e2e8f0 !important; }
    [data-theme="dark"] .quick-action-btn { background: #111827 !important; border-color: rgba(255,255,255,0.07) !important; color: #cbd5e1 !important; }
    [data-theme="dark"] .quick-action-btn:hover { border-color: rgba(245,158,11,0.4) !important; color: #f59e0b !important; box-shadow: 0 10px 25px rgba(245,158,11,0.1) !important; }

    /* Dark Mode Toggle Button */
    .dm-toggle {
        width: 36px; height: 36px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        background: var(--prestige-slate);
        border: 1px solid var(--prestige-border);
        color: var(--prestige-gold);
        cursor: pointer; transition: all 0.2s ease; font-size: 0.9rem;
        flex-shrink: 0;
    }
    .dm-toggle:hover { background: rgba(180,83,9,0.1); border-color: var(--prestige-gold); transform: scale(1.08); }
    [data-theme="dark"] .dm-toggle { background: #0a1020; border-color: rgba(255,255,255,0.1); color: #f59e0b; }
    [data-theme="dark"] .dm-toggle:hover { background: rgba(245,158,11,0.12); border-color: #f59e0b; }

    /* Custom Scrollbar for Dark Mode */
    [data-theme="dark"] ::-webkit-scrollbar {
        width: 10px;
    }
    [data-theme="dark"] ::-webkit-scrollbar-track {
        background: #060c18 !important;
    }
    [data-theme="dark"] ::-webkit-scrollbar-thumb {
        background: #b45309 !important; /* Prestige Gold */
        border-radius: 10px;
        border: 2px solid #060c18 !important;
    }
    [data-theme="dark"] ::-webkit-scrollbar-thumb:hover {
        background: #d97706 !important;
    }

    /* Target the sidebar specifically if needed for consistency */
    [data-theme="dark"] #sidebar::-webkit-scrollbar-thumb {
        background: #b45309 !important;
    }
</style>
<script>
    // ── Sidebar Logic ──
    document.addEventListener('DOMContentLoaded', function () {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.createElement('div');
        overlay.id = 'sidebar-overlay';
        document.body.appendChild(overlay);

        window.toggleSidebar = function () { sidebar.classList.toggle('active'); };
        overlay.addEventListener('click', function () { sidebar.classList.remove('active'); });
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.addEventListener('click', () => { if (window.innerWidth <= 768) sidebar.classList.remove('active'); });
        });
    });

    // ── Midnight Prestige: Dark Mode Engine ──
    window.toggleDarkMode = function() {
        const html = document.documentElement;
        const isDark = html.getAttribute('data-theme') === 'dark';
        const next = isDark ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('srms-theme', next);
        _updateDMIcon();
    };

    function _updateDMIcon() {
        const btn = document.getElementById('dmToggleBtn');
        if (!btn) return;
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        btn.innerHTML = isDark
            ? '<i class="fas fa-sun" style="color:#f59e0b;"></i>'
            : '<i class="fas fa-moon"></i>';
        btn.title = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
    }

    // ── Global: Click anywhere on date/time field to open picker ──
    document.addEventListener('DOMContentLoaded', () => {
        _updateDMIcon();
        document.querySelectorAll('input[type="date"], input[type="time"]').forEach(input => {
            input.addEventListener('click', function () {
                if (typeof this.showPicker === 'function') {
                    try { this.showPicker(); } catch (e) {}
                }
            });
        });
    });
</script>