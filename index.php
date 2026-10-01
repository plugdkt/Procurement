<?php
// index.php - Public Announcement Portal
require_once 'db.php';

try {
    $pdo = db_connect();
} catch (Exception $e) {
    die("ระบบไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ");
}

// Get filter inputs
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$year = isset($_GET['year']) ? intval($_GET['year']) : null;

// 1. Get fiscal years list from plans for filter dropdown
$years_stmt = $pdo->query("SELECT DISTINCT fiscal_year FROM plans ORDER BY fiscal_year DESC");
$fiscal_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

if ($year === null) {
    // Default to the latest fiscal year
    $year = !empty($fiscal_years) ? intval($fiscal_years[0]) : 0;
}

// Determine the year to use for stats
$stats_year = $year;
if ($stats_year == 0 && !empty($fiscal_years)) {
    $stats_year = intval($fiscal_years[0]); // Default to latest year if "All years" (0) is selected
}

// 2. Fetch overall stats (Filtered for e-bidding only AND selected year AND ready for announcement)
$stat_query = "SELECT COUNT(p.id) as cnt, SUM(p.budget) as total FROM projects p JOIN plans pl ON p.plan_id = pl.id JOIN project_tracking pt ON p.id = pt.project_id WHERE p.procurement_method = 'ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)' AND pt.step4_status = 'completed'";
if ($stats_year > 0) {
    $stat_query .= " AND pl.fiscal_year = " . $stats_year;
}
$stat_stmt = $pdo->query($stat_query);
$overall_stats = $stat_stmt->fetch();
$total_projects_count = $overall_stats['cnt'] ?? 0;
$total_budget_sum = $overall_stats['total'] ?? 0.00;

$plans_query = "SELECT COUNT(*) FROM plans";
if ($stats_year > 0) {
    $plans_query .= " WHERE fiscal_year = " . $stats_year;
}
$plans_count = $pdo->query($plans_query)->fetchColumn();

$completed_query = "SELECT COUNT(p.id) FROM projects p JOIN plans pl ON p.plan_id = pl.id JOIN project_tracking pt ON p.id = pt.project_id WHERE p.status = 'completed' AND p.procurement_method = 'ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)' AND pt.step4_status = 'completed'";
if ($stats_year > 0) {
    $completed_query .= " AND pl.fiscal_year = " . $stats_year;
}
$completed_count = $pdo->query($completed_query)->fetchColumn();

