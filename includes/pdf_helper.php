<?php
use Dompdf\Dompdf;
use Dompdf\Options;

if (!function_exists("sned_pdf_open_basedir_allows")) {
    function sned_pdf_open_basedir_allows(string $path): bool
    {
        $open_basedir = ini_get("open_basedir");
        if (!$open_basedir) {
            return true;
        }

        $norm_path = str_replace("\\", "/", $path);
        $norm_path = rtrim($norm_path, "/");
        $allowed_paths = array_map(function ($p) {
            return str_replace("\\", "/", rtrim(trim($p), "/\\"));
        }, explode(PATH_SEPARATOR, $open_basedir));

        foreach ($allowed_paths as $allowed) {
            if ($allowed === "") {
                continue;
            }
            if (
                strcasecmp($norm_path, $allowed) === 0 ||
                strncasecmp(
                    $norm_path,
                    $allowed . "/",
                    strlen($allowed) + 1,
                ) === 0
            ) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists("sned_pdf_is_executable_file")) {
    function sned_pdf_is_executable_file(string $path): bool
    {
        if (!sned_pdf_open_basedir_allows($path) || !@is_file($path)) {
            return false;
        }

        if (PHP_OS_FAMILY === "Windows") {
            return (bool) preg_match('/\\.(exe|cmd|bat)$/i', $path);
        }

        return @is_executable($path);
    }
}

if (!function_exists("sned_pdf_chrome_candidates")) {
    function sned_pdf_chrome_candidates(): array
    {
        $project_root = dirname(__DIR__);

        $env_candidates = array_filter([
            defined("SNED_CHROME_PATH") ? SNED_CHROME_PATH : "",
            getenv("CHROME_BIN") ?: "",
            getenv("CHROME_PATH") ?: "",
            getenv("EDGE_BIN") ?: "",
            getenv("MSEDGE_BIN") ?: "",
        ]);

        $windows_candidates = [];
        if (PHP_OS_FAMILY === "Windows") {
            $program_files = array_filter([
                getenv("ProgramFiles") ?: "",
                getenv("ProgramFiles(x86)") ?: "",
                getenv("LOCALAPPDATA") ?: "",
                getenv("SystemDrive")
                    ? rtrim(getenv("SystemDrive"), "\\/") . "\\Program Files"
                    : "",
                getenv("SystemDrive")
                    ? rtrim(getenv("SystemDrive"), "\\/") .
                        "\\Program Files (x86)"
                    : "",
            ]);

            foreach ($program_files as $base) {
                $windows_candidates[] =
                    rtrim($base, "\\/") .
                    "\\Google\\Chrome\\Application\\chrome.exe";
                $windows_candidates[] =
                    rtrim($base, "\\/") .
                    "\\Microsoft\\Edge\\Application\\msedge.exe";
            }
        }

        $candidates = array_merge($env_candidates, $windows_candidates, [
            "/usr/bin/google-chrome",
            "/usr/bin/google-chrome-stable",
            "/usr/bin/chromium",
            "/usr/bin/chromium-browser",
            "/snap/bin/chromium",
            "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome",
            "/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge",
        ]);

        $unique = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === "") {
                continue;
            }

            $is_absolute = false;
            if (DIRECTORY_SEPARATOR === '/') {
                $is_absolute = str_starts_with($candidate, '/');
            } else {
                $is_absolute = preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate) || str_starts_with($candidate, '\\\\');
            }
            if (!$is_absolute) {
                $candidate = $project_root . DIRECTORY_SEPARATOR . $candidate;
            }

            $key =
                PHP_OS_FAMILY === "Windows"
                    ? strtolower(str_replace("/", "\\", $candidate))
                    : $candidate;
            $unique[$key] = $candidate;
        }

        return array_values(
            array_filter($unique, function ($candidate) {
                return !sned_pdf_open_basedir_allows($candidate) ||
                    sned_pdf_is_executable_file($candidate);
            }),
        );
    }
}

if (!function_exists("sned_pdf_file_uri")) {
    function sned_pdf_file_uri(string $path): string
    {
        $normalized = str_replace("\\", "/", $path);
        if (preg_match("/^[A-Za-z]:\//", $normalized)) {
            return "file:///" . $normalized;
        }
        return "file://" . $normalized;
    }
}

