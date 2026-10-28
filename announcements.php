<?php
session_start();
include "config.php";
include "includes/helpers.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

$user_role = $_SESSION["role"] ?? "user";
$username = $_SESSION["username"] ?? "User";
$user_id = $_SESSION["user_id"] ?? 0;

if (!user_is_superadmin($user_role)) {
    header("Location: 403.php");
    exit();
}

$message = "";

function announcement_hide_other_active(mysqli $conn, int $active_id): void
{
    $stmt = $conn->prepare("UPDATE announcements SET is_active = 0 WHERE is_active = 1 AND id <> ?");
    if ($stmt) {
        $stmt->bind_param("i", $active_id);
        $stmt->execute();
        $stmt->close();
    }
}

function announcement_data_payload(array $announcement): string
{
    return htmlspecialchars(
        json_encode(
            $announcement,
            JSON_HEX_APOS |
                JSON_HEX_AMP |
                JSON_HEX_QUOT |
                JSON_HEX_TAG,
        ),
        ENT_QUOTES,
        "UTF-8",
    );
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_validate($_POST["csrf_token"] ?? null)) {
        die("CSRF Token validation failed.");
    }

    $action = $_POST["action"] ?? "";

    if ($action === "create") {
        $title = trim($_POST["title"] ?? "");
        $content = trim($_POST["content"] ?? "");
        $type = in_array($_POST["type"] ?? "", ["info", "warning", "urgent"])
            ? $_POST["type"]
            : "info";

        if ($title === "" || $content === "") {
            $message =
                '<div class="alert alert-danger shadow-sm rounded-4">Title and content are required.</div>';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO announcements (title, content, type, is_active, created_by) VALUES (?, ?, ?, 1, ?)",
            );
            $stmt->bind_param("sssi", $title, $content, $type, $user_id);
            if ($stmt->execute()) {
                $new_announcement_id = (int) $stmt->insert_id;
                announcement_hide_other_active($conn, $new_announcement_id);
                log_transaction(
                    $conn,
                    $user_id,
                    "create",
                    "announcements",
                    (string) $new_announcement_id,
                    "Created announcement: {$title}",
                );
                $message =
                    '<div class="alert alert-success shadow-sm rounded-4">Announcement published. Other active announcements were hidden.</div>';
            } else {
                $message =
                    '<div class="alert alert-danger shadow-sm rounded-4">Failed to create announcement.</div>';
            }
            $stmt->close();
        }
    } elseif ($action === "update") {
        $id = (int) ($_POST["id"] ?? 0);
        $title = trim($_POST["title"] ?? "");
        $content = trim($_POST["content"] ?? "");
        $type = in_array($_POST["type"] ?? "", ["info", "warning", "urgent"])
            ? $_POST["type"]
            : "info";
        $is_active = isset($_POST["is_active"]) ? 1 : 0;

        if ($id <= 0 || $title === "" || $content === "") {
            $message =
                '<div class="alert alert-danger shadow-sm rounded-4">Invalid data.</div>';
        } else {
            $stmt = $conn->prepare(
                "UPDATE announcements SET title = ?, content = ?, type = ?, is_active = ? WHERE id = ?",
            );
            $stmt->bind_param(
                "sssii",
                $title,
                $content,
                $type,
                $is_active,
                $id,
            );
            if ($stmt->execute()) {
                if ($is_active === 1) {
                    announcement_hide_other_active($conn, $id);
                }
                log_transaction(
                    $conn,
                    $user_id,
                    "update",
                    "announcements",
                    (string) $id,
                    "Updated announcement: {$title}",
                );
                $message = $is_active === 1
                    ? '<div class="alert alert-success shadow-sm rounded-4">Announcement updated. Other active announcements were hidden.</div>'
                    : '<div class="alert alert-success shadow-sm rounded-4">Announcement updated.</div>';
            } else {
                $message =
                    '<div class="alert alert-danger shadow-sm rounded-4">Failed to update.</div>';
            }
            $stmt->close();
        }
    } elseif ($action === "delete") {
        $id = (int) ($_POST["id"] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                log_transaction(
                    $conn,
                    $user_id,
                    "delete",
                    "announcements",
                    (string) $id,
                    "Deleted announcement #{$id}",
                );
                $message =
                    '<div class="alert alert-success shadow-sm rounded-4">Announcement removed.</div>';
            } else {
                $message =
                    '<div class="alert alert-danger shadow-sm rounded-4">Failed to delete.</div>';
            }
            $stmt->close();
        }
    }
}

