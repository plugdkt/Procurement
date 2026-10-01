<?php
// all_plans.php - Public Plans List
require_once 'db.php';

try {
    $pdo = db_connect();
} catch (Exception $e) {
    die("ระบบไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาติดต่อผู้ดูแลระบบ");
}

$year = isset($_GET['year']) ? intval($_GET['year']) : 0;

// Get fiscal years list for filter
$years_stmt = $pdo->query("SELECT DISTINCT fiscal_year FROM plans ORDER BY fiscal_year DESC");
$fiscal_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Fetch plans
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
    <title>แผนการจัดซื้อจัดจ้างทั้งหมด - คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา</title>
    <link rel="stylesheet" href="style.css">
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
                <a href="index.php" class="btn btn-secondary" style="border-color: #ffffff; color: #ffffff; background: rgba(255,255,255,0.1);">
                    &larr; กลับหน้าหลัก
                </a>
            </div>
        </div>
    </header>

    <main class="container" style="margin-top: 40px; min-height: 50vh;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
            <h2 style="font-size: 1.5rem; font-weight: 700; color: var(--primary-dark); display: flex; align-items: center; gap: 8px;">
                <svg style="width:28px;height:28px;fill:currentColor;" viewBox="0 0 24 24">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                </svg>
                แผนการจัดซื้อจัดจ้างประจำปีทั้งหมด
            </h2>

            <form action="all_plans.php" method="GET" style="display: flex; gap: 10px; align-items: center;">
                <label for="year" style="font-weight: 600;">กรองปีงบประมาณ:</label>
                <select id="year" name="year" class="form-control" style="width: 200px; padding: 8px;" onchange="this.form.submit()">
                    <option value="0">--- แสดงทุกปี ---</option>
                    <?php foreach ($fiscal_years as $f_year): ?>
                        <option value="<?= $f_year ?>" <?= $year === intval($f_year) ? 'selected' : '' ?>><?= $f_year ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php if (empty($plans)): ?>
            <div style="background:#ffffff; border-radius: var(--radius-md); padding: 50px; text-align: center; border: 1px solid var(--border-color); color: var(--text-muted);">
                <p style="font-size: 1.1rem; font-weight: 600;">ไม่พบข้อมูลแผนงาน</p>
            </div>
        <?php else: ?>
            <div style="background: #ffffff; border-radius: var(--radius-md); border: 1px solid var(--border-color); overflow: hidden; margin-bottom: 60px;">
                <?php 
                $total_plans = count($plans);
                foreach ($plans as $index => $plan): 
                    $border_bottom = ($index < $total_plans - 1) ? 'border-bottom: 1px solid var(--border-color);' : '';
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

</body>
</html>