if (!function_exists("sned_pdf_shell_exec_available")) {
    function sned_pdf_shell_exec_available(): bool
    {
        if (!function_exists("shell_exec")) {
            return false;
        }

        $disabled = array_filter(
            array_map(
                "trim",
                explode(",", (string) ini_get("disable_functions")),
            ),
        );
        foreach ($disabled as $function) {
            if (strcasecmp($function, "shell_exec") === 0) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists("stream_pdf_from_html")) {
    function stream_pdf_from_html(
        string $html,
        string $filename,
        string $orientation = "portrait",
        bool $download = false,
    ): void {
        $project_root = dirname(__DIR__);
        $orientation =
            strtolower($orientation) === "landscape" ? "landscape" : "portrait";
        $safe_filename = trim(
            preg_replace("/[^A-Za-z0-9._-]/", "_", $filename),
            "_",
        );
        if ($safe_filename === "") {
            $safe_filename = "document.pdf";
        }
        if (!str_ends_with(strtolower($safe_filename), ".pdf")) {
            $safe_filename .= ".pdf";
        }

        if (
            !class_exists("Dompdf\Dompdf") &&
            file_exists($project_root . "/vendor/autoload.php")
        ) {
            require_once $project_root . "/vendor/autoload.php";
        }

        $html = preg_replace(
            '/(href|src)="([^"]+)\?v=[^"]+"/',
            '$1="$2"',
            $html,
        );
        $html = str_replace(
            'href="css/',
            'href="' . $project_root . "/css/",
            $html,
        );
        $html = str_replace(
            "href='css/",
            "href='" . $project_root . "/css/",
            $html,
        );
        $html = str_replace(
            'href="tokens.css',
            'href="' . $project_root . "/tokens.css",
            $html,
        );
        $html = str_replace(
            "href='tokens.css",
            "href='" . $project_root . "/tokens.css",
            $html,
        );
        $html = str_replace(
            'src="uploads/',
            'src="' . $project_root . "/uploads/",
            $html,
        );
        $html = str_replace(
            "src='uploads/",
            "src='" . $project_root . "/uploads/",
            $html,
        );
        $html = str_replace(
            'src="img/',
            'src="' . $project_root . "/img/",
            $html,
        );
        $html = str_replace(
            "src='img/",
            "src='" . $project_root . "/img/",
            $html,
        );

        $page_size =
            $orientation === "landscape" ? "A4 landscape" : "A4 portrait";
        $html .=
            "<style>@page{size:" .
            $page_size .
            ";margin:8mm;} .no-pdf,.no-print,button,.btn{display:none!important;}</style>";

        $pdf_content = "";
        $temp_base =
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) .
            DIRECTORY_SEPARATOR .
            "sned_pdf_" .
            bin2hex(random_bytes(8));
        $temp_html = $temp_base . ".html";
        $temp_pdf = $temp_base . ".pdf";

        if (
            file_put_contents($temp_html, $html) !== false &&
            sned_pdf_shell_exec_available()
        ) {
            foreach (sned_pdf_chrome_candidates() as $chrome_path) {
                $cmd =
                    escapeshellarg($chrome_path) .
                    " --headless --disable-gpu --no-sandbox --disable-dev-shm-usage --no-pdf-header-footer --print-to-pdf=" .
                    escapeshellarg($temp_pdf) .
                    " " .
                    escapeshellarg(sned_pdf_file_uri($temp_html));
                @shell_exec($cmd . " 2>&1");
                if (file_exists($temp_pdf) && filesize($temp_pdf) > 0) {
                    $pdf_content = (string) file_get_contents($temp_pdf);
                    break;
                }
            }
        }

        if ($pdf_content === "") {
            $options = new Options();
            $options->set("isHtml5ParserEnabled", true);
            $options->set("isRemoteEnabled", false);
            $options->set("chroot", $project_root);
            $options->set("defaultMediaType", "print");

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper("A4", $orientation);
            $dompdf->render();
            $pdf_content = (string) $dompdf->output();
        }

        if (file_exists($temp_html)) {
            @unlink($temp_html);
        }
        if (file_exists($temp_pdf)) {
            @unlink($temp_pdf);
        }

        if ($pdf_content === "") {
            http_response_code(500);
            exit("Unable to generate PDF.");
        }

        if (!headers_sent()) {
            header("Content-Type: application/pdf");
            header("Content-Length: " . strlen($pdf_content));
            header(
                "Content-Disposition: " .
                    ($download ? "attachment" : "inline") .
                    '; filename="' .
                    $safe_filename .
                    '"',
            );
            header("Cache-Control: private, max-age=0, must-revalidate");
            header("Pragma: public");
        }
        echo $pdf_content;
        exit();
    }
}