// Keep legacy data consistent with the one-active-announcement rule.
$active_res = $conn->query("SELECT id FROM announcements WHERE is_active = 1 ORDER BY FIELD(type, 'urgent', 'warning', 'info'), created_at DESC LIMIT 1");
if ($active_res && $active_row = $active_res->fetch_assoc()) {
    announcement_hide_other_active($conn, (int) $active_row["id"]);
}

// ── Fetch all announcements ───────────────────────────────────
$announcements = [];
$res = $conn->query(
    "SELECT a.*, u.username AS author FROM announcements a LEFT JOIN users u ON a.created_by = u.id ORDER BY a.created_at DESC",
);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $announcements[] = $row;
    }
}

$announcement_type_meta = [
    "info" => [
        "label" => "Information",
        "short" => "Info",
        "icon" => "bi-info-circle-fill",
        "class" => "announcement-card--info",
        "badge" => "bg-primary-subtle text-primary",
        "helper" => "General reminders, notices, and updates.",
    ],
    "warning" => [
        "label" => "Warning",
        "short" => "Warning",
        "icon" => "bi-exclamation-triangle-fill",
        "class" => "announcement-card--warning",
        "badge" => "bg-warning-subtle text-warning-emphasis",
        "helper" => "Important changes that users should prepare for.",
    ],
    "urgent" => [
        "label" => "Urgent",
        "short" => "Urgent",
        "icon" => "bi-exclamation-octagon-fill",
        "class" => "announcement-card--urgent",
        "badge" => "bg-danger-subtle text-danger",
        "helper" => "Critical messages that should be seen first.",
    ],
];

