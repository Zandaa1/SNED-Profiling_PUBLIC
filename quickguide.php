<?php
session_start();
include 'config.php';

// Redirect to login if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$username = $_SESSION['username'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'user';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Documentation &amp; Quick Guide - SNED Profiling</title>
    <style>
        :root {
            --sped-indigo: var(--sned-accent);
            --sped-blue: var(--sned-info);
            --indigo-50: var(--sned-dew);
        }
        body { 
            background-color: var(--sned-cloud); 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            color: var(--sned-ink);
        }
        
        /* Sticky Header */
        .docs-navbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--sned-mist);
        }
        .docs-brand-logo {
            width: 34px;
            height: 34px;
            object-fit: contain;
        }
        .docs-brand-title {
            font-weight: 800;
            font-size: 1.1rem;
            letter-spacing: -0.02em;
            color: var(--sned-primary);
        }
        .docs-brand-subtitle {
            font-size: 0.72rem;
            color: var(--color-muted);
            font-weight: 500;
        }

        /* Hero styling */
        .docs-hero {
            background: linear-gradient(135deg, var(--sned-dew) 0%, rgba(240, 245, 241, 0.5) 100%);
            border-radius: 20px;
            padding: 3rem 2rem;
            margin-bottom: 2.5rem;
            border: 1px solid rgba(64, 145, 108, 0.12);
        }
        .search-container input {
            height: 48px;
            font-size: 1rem;
            border: 1px solid var(--sned-mist);
            transition: border-color 0.2s, box-shadow 0.2s;
            border-radius: 8px !important;
        }
        .search-container input:focus {
            border-color: var(--sned-accent);
            box-shadow: 0 0 0 0.25rem rgba(64, 145, 108, 0.15);
        }

        /* Category Cards */
        .category-card {
            transition: transform 0.2s var(--ease-out), box-shadow 0.2s var(--ease-out), border-color 0.2s;
            border: 1px solid var(--sned-mist) !important;
            border-radius: 12px !important;
            height: 100%;
        }
        .category-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(45, 106, 79, 0.08) !important;
            border-color: var(--sned-accent) !important;
        }
        .category-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        /* Section Layout */
        .guide-section {
            scroll-margin-top: 100px;
            margin-bottom: 3.5rem;
        }
        .guide-section-title { 
            font-weight: 800; 
            text-transform: uppercase; 
            letter-spacing: 1px; 
            color: var(--sned-moss); 
            font-size: 0.9rem; 
            margin-bottom: 1.5rem; 
            display: flex; 
            align-items: center; 
            gap: 12px; 
        }
        .guide-section-title::after { 
            content: ""; 
            flex-grow: 1; 
            height: 1px; 
            background: var(--sned-mist); 
        }

        /* Accordions */
        .accordion-item { 
            border: 1px solid var(--sned-mist); 
            border-radius: 12px !important; 
            overflow: hidden; 
            margin-bottom: 0.75rem; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02); 
            transition: border-color 0.25s var(--ease-out), box-shadow 0.25s var(--ease-out);
            background: #ffffff;
        }
        .accordion-item:hover {
            border-color: var(--sned-accent);
            box-shadow: 0 6px 15px rgba(45, 106, 79, 0.05);
        }
        .accordion-button { 
            font-weight: 700; 
            padding: 1.25rem; 
            font-size: 1.05rem; 
            color: var(--sned-ink); 
            background-color: #ffffff;
            border: none;
        }
        .accordion-button:not(.collapsed) { 
            background-color: var(--sned-cloud); 
            color: var(--sned-primary); 
            box-shadow: none; 
            border-bottom: 1px solid var(--sned-mist);
        }
        .accordion-button::after { 
            background-size: 1rem; 
        }
        .guide-icon { 
            width: 36px; 
            height: 36px; 
            border-radius: 8px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin-right: 15px; 
            font-size: 1.15rem; 
            flex-shrink: 0; 
        }
        .lh-relaxed { 
            line-height: 1.7; 
        }

        /* Role tags instead of pill badges */
        .role-tag {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            display: inline-block;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .role-tag-superadmin {
            background-color: var(--sned-danger-light);
            color: #7a2028;
            border: 1px solid rgba(192, 98, 106, 0.25);
        }
        .role-tag-admin {
            background-color: var(--sned-info-light);
            color: #1a5276;
            border: 1px solid rgba(69, 123, 157, 0.25);
        }
        .role-tag-coordinator {
            background-color: var(--sned-success-light);
            color: #1e5c39;
            border: 1px solid rgba(82, 183, 136, 0.25);
        }
        .role-tag-teacher {
            background-color: var(--sned-warning-light);
            color: #7d5a0a;
            border: 1px solid rgba(233, 167, 61, 0.25);
        }

        /* Support desk & warnings */
        .desk-card {
            border-radius: 16px !important;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            border-left: 5px solid var(--card-border-color) !important;
        }
        .btn-custom-action {
            font-weight: 600;
            border-radius: 8px !important;
            font-size: 0.85rem;
            padding: 0.5rem 1rem;
            transition: all 0.2s;
        }
    </style>
</head>
<body>

    <!-- Sticky Navbar -->
    <header class="docs-navbar shadow-sm sticky-top py-3">
        <div class="container d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <img src="img/valenzuela_gov_logo.webp" class="docs-brand-logo" alt="Gov Logo">
                <div class="lh-1">
                    <div class="docs-brand-title">ValSNED</div>
                    <div class="docs-brand-subtitle">Documentation Center</div>
                </div>
            </div>
            <div>
                <a href="main.php" class="btn btn-outline-primary btn-custom-action d-flex align-items-center gap-2">
                    <i class="bi bi-arrow-left-short fs-5"></i> Return to Dashboard
                </a>
            </div>
        </div>
    </header>

    <!-- Main Container -->
    <div class="container py-5" style="max-width: 1000px;">
        
        <!-- Hero Search Section -->
        <div class="docs-hero text-center">
            <h1 class="fw-black display-5 mb-2">How can we help you?</h1>
            <p class="text-muted fs-5 mb-4">Search documentation, explore workflows, or review role-based guidelines.</p>
            <div class="search-container mx-auto position-relative" style="max-width: 600px;">
                <input type="text" id="docsSearch" class="form-control form-control-lg ps-5" placeholder="Search topics, keywords, or answers...">
            </div>
        </div>

        <!-- Quick Jump Grid -->
        <div class="row g-3 mb-5" id="categoryGrid">
            <div class="col-md-6 col-lg-3">
                <a href="#section-profiling" class="text-decoration-none text-dark category-card d-block p-4 bg-white shadow-sm">
                    <div class="category-icon text-primary bg-primary-subtle mb-3"><i class="bi bi-person-vcard-fill"></i></div>
                    <h5 class="fw-bold mb-2">Learner Profiling</h5>
                    <p class="text-muted small mb-0">Guides on student intake, placement types, adult parameters, and school year limits.</p>
                </a>
            </div>
            <div class="col-md-6 col-lg-3">
                <a href="#section-enrollment" class="text-decoration-none text-dark category-card d-block p-4 bg-white shadow-sm">
                    <div class="category-icon text-success bg-success-subtle mb-3"><i class="bi bi-arrow-left-right"></i></div>
                    <h5 class="fw-bold mb-2">Enrollment &amp; Transfers</h5>
                    <p class="text-muted small mb-0">How to process promotions, handle transfers, and manage pending transfer requests.</p>
                </a>
            </div>
            <div class="col-md-6 col-lg-3">
                <a href="#section-reports" class="text-decoration-none text-dark category-card d-block p-4 bg-white shadow-sm">
                    <div class="category-icon text-info bg-info-subtle mb-3"><i class="bi bi-file-earmark-pdf"></i></div>
                    <h5 class="fw-bold mb-2">Reports &amp; Forms</h5>
                    <p class="text-muted small mb-0">Reworking IEP, ILP, and ITP templates, printing case studies, and encoding SF9 reports.</p>
                </a>
            </div>
            <div class="col-md-6 col-lg-3">
                <a href="#section-security" class="text-decoration-none text-dark category-card d-block p-4 bg-white shadow-sm">
                    <div class="category-icon text-danger bg-danger-subtle mb-3"><i class="bi bi-shield-lock-fill"></i></div>
                    <h5 class="fw-bold mb-2">Security &amp; Admin</h5>
                    <p class="text-muted small mb-0">System roles permissions matrix, setting up 2FA, logs auditing, and data privacy notice.</p>
                </a>
            </div>
        </div>

        <!-- Dynamic Empty State -->
        <div id="noResults" class="text-center py-5 d-none">
            <div class="text-muted display-1"><i class="bi bi-inbox"></i></div>
            <h4 class="fw-bold text-muted mt-3">No matching documentation topics found</h4>
            <p class="text-muted small">Try searching for other terms like "promote", "adult", "IEP", or "2FA".</p>
        </div>

        <!-- Documentation Sections -->
        
        <!-- SECTION 1: LEARNER PROFILING -->
        <div class="guide-section" id="section-profiling">
            <div class="guide-section-title">
                <i class="bi bi-person-vcard-fill text-primary"></i> Learner Profiling
            </div>
            
            <div class="accordion" id="accordionProfiling">
                
                <?php if (user_is_coordinator($user_role)): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_reg" aria-expanded="false">
                            <div class="guide-icon bg-primary-subtle text-primary"><i class="bi bi-person-plus"></i></div>
                            How do I register a new learner?
                        </button>
                    </h2>
                    <div id="q_reg" class="accordion-collapse collapse" data-bs-parent="#accordionProfiling">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>Go to <strong>Learner Directory</strong> in the sidebar and click <strong>Add Learner Profile</strong>.</p>
                            <ul class="mb-0">
                                <li>Fill out the form with the learner's personal details. The <strong>LRN</strong> is required, must be exactly 12 digits, and is immutable after registration.</li>
                                <li>Enter the learner's medical diagnosis and educational placement.</li>
                                <li>Assign a parent or guardian. If the guardian is new, select <strong>Create New Guardian</strong>.</li>
                                <li>Upload a profile picture for the student ID card. You can use the built-in photo cropper.</li>
                                <li>Note: Date of Birth must be a valid date between 1900 and the current year. Guardian, mother, and father contact numbers must contain digits only.</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_placement" aria-expanded="false">
                            <div class="guide-icon bg-info-subtle text-info"><i class="bi bi-diagram-3"></i></div>
                            What placement types are available?
                        </button>
                    </h2>
                    <div id="q_placement" class="accordion-collapse collapse" data-bs-parent="#accordionProfiling">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>There are two placement types:</p>
                            <ul>
                                <li><strong>Graded</strong> — The learner attends standard academic classes with special support.</li>
                                <li><strong>Non-Graded</strong> — The learner is in a dedicated SNED class focused on life skills and development.</li>
                            </ul>
                            <p class="mb-0">For <strong>Non-Graded</strong> placements, you can also set a <strong>Program</strong>:</p>
                            <ul class="mb-0">
                                <li><strong>Kinder</strong> — Early childhood program.</li>
                                <li><strong>Primary Level 1</strong> — Foundational learning stage.</li>
                                <li><strong>Primary Level 2</strong> — Intermediate learning stage.</li>
                                <li><strong>Primary Level 3</strong> — Advanced primary stage.</li>
                                <li><strong>Transition</strong> — Life skills and transition program.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_adult_flag" aria-expanded="false">
                            <div class="guide-icon bg-warning-subtle text-warning"><i class="bi bi-exclamation-triangle"></i></div>
                            How does the system handle adult learners?
                        </button>
                    </h2>
                    <div id="q_adult_flag" class="accordion-collapse collapse" data-bs-parent="#accordionProfiling">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Learners <strong>18 or older</strong> are automatically marked as <strong>Adult LWD</strong> in lists and reports. Their welfare support shifts to the <strong>Cash For Work Proxy</strong> program, where a relative can work and receive benefits on their behalf.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_sy" aria-expanded="false">
                            <div class="guide-icon bg-warning-subtle text-warning"><i class="bi bi-calendar-event"></i></div>
                            When does the school year change?
                        </button>
                    </h2>
                    <div id="q_sy" class="accordion-collapse collapse" data-bs-parent="#accordionProfiling">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>The school year follows the Philippine calendar (<strong>June to May</strong>). It switches automatically — there is no button to change it.</p>
                            <p>From June onward, the system uses the next school year (e.g., 2025–2026). Before June, it shows the current one.</p>
                            <p class="mb-0">The dashboard shows a countdown to the next switch. No data is lost during the change. To move learners to the next grade, use the <strong>Enrollment</strong> workspace to promote them.</p>
                        </div>
                    </div>
                </div>

                <?php if (user_is_admin($user_role)): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_del" aria-expanded="false">
                            <div class="guide-icon bg-danger-subtle text-danger"><i class="bi bi-trash3"></i></div>
                            What happens when I delete a record?
                        </button>
                    </h2>
                    <div id="q_del" class="accordion-collapse collapse" data-bs-parent="#accordionProfiling">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Deleted records are not permanently removed. They are hidden from active lists and moved to <strong>Archived Records (Trash)</strong>. You can restore them at any time with all their history intact.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- SECTION 2: ENROLLMENT & TRANSFERS -->
        <?php if (user_is_coordinator($user_role)): ?>
        <div class="guide-section" id="section-enrollment">
            <div class="guide-section-title">
                <i class="bi bi-arrow-left-right text-success"></i> Enrollment &amp; Transfers
            </div>
            
            <div class="accordion" id="accordionEnrollment">
                
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_promote" aria-expanded="false">
                            <div class="guide-icon bg-success-subtle text-success"><i class="bi bi-arrow-up-circle"></i></div>
                            How do I promote a learner?
                        </button>
                    </h2>
                    <div id="q_promote" class="accordion-collapse collapse" data-bs-parent="#accordionEnrollment">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>Go to <strong>Enrollment Workspace</strong> in the sidebar. You can promote learners one at a time or in bulk.</p>
                            <ul>
                                <li><strong>Individual</strong> — Search for a learner, pick the new grade and placement, then submit.</li>
                                <li><strong>Bulk Promotion</strong> — Filter by school and grade, select the learners, set the target grade, and promote all at once.</li>
                            </ul>
                            <p class="mb-0">The learner keeps their LRN, school, and diagnosis. Only the grade level and school year change.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_transfer" aria-expanded="false">
                            <div class="guide-icon bg-info-subtle text-info"><i class="bi bi-building-arrow-right"></i></div>
                            How do I transfer a learner to another school?
                        </button>
                    </h2>
                    <div id="q_transfer" class="accordion-collapse collapse" data-bs-parent="#accordionEnrollment">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>From the learner's profile, click the <strong>Transfer</strong> button. Pick the destination school, specify the school year, and enter a reason.</p>
                            <p>For transfers between schools, a <strong>Transfer Request</strong> is created. The coordinator or administrator of the receiving school must approve the request before the transfer is completed.</p>
                            <p class="mb-0">Pending requests show a badge count on the <strong>Transfer Requests</strong> page in the sidebar.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <!-- SECTION 3: REPORTS & ASSESSMENTS -->
        <div class="guide-section" id="section-reports">
            <div class="guide-section-title">
                <i class="bi bi-file-earmark-pdf text-info"></i> Reports &amp; Assessments
            </div>
            
            <div class="accordion" id="accordionReports">
                
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_case_study" aria-expanded="false">
                            <div class="guide-icon bg-secondary-subtle text-secondary"><i class="bi bi-file-earmark-pdf"></i></div>
                            How do I print a Case Study?
                        </button>
                    </h2>
                    <div id="q_case_study" class="accordion-collapse collapse" data-bs-parent="#accordionReports">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Open a learner's profile and click <strong>Print Case Study</strong>. This opens a clean A4 report with bio data, family background, placement history, remarks, and a signature block. Use your browser's print option (Ctrl+P or Cmd+P) to print or save as PDF.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_blank_form" aria-expanded="false">
                            <div class="guide-icon bg-light text-dark border"><i class="bi bi-file-text"></i></div>
                            Can I get blank forms for field work?
                        </button>
                    </h2>
                    <div id="q_blank_form" class="accordion-collapse collapse" data-bs-parent="#accordionReports">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Yes. Go to <strong>Blank Intake Forms</strong> in the sidebar. These are print-ready templates you can take to the field to collect learner data by hand before encoding it into the system.</p>
                        </div>
                    </div>
                </div>

                <?php if (user_is_coordinator($user_role)): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_assessments" aria-expanded="false">
                            <div class="guide-icon bg-primary-subtle text-primary"><i class="bi bi-clipboard-data"></i></div>
                            How do assessments (IEP / ILP / ITP) work?
                        </button>
                    </h2>
                    <div id="q_assessments" class="accordion-collapse collapse" data-bs-parent="#accordionReports">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>There are three core types of learner assessments/plans:</p>
                            <ul>
                                <li><strong>IEP</strong> (Individualized Education Program) — For Non-Graded learners. Covers annual goals, accommodations, and program planning.</li>
                                <li><strong>ILP</strong> (Individual Learning Plan) — For Graded learners. Focuses on academic support within the standard curriculum. Features a dynamic <strong>Record of Assessments</strong> table to log chronological age, test administrator details, and results.</li>
                                <li><strong>ITP</strong> (Individual Transition Plan) — Available for all placement types (both Graded and Non-Graded learners) to structure preparation for career, independent living, or tertiary transition.</li>
                            </ul>
                            <p class="mb-0">Assessments can be created, updated, and printed via the Assessments tab on the learner's profile page. All changes are saved chronologically with active audit logging.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_sf9" aria-expanded="false">
                            <div class="guide-icon bg-success-subtle text-success"><i class="bi bi-card-checklist"></i></div>
                            What is the Non Graded Progress Report (SF9)?
                        </button>
                    </h2>
                    <div id="q_sf9" class="accordion-collapse collapse" data-bs-parent="#accordionReports">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">The <strong>Non Graded Progress Report (SF9)</strong> is the official progress tracker for Non-Graded learners. It tracks developmental skill ratings, monthly attendance, general remarks, and parent/guardian feedback across four quarters. It is accessible from a learner's profile or the Assessments page, and renders standard DepEd Annex 2 progress report layouts for printing.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_docs" aria-expanded="false">
                            <div class="guide-icon bg-light text-dark border"><i class="bi bi-upload"></i></div>
                            Can I upload documents?
                        </button>
                    </h2>
                    <div id="q_docs" class="accordion-collapse collapse" data-bs-parent="#accordionReports">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Yes. From a learner's profile, scroll to the <strong>Documents</strong> section. You can upload IEPs, ILPs, medical records, referrals, and more. Uploaded files are stored securely and can be viewed, downloaded, or deleted as needed.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_consent" aria-expanded="false">
                             <div class="guide-icon text-white bg-primary"><i class="bi bi-file-earmark-check"></i></div>
                            How does Parental Consent work?
                        </button>
                    </h2>
                    <div id="q_consent" class="accordion-collapse collapse" data-bs-parent="#accordionReports">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">You can record a parent's consent for assessments, IEPs, or program enrollment from the learner's profile. Each entry stores the parent's name, date, and your notes. All consents are logged for audit and compliance.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- SECTION 4: SECURITY & SYSTEM ADMIN -->
        <div class="guide-section" id="section-security">
            <div class="guide-section-title">
                <i class="bi bi-shield-lock-fill text-danger"></i> Security &amp; Administration
            </div>
            
            <div class="accordion" id="accordionSecurity">
                
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_sec_roles" aria-expanded="false">
                            <div class="guide-icon bg-danger-subtle text-danger"><i class="bi bi-fingerprint"></i></div>
                            What can each user role do?
                        </button>
                    </h2>
                    <div id="q_sec_roles" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>The system uses four strictly defined roles with hierarchical permissions:</p>
                            <ul class="mb-0 list-unstyled">
                                <li class="mb-3">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="role-tag role-tag-superadmin">SUPERADMIN</span>
                                        <strong>Super Administrator</strong>
                                    </div>
                                    <span class="small text-muted">Developer/Demo Support Team Staff: Full developer access, database tools, audit trails, and user configurations.</span>
                                </li>
                                <li class="mb-3">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="role-tag role-tag-admin">ADMIN OFFICE</span>
                                        <strong>Admin Office</strong>
                                    </div>
                                    <span class="small text-muted">Division Admin: Full management of schools directory, system users, archived records (trash), and transaction logging.</span>
                                </li>
                                <li class="mb-3">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="role-tag role-tag-coordinator">SCHOOL_COORDINATOR</span>
                                        <strong>School Coordinator</strong>
                                    </div>
                                    <span class="small text-muted">School-scoped Coordinator: School-scoped access to register learners, edit profiles, process transfers/promotions, and manage student document assets.</span>
                                </li>
                                <li class="mb-0">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="role-tag role-tag-teacher">TEACHER</span>
                                        <strong>Teacher</strong>
                                    </div>
                                    <span class="small text-muted">Classroom Teacher: School-scoped access identical to School Coordinator. Can register, edit, transfer, promote, and manage assessments (IEP/ILP/Non Graded Progress Reports) for all learners enrolled in their assigned school. Registered learners are automatically mapped to the teacher via `learner_teachers` for tracking.</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_2fa" aria-expanded="false">
                            <div class="guide-icon bg-danger-subtle text-danger"><i class="bi bi-shield-lock"></i></div>
                            How do I set up 2FA?
                        </button>
                    </h2>
                    <div id="q_2fa" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">After logging in with your password, you will be asked for a 6-digit code from an authenticator app (like Google Authenticator or Authy). To set it up, click your profile dropdown, select <strong>Security Settings</strong>, scan the QR code, and enter the code to enable it.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_privacy" aria-expanded="false">
                            <div class="guide-icon bg-primary-subtle text-primary"><i class="bi bi-shield-check"></i></div>
                            Why do I need to accept the Data Privacy notice?
                        </button>
                    </h2>
                    <div id="q_privacy" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">The system follows the <strong>Data Privacy Act of 2012 (RA 10173)</strong>. On your first login, a notice explains how learner data is collected and used. You must check the consent box and click <strong>Acknowledge &amp; Continue</strong> to proceed.</p>
                        </div>
                    </div>
                </div>

                <?php if (user_is_admin($user_role)): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_audit" aria-expanded="false">
                            <div class="guide-icon bg-light text-dark border"><i class="bi bi-journal-text"></i></div>
                            How do I view the audit log?
                        </button>
                    </h2>
                    <div id="q_audit" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Every action — creating, updating, deleting, or printing — is recorded in the <strong>Transaction Logs</strong>. Go there to see who did what, when, and in which module.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (user_is_admin($user_role)): ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_school_profile" aria-expanded="false">
                            <div class="guide-icon bg-primary-subtle text-primary"><i class="bi bi-building-gear"></i></div>
                            How do I manage school records?
                        </button>
                    </h2>
                    <div id="q_school_profile" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p>Go to <strong>My School</strong> in the sidebar. Admin users can open <strong>Schools</strong> under Management for directory maintenance. You can:</p>
                            <ul class="mb-0">
                                <li>View each school's enrollment data.</li>
                                <li>Edit school name, address, district, and grade levels offered.</li>
                                <li>Assign and track school principals.</li>
                            </ul>
                            <p class="mt-2 mb-0">Schools with active learners enrolled <strong>cannot be deleted</strong>.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_user_mgmt" aria-expanded="false">
                            <div class="guide-icon bg-danger-subtle text-danger"><i class="bi bi-person-gear"></i></div>
                            How do I manage user accounts?
                        </button>
                    </h2>
                    <div id="q_user_mgmt" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Go to <strong>User Management</strong> in the sidebar to create new users. Each user needs a username, full name, role, and password. School-scoped roles (Teacher, School Coordinator) must be assigned to a specific school.</p>
                        </div>
                    </div>
                </div>

                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#q_trash" aria-expanded="false">
                            <div class="guide-icon bg-secondary-subtle text-secondary"><i class="bi bi-archive"></i></div>
                            How do I restore deleted records?
                        </button>
                    </h2>
                    <div id="q_trash" class="accordion-collapse collapse" data-bs-parent="#accordionSecurity">
                        <div class="accordion-body p-4 text-muted lh-relaxed">
                            <p class="mb-0">Go to <strong>Archived Records (Trash)</strong> in the sidebar. You will see all deleted learners and enrollments. Click <strong>Restore</strong> to bring them back with their full history.</p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Call to Action: Help Center -->
        <div class="mt-5 p-5 rounded-4 bg-white border text-center shadow-sm border-light">
            <div class="text-primary fs-3 mb-2"><i class="bi bi-chat-left-dots-fill"></i></div>
            <h5 class="fw-bold mb-2">Still need help?</h5>
            <p class="text-muted small mb-4">For login issues, 2FA recovery, or system errors, contact the SNED Administration Team.</p>
            <p class="mb-0 fw-bold small"><i class="bi bi-envelope-at me-2 text-primary"></i> <a href="mailto:demo@example.invalid" class="text-decoration-none">demo@example.invalid</a></p>
        </div>

    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const searchInput = document.getElementById('docsSearch');
            const accordionItems = document.querySelectorAll('.accordion-item');
            const sections = document.querySelectorAll('.guide-section');
            const noResults = document.getElementById('noResults');
            const categoryGrid = document.getElementById('categoryGrid');
            const userDeskCard = document.getElementById('userDeskCard');

            searchInput.addEventListener('input', function () {
                const query = searchInput.value.toLowerCase().trim();
                let totalVisible = 0;

                if (query === '') {
                    // Reset to normal state
                    accordionItems.forEach(item => {
                        item.style.display = '';
                        const collapseEl = item.querySelector('.accordion-collapse');
                        const btn = item.querySelector('.accordion-button');
                        if (collapseEl) {
                            collapseEl.classList.remove('show');
                            btn.classList.add('collapsed');
                            btn.setAttribute('aria-expanded', 'false');
                        }
                    });
                    sections.forEach(section => section.style.display = '');
                    categoryGrid.style.display = '';
                    if (userDeskCard) userDeskCard.style.display = '';
                    noResults.classList.add('d-none');
                    return;
                }

                // Hide category links and user action panel during active search to focus on results
                categoryGrid.style.display = 'none';
                if (userDeskCard) userDeskCard.style.display = 'none';

                sections.forEach(section => {
                    let sectionHasMatch = false;
                    const items = section.querySelectorAll('.accordion-item');

                    items.forEach(item => {
                        const headerText = item.querySelector('.accordion-button').textContent.toLowerCase();
                        const bodyText = item.querySelector('.accordion-body').textContent.toLowerCase();
                        const matches = headerText.includes(query) || bodyText.includes(query);

                        if (matches) {
                            item.style.display = '';
                            sectionHasMatch = true;
                            totalVisible++;

                            // Expand matching items
                            const collapseEl = item.querySelector('.accordion-collapse');
                            const btn = item.querySelector('.accordion-button');
                            if (collapseEl && !collapseEl.classList.contains('show')) {
                                collapseEl.classList.add('show');
                                btn.classList.remove('collapsed');
                                btn.setAttribute('aria-expanded', 'true');
                            }
                        } else {
                            item.style.display = 'none';
                            const collapseEl = item.querySelector('.accordion-collapse');
                            const btn = item.querySelector('.accordion-button');
                            if (collapseEl) {
                                collapseEl.classList.remove('show');
                                btn.classList.add('collapsed');
                                btn.setAttribute('aria-expanded', 'false');
                            }
                        }
                    });

                    if (sectionHasMatch) {
                        section.style.display = '';
                    } else {
                        section.style.display = 'none';
                    }
                });

                if (totalVisible === 0) {
                    noResults.classList.remove('d-none');
                } else {
                    noResults.classList.add('d-none');
                }
            });

            // Smooth scrolling to anchors
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const targetEl = document.getElementById(targetId);
                    if (targetEl) {
                        targetEl.scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                });
            });
        });
    </script>
</body>
</html>
