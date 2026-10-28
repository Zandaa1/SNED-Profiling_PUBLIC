<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$user_role = $user_role ?? $_SESSION['role'] ?? 'user';
$username = $username ?? $_SESSION['username'] ?? 'User';

// Check for Data Privacy Acknowledgment
$show_privacy_modal = false;

$form_pages = ['iep_editor.php', 'ilp_form.php', 'itp_form.php', 'sf9_form.php', 'bir_form.php', 'learnerForm.php', 'assessment_form.php'];
$is_form_page = in_array($currentPage, $form_pages);
?>
<?php
/* Hallmark · component: sidebar · genre: editorial · theme: Quiet (existing)
 * pre-emit critique: P4 H4 E5 S5 R5 V5
 */
?>
<style>
    /* System-wide data entry forms uppercase styling */
    input[type="text"]:not([name*="username"]):not([name*="password"]):not([name="csrf_token"]),
    textarea {
        text-transform: uppercase;
    }

    <?php if ($is_form_page): ?>
    .sidebar-global-toggle,
    .app-sidebar,
    #sidebarToggle {
        display: none !important;
    }
    .app-shell {
        --sidebar-width: 0px !important;
    }
    /* Compact layout adjustments to zoom out the forms */
    .container-fluid {
        margin-left: auto !important;
        margin-right: auto !important;
    }
    header.sticky-top {
        padding: 8px 16px !important;
        margin-bottom: 12px !important;
    }
    .form-control, .form-select {
        height: 34px !important;
        padding: 4px 8px !important;
        font-size: 13.5px !important;
    }
    textarea.form-control {
        min-height: 70px !important;
    }
    .form-label {
        font-size: 12.5px !important;
        margin-bottom: 2px !important;
    }
    .form-row-gap {
        margin-bottom: 8px !important;
    }
    .form-group-spacing {
        gap: 2px !important;
    }
    .sned-card-body {
        padding: 12px !important;
    }
    @media (min-width: 768px) {
        .sned-card-body {
            padding: 24px !important;
        }
    }
    .sned-card {
        margin-bottom: 10px !important;
    }
    @media (min-width: 768px) {
        .sned-card {
            margin-bottom: 20px !important;
        }
    }
    .sned-card-header {
        padding: 8px 12px !important;
    }
    @media (min-width: 768px) {
        .sned-card-header {
            padding: 16px 24px !important;
        }
    }
    .sned-card-header h5 {
        font-size: 13.5px !important;
    }
    @media (min-width: 768px) {
        .sned-card-header h5 {
            font-size: 16px !important;
        }
    }
    .tile-checkbox-label, .tile-radio-label {
        min-height: 34px !important;
        padding: 4px 8px !important;
        font-size: 12.5px !important;
    }
    .tile-checkbox-wrapper input[type="checkbox"],
    .tile-radio-wrapper input[type="radio"] {
        top: 10px !important;
        left: 10px !important;
    }
    .btn {
        padding: 4px 8px !important;
        font-size: 13.5px !important;
    }
    /* Stepper navigation compacting */
    .step-progress-wrapper {
        margin-bottom: 10px !important;
    }
    .step-progress-item {
        padding: 6px 10px !important;
        font-size: 12.5px !important;
    }
    @media (max-width: 768px) {
        .step-progress-wrapper {
            overflow-x: auto !important;
            justify-content: flex-start !important;
            gap: 16px !important;
            white-space: nowrap !important;
            padding: 12px 8px !important;
        }
        .step-progress-item {
            flex: 0 0 110px !important;
        }
        .step-progress-item:not(:last-child)::after {
            display: none !important;
        }
    }
    /* Compact tables inside forms */
    .table th, .table td {
        padding: 6px 8px !important;
        font-size: 13px !important;
    }
    /* Compact the form section headers / subtitles */
    .form-section-title {
        font-size: 14px !important;
        margin-bottom: 8px !important;
    }
    .form-section-subtitle {
        font-size: 12px !important;
        margin-bottom: 6px !important;
    }
    <?php endif; ?>

    :root {
        --sidebar-width: 260px;
        --sidebar-collapsed-width: 64px;
    }

    .app-shell { overflow-x: hidden; }

    .sidebar-global-toggle {
        position: fixed;
        top: 1rem;
        left: 1rem;
        width: 44px;
        height: 44px;
        border: 1px solid var(--color-rule-light);
        border-radius: var(--radius-pill);
        background: rgba(255, 255, 255, 0.92);
        color: var(--color-ink);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 1045;
        transition: left var(--dur-short) var(--ease-out),
                    background var(--dur-short) var(--ease-out),
                    color var(--dur-short) var(--ease-out),
                    border-color var(--dur-short) var(--ease-out),
                    box-shadow var(--dur-short) var(--ease-out);
    }

    .sidebar-global-toggle:hover {
        background: var(--color-paper);
        color: var(--sned-accent);
        border-color: color-mix(in srgb, var(--sned-accent) 24%, var(--color-rule-light));
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16);
    }

    .sidebar-global-toggle:focus-visible {
        outline: 2px solid var(--sned-accent);
        outline-offset: 2px;
    }

    /* Hide the old page-level toggle buttons; sidebar owns this control now on desktop. */
    @media (min-width: 769px) {
        #sidebarToggle {
            display: none !important;
        }
    }
    
    .app-sidebar {
        width: var(--sidebar-width);
        min-width: var(--sidebar-width);
        flex: 0 0 var(--sidebar-width);
        height: 100vh;
        position: sticky;
        top: 0;
        background: #E8EDE9;
        border-right: 1px solid var(--color-rule-light);
        transition: width var(--dur-short) var(--ease-out),
                    min-width var(--dur-short) var(--ease-out),
                    flex var(--dur-short) var(--ease-out),
                    transform var(--dur-short) var(--ease-out), 
                    margin-right var(--dur-short) var(--ease-out);
        z-index: 1035;
    }

    .app-shell.sidebar-hidden .app-sidebar {
        transform: translateX(calc(-1 * var(--sidebar-width)));
        margin-right: calc(-1 * var(--sidebar-width));
        pointer-events: none;
    }

    .sidebar-nav {
        display: flex;
        flex-direction: column;
        gap: var(--space-3xs);
    }

    .sidebar-nav .nav-item {
        list-style: none;
    }

    .sidebar-nav-link {
        display: flex;
        align-items: center;
        gap: var(--space-sm);
        padding: var(--space-xs) var(--space-sm);
        border-radius: var(--radius-md);
        font-weight: 500;
        font-size: var(--text-sm);
        color: var(--color-muted);
        text-decoration: none;
        transition: background var(--dur-short) var(--ease-out), color var(--dur-short) var(--ease-out);
    }

    .sidebar-nav-link:hover {
        color: var(--color-ink);
        background: var(--color-paper-3);
    }

    .sidebar-nav-link:hover i {
        transform: translateX(2px);
    }

    .sidebar-nav-link:focus-visible {
        outline: 2px solid var(--color-focus);
        outline-offset: 2px;
    }

    .sidebar-nav-link:active {
        background: var(--color-paper-3);
    }

    .sidebar-nav-link.active {
        color: #ffffff;
        background: var(--sned-primary);
        font-weight: 600;
        border-left: 3px solid var(--sned-mint);
        padding-left: calc(var(--space-sm) - 3px);
    }

    .sidebar-nav-link i {
        font-size: 1.1rem;
        width: 1.25rem;
        text-align: center;
        flex-shrink: 0;
        transition: transform var(--dur-short) var(--ease-out);
    }

    .sidebar-section-label {
        font-size: var(--text-xs);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--color-muted);
        font-weight: 600;
        padding: 0 var(--space-sm);
        margin-bottom: var(--space-2xs);
    }

    .sidebar-divider {
        border: none;
        border-top: 1px solid var(--color-rule-light);
        margin: var(--space-sm) 0;
        opacity: 1;
    }

    .sidebar-brand-row {
        min-height: 48px;
        padding: 2px 0 var(--space-2xs);
    }

    .sidebar-brand-link {
        min-width: 0;
        max-width: 100%;
        padding: 4px 6px 4px 2px;
        border-radius: var(--radius-md);
        color: inherit;
        transition: background var(--dur-short) var(--ease-out);
    }

    .sidebar-brand-link:hover {
        background: rgba(255, 255, 255, 0.45);
    }

    .sidebar-brand-link:focus-visible {
        outline: 2px solid var(--color-focus);
        outline-offset: 2px;
    }

    .sidebar-brand-mark {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        background: var(--sned-primary);
        color: #ffffff;
        flex: 0 0 38px;
        box-shadow: 0 8px 18px rgba(30, 112, 79, 0.16);
    }

    .sidebar-brand-mark i {
        font-size: 1.08rem;
        line-height: 1;
    }

    .sidebar-brand-text {
        min-width: 0;
        padding-top: 1px;
    }

    .sidebar-brand-title {
        color: var(--color-ink);
        font-size: 0.92rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.05;
    }

    .sidebar-brand-subtitle {
        color: var(--color-muted);
        font-size: 0.66rem;
        font-weight: 500;
        letter-spacing: -0.005em;
        line-height: 1.12;
        margin-top: 2px;
        white-space: nowrap;
    }

    .sidebar-profile-btn {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-sm);
        padding: 10px 12px;
        border-radius: 16px;
        border: 1px solid rgba(27, 67, 50, 0.08);
        background: rgba(255, 255, 255, 0.68);
        width: 100%;
        text-align: left;
        color: var(--color-ink);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        transition: background var(--dur-short) var(--ease-out),
                    border-color var(--dur-short) var(--ease-out),
                    box-shadow var(--dur-short) var(--ease-out),
                    transform var(--dur-short) var(--ease-out);
    }

    .sidebar-profile-btn:hover {
        background: rgba(255, 255, 255, 0.9);
        border-color: rgba(27, 67, 50, 0.14);
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
        transform: translateY(-1px);
    }

    .sidebar-profile-btn:focus-visible {
        outline: 2px solid var(--color-focus);
        outline-offset: 2px;
    }

    .sidebar-profile-btn:active {
        background: rgba(255, 255, 255, 0.94);
        transform: translateY(0);
    }

    .sidebar-profile-btn.dropdown-toggle::after {
        display: none;
    }

    .sidebar-profile-main {
        min-width: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1 1 auto;
    }

    .sidebar-profile-accent {
        width: 34px;
        height: 34px;
        border-radius: 12px;
        flex: 0 0 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(45, 106, 79, 0.12), rgba(64, 145, 108, 0.22));
        color: var(--sned-primary);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.55);
    }

    .sidebar-profile-accent i {
        font-size: 0.95rem;
        line-height: 1;
    }

    .sidebar-profile-label {
        font-size: 0.64rem;
        line-height: 1;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        font-weight: 700;
        color: color-mix(in srgb, var(--sned-primary) 62%, var(--color-muted));
        margin-bottom: 4px;
    }

    .sidebar-profile-name {
        font-size: 0.98rem;
        line-height: 1.1;
        color: var(--color-ink);
        max-width: 140px;
    }

    .sidebar-profile-meta {
        font-size: 0.72rem;
        color: var(--sned-primary);
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        line-height: 1.15;
    }

    .sidebar-profile-caret {
        width: 28px;
        height: 28px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 28px;
        color: var(--color-muted);
        background: rgba(255, 255, 255, 0.65);
        border: 1px solid rgba(15, 23, 42, 0.06);
    }

    .sidebar-profile-btn[aria-expanded="true"] .sidebar-profile-caret {
        color: var(--sned-primary);
        background: rgba(45, 106, 79, 0.08);
        border-color: rgba(45, 106, 79, 0.16);
    }

    .sidebar-profile-dropdown .dropdown-menu {
        min-width: 12rem;
        z-index: 1070;
    }

    .sidebar-profile-dropdown {
        position: static;
        z-index: 1070;
    }

    .sidebar-badge-count {
        background: var(--color-danger);
        color: var(--color-paper);
        font-size: 0.65rem;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: var(--radius-pill);
        line-height: 1.3;
    }

    /* Mobile */
    /* Desktop: sidebar-hidden = collapsed icon-only */
    @media (min-width: 769px) {
        .app-shell:not(.sidebar-hidden) .sidebar-global-toggle {
            left: calc(var(--sidebar-width) - 22px);
        }

        .app-shell.sidebar-hidden .sidebar-global-toggle {
            left: calc(var(--sidebar-collapsed-width) - 22px);
        }

        .app-shell.sidebar-hidden .app-sidebar {
            transform: none;
            margin-right: 0;
            pointer-events: auto;
            width: var(--sidebar-collapsed-width);
            min-width: var(--sidebar-collapsed-width);
            flex: 0 0 var(--sidebar-collapsed-width);
        }

        .app-shell.sidebar-hidden .sidebar-nav-link {
            justify-content: center;
            padding: var(--space-xs);
            gap: 0;
        }

        .app-shell.sidebar-hidden .sidebar-nav-link i {
            font-size: 1.25rem;
        }

        .app-shell.sidebar-hidden .sidebar-nav-text {
            display: none;
        }

        .app-shell.sidebar-hidden .sidebar-section-label {
            display: none;
        }

        .app-shell.sidebar-hidden .sidebar-brand-text {
            display: none;
        }

        .app-shell.sidebar-hidden .sidebar-brand-row {
            justify-content: center !important;
            padding-top: 0;
        }

        .app-shell.sidebar-hidden .sidebar-brand-link {
            justify-content: center;
            padding: 4px;
        }

        .app-shell.sidebar-hidden .sidebar-brand-mark {
            width: 36px;
            height: 36px;
            flex-basis: 36px;
        }

        .app-shell.sidebar-hidden .sidebar-divider {
            margin: var(--space-2xs) var(--space-sm);
        }

        .app-shell.sidebar-hidden .sidebar-profile-btn {
            justify-content: center;
            padding: 8px;
            border-radius: 14px;
        }

        .app-shell.sidebar-hidden .sidebar-profile-info {
            display: none;
        }

        .app-shell.sidebar-hidden .sidebar-profile-caret {
            display: none;
        }

        .app-shell.sidebar-hidden .sidebar-profile-main {
            justify-content: center;
        }
    }

    @media (min-width: 769px) {
        .sidebar-profile-dropdown.dropend .dropdown-menu:not([data-bs-popper]) {
            top: 0;
            left: 100%;
            margin-top: 0;
            margin-left: var(--space-2xs);
            z-index: 1071;
        }
    }

    @media (max-width: 768px) {
        .sidebar-global-toggle {
            display: none !important;
        }
         .app-sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 280px;
            min-width: 280px;
            z-index: 1040;
            transform: translateX(-100%);
            box-shadow: 0 0 20px rgba(15, 23, 42, 0.12);
            background: #E8EDE9;
            border-right: 1px solid var(--color-rule-light);
        }

        .app-shell.sidebar-hidden .app-sidebar {
            transform: translateX(-100%);
            pointer-events: none;
        }

        .app-shell:not(.sidebar-hidden) .app-sidebar {
            transform: translateX(0);
            pointer-events: auto;
        }

        .sidebar-backdrop {
            position: fixed;
            inset: 0;
            width: 100vw;
            height: 100vh;
            background: rgba(15, 23, 42, 0.25);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 1030;
            opacity: 0;
            transition: opacity var(--dur-md) var(--ease-out);
            pointer-events: none;
        }

        .sidebar-backdrop.visible {
            opacity: 1;
            pointer-events: auto;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .app-sidebar,
        .sidebar-backdrop {
            transition: none;
        }
    }

    .sidebar-sub-nav {
        display: flex;
        flex-direction: column;
        gap: var(--space-3xs);
        padding-left: var(--space-sm);
        margin-left: calc(var(--space-sm) + 0.6rem); /* Align connecting line with parent icon center */
        border-left: 1.5px solid var(--color-rule);
        margin-top: var(--space-3xs);
        margin-bottom: var(--space-3xs);
    }

    .sidebar-sub-sub-nav {
        display: flex;
        flex-direction: column;
        gap: var(--space-3xs);
        padding-left: var(--space-sm);
        margin-left: calc(var(--space-sm) + 0.5rem); /* Align connecting line with sub-parent icon center */
        border-left: 1.5px solid var(--color-rule);
        margin-top: var(--space-3xs);
        margin-bottom: var(--space-3xs);
    }

    .sidebar-sub-nav .sidebar-nav-link {
        font-size: 0.825rem;
        padding: var(--space-2xs) var(--space-xs);
        color: var(--color-muted);
    }

    .sidebar-sub-nav .sidebar-nav-link i {
        font-size: 0.95rem;
        width: 1.1rem;
    }

    .sidebar-sub-nav .sidebar-nav-link.active {
        color: var(--sned-primary);
        background: var(--sned-dew);
        font-weight: 600;
        border-left: 2px solid var(--sned-mint);
        padding-left: calc(var(--space-xs) - 2px);
    }

    @media (min-width: 769px) {
        .app-shell.sidebar-hidden .sidebar-sub-nav,
        .app-shell.sidebar-hidden .sidebar-sub-sub-nav {
            display: none !important;
        }
    }