$active_announcement = null;
$inactive_announcements = [];
foreach ($announcements as $announcement) {
    if (!empty($announcement["is_active"])) {
        $active_announcement ??= $announcement;
    } else {
        $inactive_announcements[] = $announcement;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(
                                                                SYSTEM_VERSION,
                                                            ) ?>">
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(
                                                                    SYSTEM_VERSION,
                                                                ) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="assets/css/sned-ui.css?v=<?= urlencode(
                                                                SYSTEM_VERSION,
                                                            ) ?>">
    <title>Announcements - SNED Profiling</title>

    <style>
        /* ── Active announcement panel ── */
        .announcement-active-panel {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: var(--space-lg);
            align-items: start;
            padding: var(--space-xl);
            margin-bottom: var(--space-lg);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            background: var(--color-paper);
            box-shadow: var(--shadow-sm);
        }

        .announcement-active-panel--empty {
            border-color: var(--color-rule-light);
            background: var(--color-paper);
        }

        .announcement-active-label {
            color: var(--color-primary);
            font-size: var(--text-xs);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: var(--space-xs);
        }

        .announcement-active-title {
            margin: 0;
            color: var(--color-ink);
            font-size: clamp(1.5rem, 2vw, 2rem);
            font-weight: 750;
            letter-spacing: -.02em;
            line-height: 1.15;
        }

        .announcement-active-content {
            margin: var(--space-md) 0 0;
            color: var(--color-muted-dark);
            white-space: pre-line;
            line-height: 1.6;
            max-width: 78ch;
        }

        .announcement-active-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-xs);
            align-items: center;
            margin-top: var(--space-md);
            color: var(--color-muted);
            font-size: var(--text-xs);
        }

        .announcement-active-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: var(--space-xs);
        }

        /* ── Section heading ── */
        .announcement-section-heading {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: var(--space-md);
            margin: var(--space-xl) 0 var(--space-md);
        }

        .announcement-section-heading h2 {
            margin: 0;
            color: var(--color-ink);
            font-size: var(--text-xl);
            font-weight: 750;
            letter-spacing: -.02em;
        }

        .announcement-section-heading p {
            margin: var(--space-2xs) 0 0;
            color: var(--color-muted);
            font-size: var(--text-sm);
        }

        /* ── Card grid & cards ── */
        .announcement-board {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-md);
        }

        .announcement-card {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            gap: var(--space-md);
            padding: var(--space-lg);
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            background: var(--color-paper);
            box-shadow: var(--shadow-sm);
            transition: transform var(--dur-short) var(--ease-out), box-shadow var(--dur-short) var(--ease-out), border-color var(--dur-short) var(--ease-out);
        }

        .announcement-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
            border-color: var(--color-rule);
        }

        .announcement-card__top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: var(--space-md);
        }

        .announcement-card__icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-md);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--color-primary-light);
            color: var(--color-primary);
            flex: 0 0 40px;
        }

        .announcement-card--warning .announcement-card__icon {
            background: var(--sned-warning-light);
            color: var(--sned-warning);
        }

        .announcement-card--urgent .announcement-card__icon {
            background: var(--sned-danger-light);
            color: var(--sned-danger);
        }

        .announcement-card__title {
            margin: 0;
            color: var(--color-ink);
            font-weight: 700;
            font-size: var(--text-lg);
            line-height: 1.25;
            text-transform: none;
        }

        .announcement-card__content {
            color: var(--color-muted-dark);
            margin: 0;
            white-space: pre-line;
            line-height: 1.55;
            font-size: var(--text-sm);
            text-transform: none;
        }

        .announcement-card__meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-xs);
            align-items: center;
            color: var(--color-muted);
            font-size: var(--text-xs);
        }

        .announcement-card__actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: var(--space-xs);
            padding-top: var(--space-sm);
            border-top: 1px solid var(--color-rule-light);
        }

        /* ── Composer (modals) ── */
        .announcement-composer-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(260px, 0.8fr);
            gap: var(--space-lg);
        }

        .announcement-compose-note {
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            background: var(--color-paper-2);
            padding: var(--space-md);
            color: var(--color-muted-dark);
            font-size: var(--text-sm);
        }

        .announcement-field-hint {
            display: flex;
            justify-content: space-between;
            gap: var(--space-sm);
            color: var(--color-muted);
            font-size: var(--text-xs);
            margin-top: var(--space-2xs);
        }

        .announcement-type-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: var(--space-xs);
        }

        .announcement-type-option {
            position: relative;
            display: block;
        }

        .announcement-type-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .announcement-type-card {
            display: block;
            height: 100%;
            cursor: pointer;
            padding: var(--space-sm);
            border: 1px solid var(--color-rule);
            border-radius: var(--radius-md);
            background: var(--color-paper);
            color: var(--color-ink);
            transition: border-color var(--dur-short) var(--ease-out), background var(--dur-short) var(--ease-out), box-shadow var(--dur-short) var(--ease-out);
        }

        .announcement-type-card:hover {
            border-color: var(--color-outline);
            background: var(--color-paper-2);
        }

        .announcement-type-card strong {
            display: block;
            font-size: var(--text-sm);
        }

        .announcement-type-card span {
            display: block;
            color: var(--color-muted);
            font-size: var(--text-xs);
            line-height: 1.3;
            margin-top: 3px;
        }

        .announcement-type-option input:checked+.announcement-type-card {
            border-color: var(--color-primary);
            background: var(--color-primary-light);
            box-shadow: 0 0 0 2px rgba(64, 145, 108, 0.16);
        }

        .announcement-type-option input:focus-visible+.announcement-type-card {
            outline: 2px solid var(--color-focus);
            outline-offset: 2px;
        }

        .announcement-form-control {
            text-transform: none !important;
        }

        .announcement-preview {
            border: 1px solid var(--color-rule-light);
            border-radius: var(--radius-lg);
            background: var(--color-paper);
            padding: var(--space-md);
            box-shadow: var(--shadow-xs);
        }

        .announcement-preview__label {
            font-size: var(--text-xs);
            color: var(--color-muted);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-bottom: var(--space-xs);
        }

        .announcement-preview__title {
            font-weight: 700;
            margin-bottom: var(--space-xs);
            color: var(--color-ink);
            text-transform: none;
        }

        .announcement-preview__body {
            color: var(--color-muted-dark);
            white-space: pre-line;
            font-size: var(--text-sm);
            line-height: 1.5;
            text-transform: none;
        }

        /* ── Row action buttons ── */
        .btn-row-action {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2xs);
            border: none;
            border-radius: var(--radius-sm);
            font-size: var(--text-xs);
            padding: 4px 8px;
            font-weight: 600;
            text-decoration: none;
            white-space: nowrap;
            background: transparent;
            transition: background var(--dur-short) var(--ease-out), color var(--dur-short) var(--ease-out);
        }

        .btn-row-action:hover {
            background: var(--color-paper-3);
        }

        .btn-row-action:focus-visible {
            outline: 2px solid var(--color-focus);
            outline-offset: 1px;
        }

        .btn-row-edit {
            color: var(--sned-primary);
        }

        .btn-row-archive {
            color: var(--sned-danger);
        }

        @media (max-width: 992px) {
            .announcement-active-panel {
                grid-template-columns: 1fr;
            }

            .announcement-active-actions {
                justify-content: flex-start;
            }

            .announcement-composer-grid {
                grid-template-columns: 1fr;
            }

            .announcement-type-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .announcement-card {
                transition: none;
            }

            .announcement-card:hover {
                transform: none;
            }
        }
    </style>
