<?php
session_start();
include "config.php";

// Redirect if already logged in
if (isset($_SESSION["user_id"])) {
    header("Location: main.php");
    exit();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_validate($_POST["csrf_token"] ?? null)) {
        $error = "Invalid request token. Please refresh and try again.";
    }

    $username = $_POST["username"] ?? "";
    $password = $_POST["password"] ?? "";

    if (
        $error === "" &&
        isset($conn) &&
        !empty($username) &&
        !empty($password)
    ) {
        // Fetch user data, including 2FA fields
        $stmt = $conn->prepare(
            "SELECT id, full_name, username, password, role, school_id, google_2fa_secret, google_2fa_enabled FROM users WHERE username = ?",
        );
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $is_password_correct = false;
            $needs_rehash = false;

            if (password_verify($password, $row["password"])) {
                $is_password_correct = true;
            } elseif ($password === $row["password"]) {
                // Legacy plain-text check
                $is_password_correct = true;
                $needs_rehash = true;
            }

            if ($is_password_correct) {
                // Rehash if necessary
                if ($needs_rehash) {
                    $new_hash = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare(
                        "UPDATE users SET password = ? WHERE id = ?",
                    );
                    $update_stmt->bind_param("si", $new_hash, $row["id"]);
                    $update_stmt->execute();
                }

                if (TWO_FA_ENABLED && $row["google_2fa_enabled"]) {
                    // Redirect to 2FA verification
                    $_SESSION["2fa_user_id"] = $row["id"];
                    $_SESSION["2fa_username"] = $row["username"];
                    $_SESSION["2fa_role"] = $row["role"];
                    $_SESSION["2fa_full_name"] =
                        $row["full_name"] ?: $row["username"];
                    $_SESSION["2fa_school_id"] = $row["school_id"]
                        ? (int) $row["school_id"]
                        : null;

                    header("Location: 2fa_verify.php");
                    exit();
                }

                // Normal login successful
                $_SESSION["user_id"] = $row["id"];
                $_SESSION["full_name"] = $row["full_name"] ?: $row["username"];
                $_SESSION["username"] = $row["username"];
                $_SESSION["role"] = $row["role"];
                $_SESSION["school_id"] = $row["school_id"]
                    ? (int) $row["school_id"]
                    : null;

                log_transaction(
                    $conn,
                    $row["id"],
                    "SECURITY",
                    "LOGIN",
                    $row["id"],
                    "User logged in successfully",
                );

                header("Location: main.php");
                exit();
            } else {
                $error = "Invalid username or password.";
                log_transaction(
                    $conn,
                    $row["id"],
                    "SECURITY",
                    "LOGIN",
                    null,
                    "Failed login attempt for username: $username",
                );
            }
        } else {
            $error = "Invalid username or password.";
            log_transaction(
                $conn,
                0,
                "SECURITY",
                "LOGIN",
                null,
                "Failed login attempt for non-existent username: $username",
            );
        }
    } else {
        $error = "Error occurred while processing your request.";
    }
}

