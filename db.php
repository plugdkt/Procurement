<?php
// db.php - Database connection and helper functions for Procurement Announcement System
// Model: plans (1-to-many) -> projects (1-to-many) -> announcements (steps 2-5)

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'procurement_db');

function db_connect() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    
    try {
        // Connect to MySQL server first to create database if it doesn't exist
        $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        $temp_pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Connect to the actual database
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, $options);
        
        // Initialize tables if they don't exist
        db_initialize($pdo);
        
        return $pdo;
    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}

function db_initialize($pdo) {
    // 1. Create users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        name VARCHAR(100) NOT NULL,
        role VARCHAR(50) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add role column to existing users if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'admin'");
    } catch (PDOException $e) {
        // Column might already exist
    }
    
    // 2. Create plans table (Annual Procurement Plans)
    $pdo->exec("CREATE TABLE IF NOT EXISTS plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_name VARCHAR(255) NOT NULL,
        fiscal_year INT NOT NULL,
        budget_source VARCHAR(255) NOT NULL DEFAULT '',
        announce_date DATE NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 3. Create projects table (linked to plans, with full procurement metadata)
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        project_name VARCHAR(255) NOT NULL,
        budget DECIMAL(15,2) NOT NULL,
        procurement_type VARCHAR(50) NOT NULL, -- จัดซื้อ, จัดจ้าง, เช่า
        quantity VARCHAR(100) NOT NULL, -- ปริมาณ เช่น 8 รายการ, 1 ชุด
        required_date VARCHAR(100) NOT NULL, -- กำหนดต้องการใช้วัสดุ
        procurement_method VARCHAR(100) NOT NULL, -- วิธี เช่น เฉพาะเจาะจง, ประกวดราคา
        request_month VARCHAR(100) NOT NULL, -- ช่วงเดือนขอซื้อขอจ้าง
        contract_month VARCHAR(100) NOT NULL, -- ทำสัญญา/สั่งซื้อสั่งจ้าง
        status VARCHAR(50) NOT NULL DEFAULT 'planning', -- planning, draft, bidding, clarification, completed
        responsible_person VARCHAR(255) NULL, -- ผู้รับผิดชอบ
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add responsible_person column to existing projects if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE projects ADD COLUMN responsible_person VARCHAR(255) NULL");
    } catch (PDOException $e) {
        // Column might already exist
    }
    
    // 4. Create announcements table (linked to projects, representing steps 2-5)
    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        category_id INT NOT NULL, -- 2: ร่างประกาศ..., 3: ประกาศเชิญชวน..., 4: ชี้แจง..., 5: ประกาศผู้ชนะ...
        title VARCHAR(255) NOT NULL,
        announce_date DATE NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // 5. Create default admin if not exists (username: admin, password: change_me_first_1234)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashed_pass = password_hash('admin1234', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES ('admin', ?, 'ผู้ดูแลระบบ', 'admin')");
        $stmt->execute([$hashed_pass]);
    }
    
    // Create default executive if not exists (username: executive, password: exec1234)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'executive'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashed_pass = password_hash('exec1234', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES ('executive', ?, 'ผู้บริหาร', 'executive')");
        $stmt->execute([$hashed_pass]);
    }
    
    // Create default superadmin if not exists (username: superadmin, password: super1234)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'superadmin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $hashed_pass = password_hash('super1234', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES ('superadmin', ?, 'ผู้ดูแลระบบสูงสุด', 'superadmin')");
        $stmt->execute([$hashed_pass]);
    }

    // 6. Create project_tracking table (1-to-1 with projects for steps 2,3,4,7,9)
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_tracking (
        project_id INT PRIMARY KEY,
        step2_status VARCHAR(50) DEFAULT 'pending',
        step2_date DATE NULL,
        step3_status VARCHAR(50) DEFAULT 'pending',
        step3_date DATE NULL,
        step4_status VARCHAR(50) DEFAULT 'pending',
        step4_date DATE NULL,
        step7_status VARCHAR(50) DEFAULT 'pending',
        step7_date DATE NULL,
        step9_status VARCHAR(50) DEFAULT 'pending',
        step9_date DATE NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add columns for Specific Procurement Method ("เฉพาะเจาะจง")
    try {
        $pdo->exec("ALTER TABLE project_tracking 
            ADD COLUMN spec_step2_status VARCHAR(50) DEFAULT 'pending',
            ADD COLUMN spec_step2_date DATE NULL,
            ADD COLUMN spec_step2b_status VARCHAR(50) DEFAULT 'pending',
            ADD COLUMN spec_step2b_date DATE NULL,
            ADD COLUMN spec_step3_status VARCHAR(50) DEFAULT 'pending',
            ADD COLUMN spec_step3_date DATE NULL,
            ADD COLUMN spec_step4_status VARCHAR(50) DEFAULT 'pending',
            ADD COLUMN spec_step4_date DATE NULL
        ");
    } catch (PDOException $e) {
        // Columns might already exist, ignore error
    }

    // 7. Create project_installments table (1-to-many with projects for step 8 - Delivery/Inspection/Payment)
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_installments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        contract_id INT NULL,
        installment_name VARCHAR(255) NOT NULL,
        delivery_status VARCHAR(50) DEFAULT 'pending',
        delivery_date DATE NULL,
        inspection_status VARCHAR(50) DEFAULT 'pending',
        inspection_date DATE NULL,
        payment_status VARCHAR(50) DEFAULT 'pending',
        payment_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
        FOREIGN KEY (contract_id) REFERENCES project_contracts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 8. Create project_contracts table (1-to-many with projects for contract signing step)
    $pdo->exec("CREATE TABLE IF NOT EXISTS project_contracts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        project_id INT NOT NULL,
        company_name VARCHAR(255) NOT NULL,
        contract_status VARCHAR(50) DEFAULT 'pending',
        contract_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// Thai Date formatting helper: e.g. 2026-05-27 -> 27 พ.ค. 2569
function get_thai_date($date_str) {
    if (!$date_str) return '';
    $months = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
        7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    $time = strtotime($date_str);
    $d = date('j', $time);
    $m = $months[(int)date('n', $time)];
    $y = (int)date('Y', $time) + 543; // Convert to Buddhist Era
    return "$d $m $y";
}

// Thai Category Name helper
function get_category_name($cat_id) {
    $categories = [
        1 => 'แผนการจัดซื้อจัดจ้างประจำปี',
        2 => 'ร่างประกาศและร่างเอกสารซื้อหรือจ้าง',
        3 => 'ประกาศและเอกสารเชิญชวน',
        4 => 'การชี้แจงรายละเอียดเพิ่มเติมหรือการแก้ไขเอกสาร',
        5 => 'ประกาศผลผู้ชนะหรือผู้ได้รับการคัดเลือก'
    ];
    return isset($categories[$cat_id]) ? $categories[$cat_id] : 'ไม่ระบุประเภท';
}

// Status translation and styling configuration
function get_status_info($status) {
    switch ($status) {
        case 'planning':
            return [
                'label' => 'แผนการจัดซื้อจัดจ้าง',
                'class' => 'badge-planning',
                'step' => 1
            ];
        case 'draft':
            return [
                'label' => 'อยู่ระหว่างรับฟังความคิดเห็น (ร่างประกาศ)',
                'class' => 'badge-draft',
                'step' => 2
            ];
        case 'bidding':
            return [
                'label' => 'อยู่ระหว่างยื่นข้อเสนอ (ประกาศเชิญชวน)',
                'class' => 'badge-bidding',
                'step' => 3
            ];
        case 'clarification':
            return [
                'label' => 'ชี้แจงรายละเอียดเพิ่มเติม',
                'class' => 'badge-clarification',
                'step' => 4
            ];
        case 'completed':
            return [
                'label' => 'ประกาศผู้ชนะการเสนอราคา',
                'class' => 'badge-completed',
                'step' => 5
            ];
        default:
            return [
                'label' => 'อยู่ระหว่างการเตรียมงาน',
                'class' => 'badge-unknown',
                'step' => 0
            ];
    }
}

// Helper to determine project timeline status based on current date and request month
function get_procurement_time_status($current_step, $request_month_str, $plan_fiscal_year) {
    if ($current_step >= 5) {
        return [
            'label' => 'ดำเนินการเสร็จสิ้น (ได้ผู้ชนะแล้ว)',
            'class' => 'badge-completed'
        ];
    }
    
    if ($current_step > 1) {
        return [
            'label' => 'อยู่ระหว่างดำเนินการจัดซื้อจัดจ้าง',
            'class' => 'badge-bidding'
        ];
    }
    
    // If current_step == 1 (Planning / Not started yet)
    if (empty($request_month_str) || $request_month_str === 'ไม่ระบุ') {
        return [
            'label' => 'ยังไม่เริ่มดำเนินการ (ไม่ได้ระบุรอบเดือน)',
            'class' => 'badge-unknown'
        ];
    }
    
    // Parse month and year from request_month_str
    $thai_months = [
        1 => ['มกราคม', 'ม.ค.'],
        2 => ['กุมภาพันธ์', 'ก.พ.'],
        3 => ['มีนาคม', 'มี.ค.'],
        4 => ['เมษายน', 'เม.ย.'],
        5 => ['พฤษภาคม', 'พ.ค.'],
        6 => ['มิถุนายน', 'มิ.ย.'],
        7 => ['กรกฎาคม', 'ก.ค.'],
        8 => ['สิงหาคม', 'ส.ค.'],
        9 => ['กันยายน', 'ก.ย.'],
        10 => ['ตุลาคม', 'ต.ค.'],
        11 => ['พฤศจิกายน', 'พ.ย.'],
        12 => ['ธันวาคม', 'ธ.ค.']
    ];
    
    $target_month = null;
    foreach ($thai_months as $m_num => $names) {
        foreach ($names as $name) {
            if (mb_strpos($request_month_str, $name) !== false) {
                $target_month = $m_num;
                break 2;
            }
        }
    }
    
    // Parse year
    $target_year = null;
    if (preg_match('/\b(25\d{2})\b/', $request_month_str, $matches)) {
        $target_year = intval($matches[1]) - 543; // Convert B.E. to A.D.
    } elseif (preg_match('/\b(20\d{2})\b/', $request_month_str, $matches)) {
        $target_year = intval($matches[1]);
    } elseif (preg_match('/(\b\d{2}\b)/', $request_month_str, $matches)) {
        // e.g. "69"
        $short_year = intval($matches[1]);
        if ($short_year >= 60 && $short_year <= 99) {
            $target_year = 2500 + $short_year - 543;
        }
    }
    
    if (!$target_year) {
        $target_year = $plan_fiscal_year - 543; // Default to plan's fiscal year converted to A.D.
    }
    
    if (!$target_month) {
        return [
            'label' => 'ยังไม่เริ่มดำเนินการ (รอบขอซื้อ: ' . htmlspecialchars($request_month_str) . ')',
            'class' => 'badge-planning'
        ];
    }
    
    // Compare target year & month with current year & month
    $current_year = intval(date('Y'));
    $current_month = intval(date('n'));
    
    if ($current_year > $target_year || ($current_year === $target_year && $current_month > $target_month)) {
        return [
            'label' => 'ล่าช้ากว่าแผน (กำหนดรอบขอซื้อ: ' . htmlspecialchars($request_month_str) . ')',
            'class' => 'badge-danger'
        ];
    }
    
    return [
        'label' => 'ตามแผน (รอบขอซื้อ: ' . htmlspecialchars($request_month_str) . ')',
        'class' => 'badge-planning'
    ];
}

// Format YYYY-MM string to Thai Month Year (e.g., 2026-05 -> พฤษภาคม 2569)
// If not in YYYY-MM format, return original string for backwards compatibility
function format_month_year($date_str) {
    if (empty($date_str)) return 'ไม่ระบุ';
    if (preg_match('/^(\d{4})-(\d{2})$/', trim($date_str), $matches)) {
        $year = intval($matches[1]) + 543; // convert to Buddhist Era
        $month = intval($matches[2]);
        $thai_months = [
            1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
            5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
            9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
        ];
        return isset($thai_months[$month]) ? $thai_months[$month] . ' ' . $year : $date_str;
    }
    return $date_str;
}
?>