</style>



<?php if (!$is_form_page): ?>
<button type="button" class="sidebar-global-toggle" data-sidebar-toggle aria-label="Toggle sidebar" aria-expanded="false" title="Toggle menu">
    <i class="bi bi-list"></i>
</button>
<?php endif; ?>

<?php if (!$is_form_page): ?>
<div class="app-sidebar">
    <div class="d-flex flex-column h-100">
        <!-- Scrollable nav area -->
        <div class="sidebar-scroll-area flex-grow-1 overflow-y-auto p-3" style="gap:var(--space-2xs);display:flex;flex-direction:column;">
        <!-- Brand -->
        <div class="sidebar-brand-row d-flex align-items-center justify-content-between mb-1">
            <a href="main.php" class="sidebar-brand-link text-decoration-none d-flex align-items-center gap-2">
                <img src="img/valenzuela_gov_logo.webp" style="width: 32px; height: 32px; object-fit: contain;" alt="Valenzuela Logo">
                <div class="sidebar-brand-text lh-1">
                    <div class="sidebar-brand-title">ValSNED</div>
                    <div class="sidebar-brand-subtitle">Learners &amp; Welfare</div>
                </div>
            </a>
            <button type="button" class="btn-close d-md-none" aria-label="Close sidebar" onclick="document.querySelector('.app-shell').classList.add('sidebar-hidden')"></button>
        </div>

        <hr class="sidebar-divider">

        <!-- Main Nav -->
        <nav class="sidebar-nav" aria-label="Main navigation">
            <div class="nav-item">
                <a href="main.php" class="sidebar-nav-link <?= ($currentPage === 'main.php') ? 'active' : '' ?>">
                    <i class="bi bi-speedometer2"></i> <span class="sidebar-nav-text">Dashboard</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="learners.php" class="sidebar-nav-link <?= ($currentPage === 'learners.php' || $currentPage === 'learnerForm.php') ? 'active' : '' ?>">
                    <i class="bi bi-people"></i> <span class="sidebar-nav-text">Learners</span>
                </a>
                <div class="sidebar-sub-nav">
                    <div class="nav-item">
                        <a href="learners.php" class="sidebar-nav-link <?= ($currentPage === 'learners.php') ? 'active' : '' ?>">
                            <i class="bi bi-list-ul"></i> <span class="sidebar-nav-text">Learner Directory</span>
                        </a>
                    </div>
                    <?php if (user_is_coordinator($user_role)): ?>
                    <div class="nav-item">
                        <a href="learnerForm.php" class="sidebar-nav-link <?= ($currentPage === 'learnerForm.php' && empty($_GET['id'])) ? 'active' : '' ?>">
                            <i class="bi bi-person-plus"></i> <span class="sidebar-nav-text">Register Learner</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="nav-item">
                <a href="school_profile.php" class="sidebar-nav-link <?= ($currentPage === 'school_profile.php') ? 'active' : '' ?>">
                    <i class="bi bi-building-gear"></i> <span class="sidebar-nav-text">My School</span>
                </a>
                <div class="sidebar-sub-nav">
                    <div class="nav-item">
                        <?php
                        $view_school_url = 'school_profile.php';
                        if (user_is_scoped($user_role) && !empty($_SESSION['school_id'])) {
                            $view_school_url = 'school_profile.php?id=' . (int)$_SESSION['school_id'];
                        }
                        ?>
                        <a href="<?= $view_school_url ?>" class="sidebar-nav-link <?= ($currentPage === 'school_profile.php' && isset($_GET['id'])) ? 'active' : '' ?>">
                            <i class="bi bi-eye"></i> <span class="sidebar-nav-text">View School</span>
                        </a>
                    </div>
                    <?php if (user_is_coordinator($user_role)): ?>
                    <div class="nav-item">
                        <a href="promotion.php" class="sidebar-nav-link <?= ($currentPage === 'promotion.php') ? 'active' : '' ?>">
                            <i class="bi bi-graph-up-arrow"></i> <span class="sidebar-nav-text">Learner Promotion</span>
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if (user_is_coordinator($user_role) && !user_is_teacher($user_role)): ?>
                    <div class="nav-item">
                        <a href="transfer.php" class="sidebar-nav-link <?= ($currentPage === 'transfer.php') ? 'active' : '' ?>">
                            <i class="bi bi-arrow-left-right"></i> <span class="sidebar-nav-text">Request Transfer</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </nav>

        <?php if (user_is_coordinator($user_role) && $user_role !== 'teacher'): ?>
        <hr class="sidebar-divider">
        <div class="sidebar-section-label">Management</div>
        <nav class="sidebar-nav" aria-label="SNED management">
            <?php if (user_is_admin($user_role)): ?>
                <div class="nav-item">
                    <a href="schools.php" class="sidebar-nav-link <?= ($currentPage === 'schools.php' && !isset($_GET['add'])) ? 'active' : '' ?>">
                        <i class="bi bi-building"></i> <span class="sidebar-nav-text">Schools</span>
                    </a>
                    <div class="sidebar-sub-nav">
                        <div class="nav-item">
                            <a href="schools.php?add=1" class="sidebar-nav-link <?= ($currentPage === 'schools.php' && isset($_GET['add'])) ? 'active' : '' ?>">
                                <i class="bi bi-plus-circle"></i> <span class="sidebar-nav-text">Add new School</span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="nav-item">
                <a href="transferRequests.php" class="sidebar-nav-link <?= ($currentPage === 'transferRequests.php') ? 'active' : '' ?> d-flex align-items-center justify-content-between">
                    <span class="d-flex align-items-center" style="gap: var(--space-sm);"><i class="bi bi-inbox"></i> <span class="sidebar-nav-text">Transfer Requests</span></span>
                    <?php
                    if (isset($conn)) {
                        if (user_is_admin($user_role)) {
                            $stmt_cnt = $conn->prepare("SELECT COUNT(*) as cnt FROM transfer_requests WHERE status = 'pending'");
                        } else {
                            $stmt_cnt = $conn->prepare("SELECT COUNT(*) as cnt FROM transfer_requests WHERE status = 'pending' AND from_school_id = ?");
                            $stmt_cnt->bind_param("i", $_SESSION['school_id']);
                        }
                        if ($stmt_cnt && $stmt_cnt->execute()) {
                            $tr_res = $stmt_cnt->get_result()->fetch_assoc();
                            $tr_cnt = $tr_res['cnt'] ?? 0;
                            if ($tr_cnt > 0) {
                                echo '<span class="sidebar-badge-count">' . $tr_cnt . '</span>';
                            }
                        }
                    }
                    ?>
                </a>
            </div>
            <?php if (user_is_coordinator($user_role) && !user_is_teacher($user_role)): ?>
            <div class="nav-item">
                <a href="teacherManagement.php" class="sidebar-nav-link <?= ($currentPage === 'teacherManagement.php' && !isset($_GET['add'])) ? 'active' : '' ?>">
                    <i class="bi bi-person-video3"></i> <span class="sidebar-nav-text">Teachers</span>
                </a>
                <div class="sidebar-sub-nav">
                    <div class="nav-item">
                        <a href="teacherManagement.php" class="sidebar-nav-link <?= ($currentPage === 'teacherManagement.php' && !isset($_GET['add'])) ? 'active' : '' ?>">
                            <i class="bi bi-list-ul"></i> <span class="sidebar-nav-text">Manage Teachers</span>
                        </a>
                    </div>
                    <div class="nav-item">
                        <a href="teacherManagement.php?add=1" class="sidebar-nav-link <?= ($currentPage === 'teacherManagement.php' && isset($_GET['add'])) ? 'active' : '' ?>">
                            <i class="bi bi-plus-circle"></i> <span class="sidebar-nav-text">Add new Teacher</span>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php if (isset($user_role) && user_is_admin($user_role)): ?>
        <hr class="sidebar-divider">
        <div class="sidebar-section-label">Administration</div>
        <nav class="sidebar-nav" aria-label="Administration">
            <div class="nav-item">
                <a href="adminCreateUser.php" class="sidebar-nav-link <?= ($currentPage === 'adminCreateUser.php') ? 'active' : '' ?>">
                    <i class="bi bi-person-gear"></i> <span class="sidebar-nav-text">Users</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="transaction_logs.php" class="sidebar-nav-link <?= ($currentPage === 'transaction_logs.php') ? 'active' : '' ?>">
                    <i class="bi bi-list-columns"></i> <span class="sidebar-nav-text">Logs</span>
                </a>
            </div>
            <div class="nav-item">
                <a href="trash.php" class="sidebar-nav-link <?= ($currentPage === 'trash.php') ? 'active' : '' ?>">
                    <i class="bi bi-archive"></i> <span class="sidebar-nav-text">Archived</span>
                </a>
            </div>
            <?php if ($user_role === 'superadmin'): ?>
            <div class="nav-item">
                <a href="announcements.php" class="sidebar-nav-link <?= ($currentPage === 'announcements.php') ? 'active' : '' ?>">
                    <i class="bi bi-megaphone"></i> <span class="sidebar-nav-text">Announcements</span>
                </a>
            </div>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php if (isset($user_role) && $user_role === 'superadmin' && defined('DEBUG_SAMPLE_DATA_ENABLED') && DEBUG_SAMPLE_DATA_ENABLED): ?>
        <hr class="sidebar-divider">
        <div class="sidebar-section-label">Dev Tools</div>
        <nav class="sidebar-nav" aria-label="Developer tools">
            <div class="nav-item">
                <a href="debugSampleData.php" class="sidebar-nav-link <?= ($currentPage === 'debugSampleData.php') ? 'active' : '' ?>">
                    <i class="bi bi-bug"></i> <span class="sidebar-nav-text">Debug</span>
                </a>
            </div>
        </nav>
        <?php endif; ?>
        
        </div>
        <!-- End scrollable nav area -->
        
        <?php
        $user_assigned_school_name = 'Global Access';
        if (user_is_scoped($user_role) && !empty($_SESSION['school_id'])) {
            $stmt_sch = $conn->prepare("SELECT school_name FROM schools WHERE id = ?");
            $stmt_sch->bind_param("i", $_SESSION['school_id']);
            $stmt_sch->execute();
            $row_sch = $stmt_sch->get_result()->fetch_assoc();
            if ($row_sch) {
                $user_assigned_school_name = $row_sch['school_name'];
            } else {
                $user_assigned_school_name = 'Unknown School';
            }
        }
        ?>
        
        <!-- Footer - not clipped by overflow -->
        <div class="sidebar-footer p-3 pt-0">
            <nav class="sidebar-nav" aria-label="Help">
                <div class="nav-item">
                    <a href="quickguide.php" class="sidebar-nav-link <?= ($currentPage === 'quickguide.php') ? 'active' : '' ?>">
                        <i class="bi bi-info-circle"></i> <span class="sidebar-nav-text">Help</span>
                    </a>
                </div>
            </nav>
            <hr class="sidebar-divider">
            <div class="dropdown dropend sidebar-profile-dropdown">
                <button type="button" class="sidebar-profile-btn dropdown-toggle" id="profileMenuButton" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false">
                    <div class="sidebar-profile-main">
                        <span class="sidebar-profile-accent" aria-hidden="true">
                            <i class="bi bi-person-gear"></i>
                        </span>
                        <div class="sidebar-profile-info overflow-hidden flex-grow-1" style="min-width:0;">
                            <div class="sidebar-profile-label">Account</div>
                            <div class="sidebar-profile-name text-truncate fw-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? $username ?? 'User') ?></div>
                            <div class="sidebar-profile-meta"><?= htmlspecialchars($user_assigned_school_name) ?></div>
                        </div>
                    </div>
                    <span class="sidebar-profile-caret" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                </button>
                <ul class="dropdown-menu shadow-sm" aria-labelledby="profileMenuButton" style="border-radius:var(--radius-md);border-color:var(--color-rule-light);">
                    <?php if (TWO_FA_ENABLED): ?>
                    <li><a class="dropdown-item d-flex align-items-center gap-2 py-2" href="2fa_setup.php"><i class="bi bi-shield-lock" style="color:var(--color-muted);"></i> Security</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <?php endif; ?>
                    <li><a class="dropdown-item d-flex align-items-center gap-2 py-2 text-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Sign out</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if (!$is_form_page): ?>