// Fetch active announcements for login page display
$announcements = [];
$res_ann = $conn->query(
    "SELECT title, content, type, created_at FROM announcements WHERE is_active = 1 ORDER BY FIELD(type, 'urgent', 'warning', 'info'), created_at DESC LIMIT 1",
);
if ($res_ann) {
    while ($row = $res_ann->fetch_assoc()) {
        $announcements[] = $row;
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
    <link rel="stylesheet" href="css/bootstrap.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="css/animate.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <title>Login | SNED Profiling System</title>
    <link rel="stylesheet" href="css/bootstrap-icons.min.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <link rel="stylesheet" href="tokens.css?v=<?= urlencode(SYSTEM_VERSION) ?>">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; }

        html, body {
            height: 100%;
            overflow-x: clip;
        }

        body {
            min-height: 100vh;
            min-height: 100svh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: url('img/valsped_center.webp') center / cover no-repeat fixed;
            color: var(--color-ink);
            padding: clamp(1rem, 4vw, 2rem);
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: rgba(27, 67, 50, 0.65);
            z-index: 0;
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .login-card {
            width: 100%;
            border: 0;
            border-radius: 1.25rem;
            background: #fff;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
        }

        .login-card-body {
            padding: clamp(1.5rem, 5vw, 2.25rem);
        }

        .login-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .brand-logo {
            width: 64px;
            height: 64px;
            object-fit: contain;
            border-radius: 50%;
            background: #fff;
            padding: 0.35rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .brand-title {
            margin: 0.75rem 0 0;
            font-size: 1.6rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            color: var(--sned-primary);
        }

        .brand-subtitle {
            margin: 0.3rem 0 0;
            font-size: 0.82rem;
            color: var(--color-muted-dark);
        }

        .notice-stack {
            display: grid;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .login-notice {
            margin: 0;
            border-radius: 0.75rem;
            font-size: 0.85rem;
        }

        .login-field-icon {
            left: 1rem;
            pointer-events: none;
            z-index: 2;
        }

        .login-input {
            padding-left: 3rem !important;
        }

        .form-control {
            min-height: 3rem;
            border-radius: 0.75rem;
            border-color: var(--color-rule);
            background: var(--color-paper-2);
            font-size: 0.95rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.2s ease !important;
        }

        .form-control:focus {
            background: #fff;
            transform: translateY(-1px);
        }

        .login-button {
            min-height: 3rem;
            border-radius: 0.75rem;
            font-weight: 700;
        }
        .login-button i {
            transition: transform 0.25s cubic-bezier(0.165, 0.84, 0.44, 1);
        }
        .login-button:hover i {
            transform: translateX(4px);
        }

        .position-relative:focus-within .login-field-icon {
            color: var(--sned-primary) !important;
        }
        .login-field-icon {
            transition: color 0.2s ease;
        }

        .login-footer {
            color: var(--color-muted-dark);
            font-size: 0.78rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-4px); }
            100% { transform: translateY(0px); }
        }
        .login-card {
            opacity: 0;
            animation: fadeInUp 0.6s cubic-bezier(0.165, 0.84, 0.44, 1) forwards;
        }
        .brand-logo {
            animation: float 4s ease-in-out infinite;
        }

        @media (max-width: 480px) {
            body {
                padding: 0.75rem;
            }
            .login-card {
                border-radius: 1rem;
            }
            .login-card-body {
                padding: 1.25rem;
            }
            .brand-logo {
                width: 52px;
                height: 52px;
            }
            .brand-title {
                font-size: 1.35rem;
            }
            .form-control,
            .login-button {
                min-height: 2.8rem;
                border-radius: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <?php if (!empty($announcements)): ?>
            <div class="notice-stack w-100 animate__animated animate__bounce" aria-label="System announcements">
                <?php foreach ($announcements as $ann):
                    $ann_styles = [
                        "info"    => ["alert-primary", "bi-info-circle-fill", "text-primary"],
                        "warning" => ["alert-warning", "bi-exclamation-triangle-fill", "text-warning"],
                        "urgent"  => ["alert-danger", "bi-exclamation-octagon-fill", "text-danger"],
                    ];
                    $as = $ann_styles[$ann["type"]] ?? $ann_styles["info"];
                ?>
                <div class="alert <?= $as[0] ?> login-notice border-0 p-3 d-flex align-items-start gap-2">
                    <div class="<?= $as[2] ?> mt-1"><i class="bi <?= $as[1] ?>"></i></div>
                    <div class="flex-grow-1">
                        <div class="fw-bold small"><?= htmlspecialchars($ann["title"]) ?></div>
                        <p class="mb-0 small text-muted"><?= nl2br(htmlspecialchars($ann["content"])) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="card login-card">
            <div class="card-body login-card-body">
                <div class="login-brand">
                    <img src="img/valenzuela_gov_logo.webp" alt="Valenzuela Logo" class="brand-logo">
                    <h1 class="brand-title">ValSNED</h1>
                    <p class="brand-subtitle">Learner Profiling System</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger rounded-3 d-flex align-items-start gap-2 mb-3" role="alert">
                        <i class="bi bi-exclamation-octagon-fill mt-1"></i>
                        <div><?= htmlspecialchars($error) ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php">
                    <?= csrf_input() ?>
                    <div class="mb-3">
                        <label for="usernameInput" class="form-label fw-semibold">Username</label>
                        <div class="position-relative">
                            <i class="bi bi-person position-absolute top-50 translate-middle-y text-muted login-field-icon"></i>
                            <input type="text" class="form-control login-input" name="username" id="usernameInput" autocomplete="username" required autofocus>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="passwordInput" class="form-label fw-semibold">Password</label>
                        <div class="position-relative">
                            <i class="bi bi-key position-absolute top-50 translate-middle-y text-muted login-field-icon"></i>
                            <input type="password" class="form-control login-input" name="password" id="passwordInput" autocomplete="current-password" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary login-button w-100 mt-2 d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-box-arrow-in-right"></i> Log in
                    </button>
                    <p class="text-center small text-muted mt-3 mb-0">Don't have an account? <b>Contact your administrator.</b></p>
                </form>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top login-footer">
                    <span>&copy; Demo Support Team 2026</span>
                    <span><i>VER. <?= htmlspecialchars(SYSTEM_VERSION) ?></i></span>
                </div>
            </div>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js?v=<?= urlencode(SYSTEM_VERSION) ?>"></script>
</body>
</html>
