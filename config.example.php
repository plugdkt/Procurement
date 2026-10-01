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

// AI: read annual plan PDFs and suggest projects (Anthropic Messages API compatible gateway).
// Leave AI_API_KEY empty to hide the feature.
define('AI_BASE_URL', 'https://gen.ai.kku.ac.th/upacth/api/v1');
define('AI_API_KEY', '');
define('AI_MODEL', 'claude-sonnet-5.5');