<script>
    (function() {
        const htmlElement = document.documentElement;
        const storageKey = 'sidebarCollapsed';
        htmlElement.setAttribute('data-bs-theme', 'light');

        document.addEventListener('DOMContentLoaded', function() {
            initSidebarState();
        });

        function initSidebarState() {
            const shell = document.querySelector('.app-shell');
            if (!shell) return;

            const backdrop = document.createElement('div');
            backdrop.className = 'sidebar-backdrop d-none';
            document.body.appendChild(backdrop);

            function isMobile() {
                return window.innerWidth <= 768;
            }

            function setSidebarHidden(hidden, shouldPersist) {
                shell.classList.toggle('sidebar-hidden', hidden);
                document.querySelectorAll('[data-sidebar-toggle], #sidebarToggle').forEach((toggle) => {
                    toggle.setAttribute('aria-expanded', String(!hidden));
                    toggle.setAttribute('aria-pressed', String(hidden));
                });
                if (shouldPersist && !isMobile()) {
                    localStorage.setItem(storageKey, hidden ? 'true' : 'false');
                }
                syncBackdrop();
            }

            function syncInitialState() {
                if (isMobile()) {
                    setSidebarHidden(true, false);
                    return;
                }
                setSidebarHidden(localStorage.getItem(storageKey) === 'true', false);
            }

            function syncBackdrop() {
                const showBackdrop = isMobile() && !shell.classList.contains('sidebar-hidden');
                if (showBackdrop) {
                    backdrop.classList.remove('d-none');
                    requestAnimationFrame(() => backdrop.classList.add('visible'));
                } else {
                    backdrop.classList.remove('visible');
                    setTimeout(() => {
                        if (!isMobile() || shell.classList.contains('sidebar-hidden')) {
                            backdrop.classList.add('d-none');
                        }
                    }, 250);
                }
            }

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('[data-sidebar-toggle], #sidebarToggle');
                if (!btn) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                setSidebarHidden(!shell.classList.contains('sidebar-hidden'), true);
            }, true);

            document.querySelectorAll('.app-sidebar .btn-close').forEach((closeBtn) => {
                closeBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    setSidebarHidden(true, false);
                });
            });

            backdrop.addEventListener('click', () => setSidebarHidden(true, false));

            const observer = new MutationObserver(() => syncBackdrop());
            observer.observe(shell, { attributes: true, attributeFilter: ['class'] });

            window.addEventListener('resize', syncInitialState);
            syncInitialState();
        }
    })();
