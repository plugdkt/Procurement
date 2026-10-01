<?php
// config.example.php - Example database configuration
// Copy this file to config.php and update with your actual database credentials.

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'procurement_db');

// Password for the first 'admin' (superadmin) account, created only when the users table is empty.
// Change it right after the first login.
define('ADMIN_INITIAL_PASSWORD', 'change_me_please');

// AI: read annual plan PDFs and suggest projects. Leave both keys empty to hide the feature.
// Providers are tried in order: Google Gemini API first, then the KKU gateway. Within each provider the models
// are tried in order, so when one model reaches its daily quota (or is unavailable) the next one is used.

// 1) Google Gemini API (AI Studio key) - accepts whole PDFs up to ~20 MB, including scanned plans
define('GEMINI_API_KEY', '');
define('GEMINI_MODELS', ['gemini-3.5-flash-lite', 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.1-pro-preview']);

// 2) KKU Anthropic-compatible gateway (fallback) - ~1 MB request limit; large PDFs are sent as page images
define('GATEWAY_BASE_URL', 'https://gen.ai.kku.ac.th/upacth/api/v1');
define('GATEWAY_API_KEY', '');
define('GATEWAY_MODELS', ['claude-sonnet-5.5', 'claude-sonnet-5', 'claude-sonnet-4.6', 'gemini-3.1-pro-preview', 'gpt-6-sol-pro', 'gemini-3.8-flash', 'gpt-5.4']);

// Optional: CA bundle path if PHP has no curl.cainfo set (e.g. Windows/IIS). TLS verification is never disabled.
// define('AI_CA_BUNDLE', 'C:\php\extras\ssl\cacert.pem');
