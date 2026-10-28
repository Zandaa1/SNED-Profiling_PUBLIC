<?php
if (!function_exists('print_a4_styles')) {
    function print_a4_styles(string $orientation = 'portrait'): void
    {
        $orientation = $orientation === 'landscape' ? 'landscape' : 'portrait';
        ?>
        <style>
            @page { size: A4 <?= $orientation ?>; margin: 0; }
            :root {
                --sned-a4-portrait-width: 210mm;
                --sned-a4-portrait-height: 297mm;
                --sned-a4-landscape-width: 297mm;
                --sned-a4-landscape-height: 210mm;
            }
            .print-toolbar,
            .print-controls {
                width: 100%;
                text-align: center;
                margin: 14px auto;
                padding: 0 12px;
                box-sizing: border-box;
            }
            .print-toolbar .a4-help,
            .print-controls .a4-help {
                display: inline-flex;
                align-items: center;
                gap: .45rem;
                margin-bottom: .75rem;
                padding: .55rem 1rem;
                border-radius: 999px;
                background: #e0f2fe;
                color: #075985;
                border: 1px solid #bae6fd;
                font-size: .92rem;
                box-shadow: 0 2px 8px rgba(15, 23, 42, .12);
            }
            .print-toolbar .a4-help-badge,
            .print-controls .a4-help-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 1.25rem;
                height: 1.25rem;
                border-radius: 50%;
                background: #0ea5e9;
                color: #fff;
                font-weight: 800;
                line-height: 1;
            }
            .print-toolbar .btn,
            .print-controls .btn {
                min-width: 220px;
            }
            @media print {
                @page { size: A4 <?= $orientation ?>; margin: 0; }
                html, body {
                    margin: 0 !important;
                    padding: 0 !important;
                    background: #fff !important;
                    width: 100% !important;
                }
                .print-toolbar,
                .print-controls,
                .no-print {
                    display: none !important;
                }
                .page,
                .sheet,
                .spread {
                    box-shadow: none !important;
                    border-color: transparent !important;
                    margin: 0 auto !important;
                    box-sizing: border-box !important;
                    background: #fff !important;
                    break-after: page;
                    page-break-after: always;
                }
                <?php if ($orientation === 'landscape'): ?>
                .spread,
                .a4-landscape,
                .sheet.a4-landscape {
                    width: var(--sned-a4-landscape-width) !important;
                    max-width: var(--sned-a4-landscape-width) !important;
                }
                .spread,
                .a4-fixed {
                    height: var(--sned-a4-landscape-height) !important;
                    max-height: var(--sned-a4-landscape-height) !important;
                    overflow: hidden !important;
                }
                .sheet.a4-landscape,
                .a4-flow {
                    min-height: var(--sned-a4-landscape-height) !important;
                    height: auto !important;
                    max-height: none !important;
                    overflow: visible !important;
                }
                <?php else: ?>
                .page,
                .sheet,
                .a4-portrait {
                    width: var(--sned-a4-portrait-width) !important;
                    max-width: var(--sned-a4-portrait-width) !important;
                    min-height: var(--sned-a4-portrait-height) !important;
                }
                .a4-fixed {
                    height: var(--sned-a4-portrait-height) !important;
                    max-height: var(--sned-a4-portrait-height) !important;
                    overflow: hidden !important;
                }
                .a4-flow {
                    height: auto !important;
                    max-height: none !important;
                    overflow: visible !important;
                }
                <?php endif; ?>
                .page:last-child,
                .sheet:last-child,
                .spread:last-child {
                    break-after: auto;
                    page-break-after: auto;
                }
                .a4-flow {
                    -webkit-box-decoration-break: clone;
                    box-decoration-break: clone;
                }
                table {
                    max-width: 100% !important;
                    page-break-inside: auto;
                    break-inside: auto;
                }
                thead { display: table-header-group; }
                tfoot { display: table-footer-group; }
                tr, img {
                    page-break-inside: avoid;
                    break-inside: avoid;
                }
                img { max-width: 100%; }
            }
        </style>
        <?php
    }
}

if (!function_exists('print_a4_toolbar')) {
    function print_a4_toolbar(string $buttonLabel = 'Print Document'): void
    {
        ?>
        <div class="print-toolbar no-print">
            <div class="a4-help" title="Use Chrome and A4 paper size in the print dialog.">
                <span class="a4-help-badge">?</span>
                <span>For best results, use <strong>Google Chrome</strong> or <strong>Microsoft Edge</strong>. In the print options, set paper size to <strong>A4</strong> and scale to <strong>Default/100%</strong>; margins are handled automatically.</span>
            </div>
            <div>
                <button class="btn btn-primary px-5 rounded-pill shadow fw-bold" onclick="window.print();">
                    <i class="bi bi-printer me-1"></i> <?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </div>
        <?php
    }
}