// 3. Fetch plans and their projects based on filter
$plan_query = "SELECT * FROM plans WHERE 1=1";
$plan_params = [];
if ($year > 0) {
    $plan_query .= " AND fiscal_year = ?";
    $plan_params[] = $year;
}
$plan_query .= " ORDER BY fiscal_year DESC, id DESC";
$plan_stmt = $pdo->prepare($plan_query);
$plan_stmt->execute($plan_params);
$plans = $plan_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบประกาศจัดซื้อจัดจ้าง - คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .plan-wrapper-card {
            background: #ffffff;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-md);
            margin-bottom: 40px;
            overflow: hidden;
        }
        .plan-header-banner {
            background: linear-gradient(135deg, #4a2175 0%, var(--primary) 100%);
            color: #ffffff;
            padding: 24px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-bottom: 2px solid var(--secondary);
        }
        .plan-info-title h2 {
            font-size: 1.35rem;
            font-weight: 700;
        }
        .plan-projects-list {
            padding: 24px;
            background-color: #fafbfd;
        }
        .plan-download-btn {
            background-color: var(--secondary);
            color: #ffffff;
            font-weight: 700;
        }
        .plan-download-btn:hover {
            background-color: var(--secondary-light);
            transform: translateY(-2px);
            color: #ffffff;
        }
    </style>
</head>
<body>

    <!-- Header Section -->
    <header class="main-header">
        <div class="container header-content">
            <div class="brand">
                <div class="logo-placeholder">
                    <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                        <path d="M50 10 L85 30 L85 70 L50 90 L15 70 L15 30 Z" fill="none" stroke="#bca256" stroke-width="4"/>
                        <path d="M50 18 L78 34 L78 66 L50 82 L22 66 L22 34 Z" fill="#5d2d91"/>
                        <circle cx="50" cy="50" r="16" fill="#bca256"/>
                        <path d="M50 38 L50 62 M38 50 L62 50" stroke="#ffffff" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="brand-text">
                    <h1>ระบบประกาศจัดซื้อจัดจ้างและการจัดหาพัสดุ</h1>
                    <p>คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา | School of Medical Sciences</p>
                </div>
            </div>
            <div class="header-actions">
                <a href="login.php" class="btn btn-secondary" style="border-color: #ffffff; color: #ffffff; background: rgba(255,255,255,0.1);">
                    <svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/>
                    </svg>
                    สำหรับเจ้าหน้าที่ (Admin)
                </a>
            </div>
        </div>
    </header>

    <!-- Stats Banner Section -->
    <section class="container stats-banner">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon purple">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                </div>
                <div class="stat-details">
                    <h3>แผนการจัดซื้อจัดจ้างประจำปี</h3>
                    <div class="value"><?= number_format($plans_count) ?> แผนงาน</div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon gold">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="1" x2="12" y2="23"></line>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                    </svg>
                </div>
                <div class="stat-details">
                    <h3>งบประมาณโครงการรวม</h3>
                    <div class="value"><?= number_format($total_budget_sum, 2) ?> บาท</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon success">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                    </svg>
                </div>
                <div class="stat-details">
                    <h3>โครงการเสร็จสิ้น (ได้ผู้ชนะ)</h3>
                    <div class="value"><?= number_format($completed_count) ?> / <?= number_format($total_projects_count) ?> โครงการ</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Search & Filter Area -->
    <main class="container">
        <div style="margin-bottom: 24px; text-align: center;">
            <h2 style="font-size: 1.15rem; font-weight: 700; color: var(--primary-dark); display: inline-block; padding: 10px 30px; background: #ffffff; border-radius: 50px; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
                <svg style="width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:text-bottom;margin-right:8px;" viewBox="0 0 24 24">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                สรุปข้อมูลภาพรวมระบบ ประจำปีงบประมาณ พ.ศ. <?= $stats_year > 0 ? $stats_year : 'ทั้งหมด' ?>
            </h2>
        </div>
        <section class="search-filter-card">
            <form action="index.php" method="GET" class="filter-form" style="grid-template-columns: 3fr 1fr auto;">
                <div class="form-group">
                    <label for="search">ค้นหาโครงการภายใต้แผนงาน</label>
                    <input type="text" id="search" name="search" class="form-control" placeholder="พิมพ์คีย์เวิร์ดชื่อโครงการ..." value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="form-group">
                    <label for="year">กรองตามปีงบประมาณ</label>
                    <select id="year" name="year" class="form-control">
                        <option value="0">--- แสดงทุกปี ---</option>
                        <?php foreach ($fiscal_years as $f_year): ?>
                            <option value="<?= $f_year ?>" <?= $year === intval($f_year) ? 'selected' : '' ?>><?= $f_year ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    <svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24">
                        <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                    </svg>
                    ค้นหา
                </button>
            </form>
        </section>

        <!-- 1. Plans List -->
        <section class="announcement-list" style="margin-bottom: 40px;">
            <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--primary-dark); margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <svg style="width:24px;height:24px;fill:currentColor;" viewBox="0 0 24 24">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                </svg>
                ประกาศแผนการจัดซื้อจัดจ้างประจำปี
            </h2>

            <?php if (empty($plans)): ?>
                <div style="background:#ffffff; border-radius: var(--radius-md); padding: 50px; text-align: center; border: 1px solid var(--border-color); color: var(--text-muted);">
                    <p style="font-size: 1.1rem; font-weight: 600;">ไม่พบข้อมูลแผนงาน</p>
                </div>
            <?php else: ?>
                <div style="background: #ffffff; border-radius: var(--radius-md); border: 1px solid var(--border-color); overflow: hidden;">
                    <?php 
                    $recent_plans = array_slice($plans, 0, 3);
                    $total_recent = count($recent_plans);
                    foreach ($recent_plans as $index => $plan): 
                        $border_bottom = ($index < $total_recent - 1) ? 'border-bottom: 1px solid var(--border-color);' : '';
                    ?>
                        <div style="padding: 20px; display: flex; justify-content: space-between; align-items: center; gap: 20px; <?= $border_bottom ?>">
                            <div>
                                <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px;"><?= htmlspecialchars($plan['plan_name']) ?></h3>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 2px;">แหล่งงบประมาณ: <strong><?= !empty($plan['budget_source']) ? htmlspecialchars($plan['budget_source']) : '-' ?></strong></p>
                                <p style="font-size: 0.85rem; color: var(--text-muted);">ปีงบประมาณ พ.ศ. <?= $plan['fiscal_year'] ?> | วันที่ประกาศเผยแพร่: <?= get_thai_date($plan['announce_date']) ?></p>
                            </div>
                            <div>
                                <a href="<?= htmlspecialchars($plan['file_path']) ?>" target="_blank" class="btn plan-download-btn" style="padding: 8px 16px; font-size: 0.9rem; background-color: var(--secondary); color: white;">
                                    <svg style="width:16px;height:16px;fill:currentColor;vertical-align:text-bottom;margin-right:4px;" viewBox="0 0 24 24">
                                        <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/>
                                    </svg>
                                    ดาวน์โหลดแผน (PDF)
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($plans) > count($recent_plans)): ?>
                <div style="text-align: center; margin-top: 24px;">
                    <a href="all_plans.php" class="btn btn-secondary" style="border-radius: 50px; padding: 10px 24px; font-weight: 600; font-size: 0.95rem;">
                        ดูแผนการจัดซื้อจัดจ้างทั้งหมด (<?= count($plans) ?> แผน) &rarr;
                    </a>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <!-- 2. Projects List -->
        <?php
        // Fetch all e-Bidding projects joined with plans and tracking
        $proj_query_all = "SELECT p.*, pl.plan_name, pl.fiscal_year, pl.announce_date as plan_announce_date, pl.file_path as plan_file_path 
                           FROM projects p 
                           JOIN plans pl ON p.plan_id = pl.id 
                           JOIN project_tracking pt ON p.id = pt.project_id
                           WHERE p.procurement_method = 'ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)'
                           AND pt.step4_status = 'completed'";
        $proj_params_all = [];
        
        if ($year > 0) {
            $proj_query_all .= " AND pl.fiscal_year = ?";
            $proj_params_all[] = $year;
        }
        
        if ($search !== '') {
            $proj_query_all .= " AND p.project_name LIKE ?";
            $proj_params_all[] = "%$search%";
        }
        
        $proj_query_all .= " ORDER BY p.id DESC";
        $proj_stmt_all = $pdo->prepare($proj_query_all);
        $proj_stmt_all->execute($proj_params_all);
        $all_ebidding_projects = $proj_stmt_all->fetchAll();
        
        $active_projects = [];
        $completed_projects = [];
        foreach ($all_ebidding_projects as $proj) {
            if ($proj['status'] === 'completed') {
                $completed_projects[] = $proj;
            } else {
                $active_projects[] = $proj;
            }
        }
        ?>

        <section class="announcement-list">
            <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--primary-dark); margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <svg style="width:24px;height:24px;fill:currentColor;" viewBox="0 0 24 24">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                </svg>
                ประกาศโครงการจัดซื้อจัดจ้าง (วิธีประกวดราคาอิเล็กทรอนิกส์ e-Bidding)
            </h2>

            <?php if (empty($active_projects)): ?>
                <div style="background:#ffffff; border-radius: var(--radius-md); padding: 50px; text-align: center; border: 1px solid var(--border-color); color: var(--text-muted);">
                    <p style="font-size: 1.1rem; font-weight: 600;">ไม่พบข้อมูลโครงการที่อยู่ระหว่างดำเนินการ</p>
                </div>
            <?php else: ?>
                <div class="plan-projects-list" style="background: transparent; padding: 0;">
                    <?php foreach ($active_projects as $proj): 
                        $status_info = get_status_info($proj['status']);
                        
                        // Fetch announcements for this project (steps 2 to 5)
                        $ann_stmt = $pdo->prepare("SELECT * FROM announcements WHERE project_id = ? ORDER BY category_id ASC, id ASC");
                        $ann_stmt->execute([$proj['id']]);
                        $announcements = $ann_stmt->fetchAll();
                        
                        // Map active steps
                        $active_steps = [];
                        $active_steps[1] = true; // Step 1 is always active/completed because it belongs to this Annual Plan!
                        foreach ($announcements as $ann) {
                            $active_steps[$ann['category_id']] = true;
                        }
                        
                        // Calculate latest date based on the latest step
                        $latest_date = $proj['plan_announce_date'];
                        if (!empty($announcements)) {
                            $last_ann = end($announcements);
                            $latest_date = $last_ann['announce_date'];
                        }
                    ?>
                        <article class="project-card" id="project-<?= $proj['id'] ?>" style="margin-bottom: 20px;">
                            <!-- Project Header -->
                            <div class="project-header" onclick="toggleDetails(<?= $proj['id'] ?>)" style="cursor: pointer; background: #ffffff; border-bottom: none;">
                                <div class="project-title-area">
                                    <h3 style="font-size: 1.3rem; color: var(--primary-dark); font-weight: 700; margin-bottom: 8px; line-height: 1.4;"><?= htmlspecialchars($proj['project_name']) ?></h3>
                                    <div class="project-meta">
                                        <span class="meta-badge" style="background-color: #fce7f3; color: #be185d; font-size: 0.8rem; border: 1px solid #fbcfe8;">
                                            <svg style="width:12px;height:12px;fill:currentColor;vertical-align:middle;margin-right:4px;" viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                                            อัปเดตล่าสุด: <?= get_thai_date($latest_date) ?>
                                        </span>
                                        <span class="meta-badge budget">
                                            งบประมาณโครงการ: <?= number_format($proj['budget'], 2) ?> บาท
                                        </span>
                                        <span class="meta-badge" style="background-color: #f1f5f9; color: var(--text-muted);">
                                            คลิกเพื่อดูเอกสาร (<?= count($announcements) + 1 ?>)
                                        </span>
                                    </div>
                                </div>
                                <span class="project-status-badge <?= $status_info['class'] ?>">
                                    <?= $status_info['label'] ?>
                                </span>
                            </div>

                            <!-- Project Progress Stepper Timeline -->
                            <div class="timeline-stepper" onclick="toggleDetails(<?= $proj['id'] ?>)" style="cursor: pointer; border-top: 1px solid var(--border-color); background-color: #fafbfd;">
                                <div class="steps-container">
                                    <?php 
                                    $steps = [
                                        1 => 'แผนการจัดซื้อจัดจ้าง',
                                        2 => 'ร่างประกาศ/TOR',
                                        3 => 'ประกาศเชิญชวน',
                                        4 => 'การชี้แจงเพิ่มเติม',
                                        5 => 'ประกาศผลผู้ชนะ'
                                    ];
                                    foreach ($steps as $step_id => $step_name):
                                        $step_class = '';
                                        if (isset($active_steps[$step_id])) {
                                            $step_class = 'completed';
                                            if ($status_info['step'] == $step_id) {
                                                $step_class .= ' current';
                                            }
                                        } elseif ($status_info['step'] == $step_id) {
                                            $step_class = 'current';
                                        }
                                    ?>
                                        <div class="step-item <?= $step_class ?>">
                                            <div class="step-node"><?= $step_id ?></div>
                                            <div class="step-label"><?= $step_name ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Project Document Details Panel -->
                            <div class="project-details-panel" id="details-<?= $proj['id'] ?>">
                                <div class="doc-timeline-title">
                                    <svg fill="currentColor" viewBox="0 0 24 24" style="width:18px;height:18px;"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                                    ลำดับเอกสารประกาศและดาวน์โหลด
                                </div>
                                
                                <div class="doc-list">
                                    <!-- Step 1 Document (Inherited from Parent Plan) -->
                                    <div class="doc-row">
                                        <div class="doc-info">
                                            <div>
                                                <span class="doc-step-indicator badge-planning">
                                                    ขั้นตอนที่ 1: แผนการจัดซื้อจัดจ้างประจำปี
                                                </span>
                                                <h3 class="doc-title-text" style="margin-top: 6px;"><?= htmlspecialchars($proj['plan_name']) ?></h3>
                                                <div class="doc-date">วันที่เผยแพร่แผนจัดซื้อจัดจ้างหลัก: <?= get_thai_date($proj['plan_announce_date']) ?></div>
                                            </div>
                                        </div>
                                        <div class="doc-actions">
                                            <a href="<?= htmlspecialchars($proj['plan_file_path']) ?>" target="_blank" class="btn btn-secondary btn-icon-only" title="ดูเอกสาร">
                                                <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                                    <path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/>
                                                </svg>
                                            </a>
                                            <a href="<?= htmlspecialchars($proj['plan_file_path']) ?>" download class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem;">
                                                ดาวน์โหลด PDF
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Steps 2 to 5 Documents -->
                                    <?php foreach ($announcements as $ann): 
                                        $cat_badges = [
                                            2 => 'badge-draft',
                                            3 => 'badge-bidding',
                                            4 => 'badge-clarification',
                                            5 => 'badge-completed'
                                        ];
                                        $badge_class = $cat_badges[$ann['category_id']] ?? 'badge-unknown';
                                    ?>
                                        <div class="doc-row">
                                            <div class="doc-info">
                                                <div>
                                                    <span class="doc-step-indicator <?= $badge_class ?>">
                                                        ขั้นตอนที่ <?= $ann['category_id'] ?>: <?= get_category_name($ann['category_id']) ?>
                                                    </span>
                                                    <h3 class="doc-title-text" style="margin-top: 6px;"><?= htmlspecialchars($ann['title']) ?></h3>
                                                    <div class="doc-date">วันที่เผยแพร่: <?= get_thai_date($ann['announce_date']) ?></div>
                                                    <?php if (!empty($ann['notes'])): ?>
                                                        <div class="doc-notes"><?= nl2br(htmlspecialchars($ann['notes'])) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="doc-actions">
                                                <a href="<?= htmlspecialchars($ann['file_path']) ?>" target="_blank" class="btn btn-secondary btn-icon-only" title="ดูเอกสาร">
                                                    <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                                        <path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/>
                                                    </svg>
                                                </a>
                                                <a href="<?= htmlspecialchars($ann['file_path']) ?>" download class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem;">
                                                    ดาวน์โหลด PDF
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!empty($completed_projects)): ?>
        <section class="announcement-list" style="margin-top: 40px;">
            <h2 style="font-size: 1.3rem; font-weight: 700; color: var(--success); margin-bottom: 24px; display: flex; align-items: center; gap: 8px;">
                <svg style="width:24px;height:24px;fill:currentColor;" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                โครงการที่ดำเนินการเสร็จสิ้น (ประกาศผู้ชนะเรียบร้อยแล้ว)
            </h2>
            <div style="background: #ffffff; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
                <?php foreach ($completed_projects as $index => $proj): 
                    $border_bottom = ($index < count($completed_projects) - 1) ? 'border-bottom: 1px solid var(--border-color);' : '';
                    
                    // Fetch announcements for this project (steps 2 to 5)
                    $ann_stmt = $pdo->prepare("SELECT * FROM announcements WHERE project_id = ? ORDER BY category_id ASC, id ASC");
                    $ann_stmt->execute([$proj['id']]);
                    $announcements = $ann_stmt->fetchAll();

                    $latest_date = $proj['plan_announce_date'];
                    if (!empty($announcements)) {
                        $last_ann = end($announcements);
                        $latest_date = $last_ann['announce_date'];
                        reset($announcements);
                    }
                ?>
                    <article style="<?= $border_bottom ?>">
                        <div style="padding: 20px; display: flex; justify-content: space-between; align-items: center; gap: 20px;">
                            <div style="flex: 1;">
                                <h3 style="font-size: 1.1rem; font-weight: 600; color: var(--text-main); margin-bottom: 4px; line-height: 1.4;"><?= htmlspecialchars($proj['project_name']) ?></h3>
                                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 2px;">
                                    งบประมาณ: <strong style="color: var(--secondary-dark);"><?= number_format($proj['budget'], 2) ?> บาท</strong>
                                </p>
                                <p style="font-size: 0.85rem; color: var(--text-muted);">
                                    อัปเดตล่าสุดเมื่อ: <?= get_thai_date($latest_date) ?>
                                </p>
                            </div>
                            <div>
                                <button onclick="toggleDetails('completed-<?= $proj['id'] ?>')" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.9rem; white-space: nowrap; border-radius: 20px;">
                                    ดูรายละเอียดเพิ่มเติม
                                </button>
                            </div>
                        </div>
                        
                        <div class="project-details" id="details-completed-<?= $proj['id'] ?>" style="display: none; border-top: 1px solid var(--border-color); padding: 20px; background-color: #fafbfd;">
                            <h4 style="font-size: 1rem; color: var(--primary-dark); margin-bottom: 16px;">เอกสารประกาศขั้นตอนต่างๆ</h4>
                            
                            <div class="doc-list">
                                <!-- Step 1 -->
                                <div class="doc-row">
                                    <div class="doc-info">
                                        <div>
                                            <span class="doc-step-indicator badge-completed">
                                                ขั้นตอนที่ 1: แผนการจัดซื้อจัดจ้างประจำปี
                                            </span>
                                            <h3 class="doc-title-text" style="margin-top: 6px;"><?= htmlspecialchars($proj['plan_name']) ?></h3>
                                            <div class="doc-date">วันที่เผยแพร่: <?= get_thai_date($proj['plan_announce_date']) ?></div>
                                        </div>
                                    </div>
                                    <div class="doc-actions">
                                        <a href="<?= htmlspecialchars($proj['plan_file_path']) ?>" download class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem;">
                                            ดาวน์โหลด PDF
                                        </a>
                                    </div>
                                </div>
                                
                                <!-- Steps 2 to 5 -->
                                <?php foreach ($announcements as $ann): 
                                    $cat_badges = [
                                        2 => 'badge-draft',
                                        3 => 'badge-bidding',
                                        4 => 'badge-clarification',
                                        5 => 'badge-completed'
                                    ];
                                    $badge_class = $cat_badges[$ann['category_id']] ?? 'badge-unknown';
                                ?>
                                    <div class="doc-row">
                                        <div class="doc-info">
                                            <div>
                                                <span class="doc-step-indicator <?= $badge_class ?>">
                                                    ขั้นตอนที่ <?= $ann['category_id'] ?>: <?= get_category_name($ann['category_id']) ?>
                                                </span>
                                                <h3 class="doc-title-text" style="margin-top: 6px;"><?= htmlspecialchars($ann['title']) ?></h3>
                                                <div class="doc-date">วันที่เผยแพร่: <?= get_thai_date($ann['announce_date']) ?></div>
                                                <?php if (!empty($ann['notes'])): ?>
                                                    <div class="doc-notes"><?= nl2br(htmlspecialchars($ann['notes'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="doc-actions">
                                            <a href="<?= htmlspecialchars($ann['file_path']) ?>" download class="btn btn-primary" style="padding: 8px 16px; font-size: 0.85rem;">
                                                ดาวน์โหลด PDF
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="container">
            <p class="brand-copyright">คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา</p>
            <p>School of Medical Sciences, University of Phayao</p>
            <p style="font-size:0.8rem; margin-top: 10px; opacity:0.7;">&copy; <?= (date('Y') + 543) ?>. สงวนลิขสิทธิ์ตามระเบียบจัดซื้อจัดจ้างภาครัฐ.</p>
        </div>
    </footer>

    <!-- Interactive script to toggle details -->
    <script>
        function toggleDetails(projectId) {
            const panel = document.getElementById('details-' + projectId);
            if (panel.style.display === 'block') {
                panel.style.display = 'none';
            } else {
                panel.style.display = 'block';
            }
        }
    </script>
</body>
</html>