</head>

<body>
    <div class="d-flex vh-100 app-shell">
        <?php include "sidebar.php"; ?>
        <div class="flex-grow-1 d-flex flex-column overflow-auto app-main">
            <header class="app-topbar d-flex align-items-center justify-content-between sticky-top">
                <button class="btn btn-outline-secondary d-flex align-items-center gap-2" id="sidebarToggle" type="button">
                    <i class="bi bi-list"></i> Menu
                </button>
            </header>
            <main class="app-content">

                <div class="page-toolbar animate-fade-up">
                    <div>
                        <h1 class="page-title">Announcements</h1>
                        <p class="page-subtitle">Manage the single announcement shown on the dashboard and login page.</p>
                    </div>
                    <div class="toolbar-actions">
                        <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" type="button" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
                            <i class="bi bi-plus-circle-fill me-1"></i> New Announcement
                        </button>
                    </div>
                </div>

                <?= $message ?>

                <?php if ($active_announcement): ?>
                    <?php
                    $active_meta = $announcement_type_meta[$active_announcement["type"]] ?? $announcement_type_meta["info"];
                    $active_encoded = announcement_data_payload($active_announcement);
                    ?>
                    <section class="announcement-active-panel animate-fade-up stagger-1" aria-label="Currently active announcement">
                        <div>
                            <div class="announcement-active-label"><i class="bi bi-broadcast-pin me-1"></i>Currently active</div>

                            <!-- Design Toggle Bug Fix Po; Alex -->

                            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                                <span class="badge rounded-pill <?= htmlspecialchars($active_meta["badge"]) ?>"><?= htmlspecialchars($active_meta["short"]) ?></span>
                                <span class="badge rounded-pill bg-success-subtle text-success">Visible on login and dashboard</span>
                            </div>

                            <h2 class="announcement-active-title"><?= htmlspecialchars($active_announcement["title"]) ?></h2>
                            <p class="announcement-active-content"><?= htmlspecialchars($active_announcement["content"]) ?></p>
                            <div class="announcement-active-meta">
                                <span><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($active_announcement["author"] ?? "System") ?></span>
                                <span><i class="bi bi-clock me-1"></i><?= date("M d, Y g:i A", strtotime($active_announcement["created_at"])) ?></span>
                                <span><i class="bi bi-check2-circle me-1"></i>Only active announcement</span>
                            </div>
                        </div>
                        <div class="announcement-active-actions">
                            <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" data-announcement="<?= $active_encoded ?>" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal">
                                <i class="bi bi-pencil-square me-1"></i> Edit Active
                            </button>
                        </div>
                    </section>
                <?php else: ?>
                    <section class="announcement-active-panel announcement-active-panel--empty animate-fade-up stagger-1" aria-label="Currently active announcement">
                        <div>
                            <div class="announcement-active-label text-secondary"><i class="bi bi-broadcast me-1"></i>No active announcement</div>
                            <h2 class="announcement-active-title">Nothing is currently visible to users.</h2>
                            <p class="announcement-active-content">Publish a new announcement or edit an existing one and turn on visibility to show it on the login page and dashboard.</p>
                        </div>
                        <div class="announcement-active-actions">
                            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" type="button" data-bs-toggle="modal" data-bs-target="#addAnnouncementModal">
                                <i class="bi bi-plus-circle-fill me-1"></i> New Announcement
                            </button>
                        </div>
                    </section>
                <?php endif; ?>

                <div class="announcement-section-heading animate-fade-up stagger-2">
                    <div>
                        <h2>Announcement Library</h2>
                        <p>Inactive announcements are kept here for reuse or reference.</p>
                    </div>
                    <span class="badge rounded-pill bg-secondary-subtle text-secondary"><?= number_format(count($inactive_announcements)) ?> hidden</span>
                </div>

                <?php if (empty($announcements)): ?>
                    <div class="section-panel text-center py-5 text-muted animate-fade-up stagger-3">
                        <i class="bi bi-megaphone display-1 mb-3 d-block" style="color:var(--color-muted)"></i>
                        <h5 class="fw-bold" style="color:var(--color-ink)">No announcements yet</h5>
                        <p>Click <strong>New Announcement</strong> to publish one.</p>
                    </div>
                <?php elseif (empty($inactive_announcements)): ?>
                    <div class="section-panel text-center py-5 text-muted animate-fade-up stagger-3">
                        <i class="bi bi-archive display-1 mb-3 d-block" style="color:var(--color-muted)"></i>
                        <h5 class="fw-bold" style="color:var(--color-ink)">No hidden announcements</h5>
                        <p>The active announcement above is the only announcement saved.</p>
                    </div>
                <?php else: ?>
                    <div class="announcement-board animate-fade-up stagger-3">
                        <?php foreach ($inactive_announcements as $a): ?>
                            <?php
                            $meta = $announcement_type_meta[$a["type"]] ?? $announcement_type_meta["info"];
                            $encoded_announcement = announcement_data_payload($a);
                            ?>
                            <article class="announcement-card <?= htmlspecialchars($meta["class"]) ?>">
                                <div class="announcement-card__top">
                                    <div class="d-flex align-items-start gap-3 min-width-0">
                                        <div class="announcement-card__icon"><i class="bi <?= htmlspecialchars($meta["icon"]) ?>"></i></div>
                                        <div class="min-width-0">
                                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                                <span class="badge rounded-pill <?= htmlspecialchars($meta["badge"]) ?>"><?= htmlspecialchars($meta["short"]) ?></span>
                                                <span class="badge rounded-pill bg-secondary-subtle text-secondary">Hidden</span>
                                            </div>
                                            <h2 class="announcement-card__title"><?= htmlspecialchars($a["title"]) ?></h2>
                                        </div>
                                    </div>
                                </div>
                                <p class="announcement-card__content"><?= htmlspecialchars($a["content"]) ?></p>
                                <div class="announcement-card__meta">
                                    <span><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($a["author"] ?? "System") ?></span>
                                    <span><i class="bi bi-clock me-1"></i><?= date("M d, Y g:i A", strtotime($a["created_at"])) ?></span>
                                </div>
                                <div class="announcement-card__actions">
                                    <button type="button" class="btn-row-action btn-row-edit" data-announcement="<?= $encoded_announcement ?>" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal">
                                        <i class="bi bi-pencil-square"></i> Edit
                                    </button>
                                    <button type="button" class="btn-row-action btn-row-archive" data-id="<?= (int) $a["id"] ?>" data-title="<?= htmlspecialchars($a["title"], ENT_QUOTES, "UTF-8") ?>" onclick="confirmDeleteFromButton(this)">
                                        <i class="bi bi-trash3"></i> Delete
                                    </button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Add Modal -->
    <div class="modal fade" id="addAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-megaphone-fill text-primary me-1"></i> New Announcement</h5>
                        <div class="text-muted small">Publishing this announcement will hide any currently visible announcement.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body px-4 pb-4">
                        <div class="announcement-composer-grid">
                            <div>
                                <div class="mb-3">
                                    <label for="addTitle" class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" id="addTitle" class="form-control announcement-form-control" required maxlength="255" autocapitalize="sentences" spellcheck="true" placeholder="Example: System maintenance on Friday">
                                    <div class="announcement-field-hint"><span>Mixed case, lowercase, acronyms, and punctuation are allowed.</span><span><span data-count-for="addTitle">0</span>/255</span></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Announcement type</label>
                                    <div class="announcement-type-grid">
                                        <?php foreach (
                                            $announcement_type_meta
                                            as $type_key => $type_meta
                                        ): ?>
                                            <label class="announcement-type-option">
                                                <input type="radio" name="type" value="<?= htmlspecialchars(
                                                                                            $type_key,
                                                                                        ) ?>" <?= $type_key === "info"
                                                        ? "checked"
                                                        : "" ?>>
                                                <span class="announcement-type-card">
                                                    <strong><i class="bi <?= htmlspecialchars(
                                                                                $type_meta["icon"],
                                                                            ) ?> me-1"></i><?= htmlspecialchars(
                                                                    $type_meta["label"],
                                                                ) ?></strong>
                                                    <span><?= htmlspecialchars(
                                                                $type_meta["helper"],
                                                            ) ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-0">
                                    <label for="addContent" class="form-label fw-bold">Content <span class="text-danger">*</span></label>
                                    <textarea name="content" id="addContent" class="form-control announcement-form-control" rows="7" required autocapitalize="sentences" spellcheck="true" placeholder="Write the announcement details. Line breaks will be preserved on the login page and dashboard."></textarea>
                                    <div class="announcement-field-hint"><span>Tip: keep it short and action-oriented.</span><span><span data-count-for="addContent">0</span> characters</span></div>
                                </div>
                            </div>

                            <aside>
                                <div class="announcement-compose-note mb-3">
                                    <div class="fw-bold text-dark mb-1"><i class="bi bi-stars text-primary me-1"></i> Writing tips</div>
                                    <ul class="mb-0 ps-3">
                                        <li>Use sentence case instead of ALL CAPS unless an acronym needs it.</li>
                                        <li>Put dates, times, and affected users near the start.</li>
                                        <li>Only one announcement is visible across the system.</li>
                                    </ul>
                                </div>
                                <div class="announcement-preview">
                                    <div class="announcement-preview__label">Live preview</div>
                                    <div class="announcement-preview__title" data-preview-title="addTitle">System maintenance on Friday</div>
                                    <div class="announcement-preview__body" data-preview-content="addContent">Write the announcement details. Line breaks will be preserved.</div>
                                </div>
                            </aside>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="bi bi-send-fill me-1"></i> Publish Announcement</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editAnnouncementModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header border-0 pb-0 pt-4 px-4">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-pencil-square text-primary me-1"></i> Edit Announcement</h5>
                        <div class="text-muted small">Update the wording, type, or visibility. Making this visible hides any other active announcement.</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="editId">
                    <div class="modal-body px-4 pb-4">
                        <div class="announcement-composer-grid">
                            <div>
                                <div class="mb-3">
                                    <label for="editTitle" class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                                    <input type="text" name="title" id="editTitle" class="form-control announcement-form-control" required maxlength="255" autocapitalize="sentences" spellcheck="true">
                                    <div class="announcement-field-hint"><span>Use the exact capitalization users should see.</span><span><span data-count-for="editTitle">0</span>/255</span></div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-bold">Announcement type</label>
                                    <div class="announcement-type-grid">
                                        <?php foreach (
                                            $announcement_type_meta
                                            as $type_key => $type_meta
                                        ): ?>
                                            <label class="announcement-type-option">
                                                <input type="radio" name="type" value="<?= htmlspecialchars(
                                                                                            $type_key,
                                                                                        ) ?>" id="editType<?= htmlspecialchars(
                                                                    ucfirst($type_key),
                                                                ) ?>">
                                                <span class="announcement-type-card">
                                                    <strong><i class="bi <?= htmlspecialchars(
                                                                                $type_meta["icon"],
                                                                            ) ?> me-1"></i><?= htmlspecialchars(
                                                                    $type_meta["label"],
                                                                ) ?></strong>
                                                    <span><?= htmlspecialchars(
                                                                $type_meta["helper"],
                                                            ) ?></span>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="editContent" class="form-label fw-bold">Content <span class="text-danger">*</span></label>
                                    <textarea name="content" maxLength="300" id="editContent" class="form-control announcement-form-control" rows="7" required autocapitalize="sentences" spellcheck="true"></textarea>
                                    <div class="announcement-field-hint"><span>Line breaks are preserved in user-facing announcement cards.</span><span><span data-count-for="editContent">0</span> characters</span></div>
                                </div>

                                <div class="form-check form-switch py-3 rounded-4 border bg-light">
                                    <input class="form-check-input" style="padding-left: 1rem;" type="checkbox" name="is_active" id="editActive" value="1">
                                    <label class="form-check-label fw-bold" for="editActive">Visible to users</label>
                                    <div class="text-muted small">Turning this on will hide any other active announcement.</div>
                                </div>
                            </div>

                            <aside>
                                <div class="announcement-compose-note mb-3">
                                    <div class="fw-bold text-dark mb-1"><i class="bi bi-eye text-primary me-1"></i> Before saving</div>
                                    <p class="mb-0">Check the preview for readability. Use normal sentences unless an emergency message truly needs stronger emphasis.</p>
                                </div>
                                <div class="announcement-preview">
                                    <div class="announcement-preview__label">Live preview</div>
                                    <div class="announcement-preview__title" data-preview-title="editTitle">Announcement title</div>
                                    <div class="announcement-preview__body" data-preview-content="editContent">Announcement content</div>
                                </div>
                            </aside>
                        </div>
                    </div>
                    <div class="modal-footer border-0 pt-0 px-4 pb-4">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="confirmDeleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 text-center">
                <form method="POST">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="deleteId">
                    <div class="modal-body p-4">
                        <div class="display-2 text-danger mb-3"><i class="bi bi-trash3-fill"></i></div>
                        <h3 class="fw-bold">Delete Announcement?</h3>
                        <p class="text-muted mb-4">This will permanently remove <strong id="deleteTitle"></strong>.</p>
                        <button type="button" class="btn btn-light w-100 rounded-pill py-2 fw-bold" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger w-100 rounded-pill py-2 fw-bold shadow mt-2">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(
                                                    SYSTEM_VERSION,
                                                ) ?>"></script>
    <script>
        const shell = document.querySelector('.app-shell');
        const toggle = document.getElementById('sidebarToggle');
        if (shell && toggle) {
            toggle.addEventListener('click', () => shell.classList.toggle('sidebar-hidden'));
        }

        function updateFieldCounter(field) {
            document.querySelectorAll(`[data-count-for="${field.id}"]`).forEach(counter => {
                counter.innerText = field.value.length;
            });
        }

        function updatePreview(field) {
            const fallback = field.id.toLowerCase().includes('title') ?
                'Announcement title' :
                'Announcement content';
            const value = field.value.trim() || fallback;
            document.querySelectorAll(`[data-preview-title="${field.id}"], [data-preview-content="${field.id}"]`).forEach(preview => {
                preview.innerText = value;
            });
        }

        function syncAnnouncementField(field) {
            updateFieldCounter(field);
            updatePreview(field);
        }

        document.querySelectorAll('#addTitle, #addContent, #editTitle, #editContent').forEach(field => {
            syncAnnouncementField(field);
            field.addEventListener('input', () => syncAnnouncementField(field));
        });

        function setEditType(type) {
            const fallback = document.querySelector('input[name="type"][value="info"]');
            const selected = document.querySelector(`#editAnnouncementModal input[name="type"][value="${CSS.escape(type || 'info')}"]`) || fallback;
            if (selected) selected.checked = true;
        }

        function populateEditModal(a) {
            document.getElementById('editId').value = a.id || '';
            document.getElementById('editTitle').value = a.title || '';
            document.getElementById('editContent').value = a.content || '';
            document.getElementById('editActive').checked = parseInt(a.is_active || 0, 10) === 1;
            setEditType(a.type || 'info');
            ['editTitle', 'editContent'].forEach(id => syncAnnouncementField(document.getElementById(id)));
        }

        document.getElementById('editAnnouncementModal').addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            if (button) {
                try {
                    populateEditModal(JSON.parse(button.dataset.announcement || '{}'));
                } catch (error) {
                    console.error('Unable to read announcement data', error);
                }
            }
        });

        function confirmDelete(id, title) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteTitle').innerText = title;
            new bootstrap.Modal(document.getElementById('confirmDeleteModal')).show();
        }

        function confirmDeleteFromButton(button) {
            confirmDelete(button.dataset.id || '', button.dataset.title || 'this announcement');
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('add')) {
            new bootstrap.Modal(document.getElementById('addAnnouncementModal')).show();
        }
    </script>
</body>

</html>