</script>
<?php endif; ?>

<!-- Global System Notice Modal -->
<div class="modal fade" id="systemNoticeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 p-4 text-center">
            <div class="modal-body">
                <div class="display-1 text-primary mb-3" id="noticeIcon"><i class="bi bi-info-circle-fill"></i></div>
                <h3 class="fw-bold mb-1" id="noticeTitle">Notice</h3>
                <p class="text-muted mb-4" id="noticeMsg"></p>
                <button type="button" class="btn btn-primary w-100 rounded-pill py-2 fw-bold" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<script>
    function showSystemNotice(msg, title = 'Notice', type = 'primary') {
        const modalEl = document.getElementById('systemNoticeModal');
        const titleEl = document.getElementById('noticeTitle');
        const msgEl = document.getElementById('noticeMsg');
        const iconEl = document.getElementById('noticeIcon');
        
        titleEl.innerText = title;
        msgEl.innerText = msg;
        
        iconEl.className = `display-1 mb-3 text-${type}`;
        if (type === 'danger') iconEl.innerHTML = '<i class="bi bi-exclamation-octagon-fill"></i>';
        else if (type === 'warning') iconEl.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i>';
        else if (type === 'success') iconEl.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
        else iconEl.innerHTML = '<i class="bi bi-info-circle-fill"></i>';

        const modal = new bootstrap.Modal(modalEl);
        modal.show();
    }

    // Optional uppercase text input handling.
    // Use data-force-uppercase="true" or .js-force-uppercase on fields that truly
    // need uppercase formatting. Normal writing fields preserve the user's casing.
    document.addEventListener('input', function(e) {
        const target = e.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement)) {
            return;
        }

        const shouldForceUppercase = target.dataset.forceUppercase === 'true'
            || target.classList.contains('js-force-uppercase');

        if (shouldForceUppercase) {
            const start = e.target.selectionStart;
            const end = e.target.selectionEnd;
            e.target.value = e.target.value.toUpperCase();
            if (start !== null && end !== null) {
                e.target.setSelectionRange(start, end);
            }
        }
    });
</script>
