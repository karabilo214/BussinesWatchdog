<?php

return [
    // Staging gate: browser checks run once in report-only and again with enforcement.
    'csp_report_only' => (bool) env('SECURITY_CSP_REPORT_ONLY', false),
];
