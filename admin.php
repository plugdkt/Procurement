<?php
// admin.php - Administrative Control Panel (Annual Plans & Projects Management)
require_once 'db.php';
session_start();

// Authentication Check
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

try {
    $pdo = db_connect();
} catch (Exception $e) {
    die("ฐานข้อมูลเชื่อมต่อไม่ได้");
}

$success_msg = '';
$error_msg = '';

// Helper to re-evaluate and update project status based on highest uploaded category_id
function refresh_project_status($pdo, $project_id) {
    $stmt = $pdo->prepare("SELECT MAX(category_id) as max_cat FROM announcements WHERE project_id = ?");
    $stmt->execute([$project_id]);
    $max_cat = $stmt->fetchColumn();

    $new_status = 'planning'; // Default since step 1 (Annual Plan) is inherited
    if ($max_cat == 2) $new_status = 'draft';
    elseif ($max_cat == 3) $new_status = 'bidding';
    elseif ($max_cat == 4) $new_status = 'clarification';
    elseif ($max_cat == 5) $new_status = 'completed';

    $update_stmt = $pdo->prepare("UPDATE projects SET status = ? WHERE id = ?");
    $update_stmt->execute([$new_status, $project_id]);
}

// Helper to calculate project tracking progress
function get_project_tracking_progress($pdo, $proj) {
    $progress_score = 1; // Step 1 is always done
    $total_steps = ($proj['procurement_method'] === 'เฉพาะเจาะจง') ? 6 : 9;
    
    $track_stmt = $pdo->prepare("SELECT * FROM project_tracking WHERE project_id = ?");
    $track_stmt->execute([$proj['id']]);
    $track = $track_stmt->fetch();
    
    if ($proj['procurement_method'] === 'เฉพาะเจาะจง') {
        if ($track) {
            if (($track['spec_step2_status'] ?? '') === 'completed') $progress_score++;
            if (($track['spec_step2b_status'] ?? '') === 'completed') $progress_score++;
            if (($track['spec_step3_status'] ?? '') === 'completed') $progress_score++;
            if (($track['spec_step4_status'] ?? '') === 'completed') $progress_score++;
        }
        $inst_stmt = $pdo->prepare("SELECT delivery_status, inspection_status, payment_status FROM project_installments WHERE project_id = ?");
        $inst_stmt->execute([$proj['id']]);
        $installments = $inst_stmt->fetchAll();
        if (count($installments) > 0) {
            $total_sub = count($installments) * 3;
            $comp_sub = 0;
            foreach ($installments as $inst) {
                if ($inst['delivery_status'] === 'completed') $comp_sub++;
                if ($inst['inspection_status'] === 'completed') $comp_sub++;
                if ($inst['payment_status'] === 'completed') $comp_sub++;
            }
            $progress_score += ($comp_sub / $total_sub);
        } else {
            // No installments defined, if final tracking step is completed, count installments step as completed
            if ($track && ($track['spec_step4_status'] ?? '') === 'completed') {
                $progress_score += 1;
            }
        }
    } else {
        if ($track) {
            if (($track['step2_status'] ?? '') === 'completed') $progress_score++;
            if (($track['step3_status'] ?? '') === 'completed') $progress_score++;
            if (($track['step4_status'] ?? '') === 'completed') $progress_score++;
            if (($track['step7_status'] ?? '') === 'completed') $progress_score++;
            if (($track['step9_status'] ?? '') === 'completed') $progress_score++;
        }
        
        if (in_array($proj['status'], ['draft', 'bidding', 'clarification', 'completed'])) {
            $progress_score++; // Step 5
        }
        if ($proj['status'] === 'completed') {
            $progress_score++; // Step 6
        }
        
        $inst_stmt = $pdo->prepare("SELECT delivery_status, inspection_status, payment_status FROM project_installments WHERE project_id = ?");
        $inst_stmt->execute([$proj['id']]);
        $installments = $inst_stmt->fetchAll();
        if (count($installments) > 0) {
            $total_sub = count($installments) * 3;
            $comp_sub = 0;
            foreach ($installments as $inst) {
                if ($inst['delivery_status'] === 'completed') $comp_sub++;
                if ($inst['inspection_status'] === 'completed') $comp_sub++;
                if ($inst['payment_status'] === 'completed') $comp_sub++;
            }
            $progress_score += ($comp_sub / $total_sub);
        } else {
            // No installments defined, if final tracking step is completed, count installments step as completed
            if ($track && ($track['step9_status'] ?? '') === 'completed') {
                $progress_score += 1;
            }
        }
    }
    
    $progress_pct = round(($progress_score / $total_steps) * 100);
    if ($progress_pct > 100) $progress_pct = 100;
    
    $latest_completed_step = '1. แผนการจัดซื้อจัดจ้าง';
    $latest_completed_date = $proj['plan_date'];
    $current_status_color = 'var(--secondary)';
    
    if ($proj['procurement_method'] === 'เฉพาะเจาะจง') {
        if ($track && ($track['spec_step2_status'] ?? '') === 'completed' && !empty($track['spec_step2_date'])) {
            $latest_completed_step = '2. ขออนุมัติซื้อ/จ้าง';
            $latest_completed_date = $track['spec_step2_date'];
            $current_status_color = 'var(--primary)';
        }
        if ($track && ($track['spec_step3_status'] ?? '') === 'completed' && !empty($track['spec_step3_date'])) {
            $latest_completed_step = '3. เจรจาตกลงราคา';
            $latest_completed_date = $track['spec_step3_date'];
            $current_status_color = 'var(--primary)';
        }
        if ($track && ($track['spec_step4_status'] ?? '') === 'completed' && !empty($track['spec_step4_date'])) {
            $latest_completed_step = '4. ทำสัญญา/ใบสั่งซื้อ';
            $latest_completed_date = $track['spec_step4_date'];
            $current_status_color = 'var(--primary)';
        }
        $inst_stmt = $pdo->prepare("SELECT MAX(delivery_date) as max_del, MAX(payment_date) as max_pay FROM project_installments WHERE project_id = ? AND (delivery_status='completed' OR payment_status='completed')");
        $inst_stmt->execute([$proj['id']]);
        $inst_dates = $inst_stmt->fetch();
        if (!empty($inst_dates['max_pay'])) {
            $latest_completed_step = '5. เบิกจ่ายเงิน';
            $latest_completed_date = $inst_dates['max_pay'];
            $current_status_color = 'var(--success)';
        } elseif (!empty($inst_dates['max_del'])) {
            $latest_completed_step = '5. ส่งมอบ/ตรวจรับ';
            $latest_completed_date = $inst_dates['max_del'];
            $current_status_color = 'var(--success)';
        }
    } else {
        if ($track && ($track['step2_status'] ?? '') === 'completed' && !empty($track['step2_date'])) {
            $latest_completed_step = '2. ร่าง TOR';
            $latest_completed_date = $track['step2_date'];
            $current_status_color = 'var(--primary)';
        }
        if ($track && ($track['step3_status'] ?? '') === 'completed' && !empty($track['step3_date'])) {
            $latest_completed_step = '3. รายงานขอซื้อ/จ้าง';
            $latest_completed_date = $track['step3_date'];
            $current_status_color = 'var(--primary)';
        }
        if ($track && ($track['step4_status'] ?? '') === 'completed' && !empty($track['step4_date'])) {
            $latest_completed_step = '4. ตั้งคณะกรรมการ';
            $latest_completed_date = $track['step4_date'];
            $current_status_color = 'var(--primary)';
        }
        
        $step5_stmt = $pdo->prepare("SELECT MAX(announce_date) FROM announcements WHERE project_id = ? AND category_id = 3");
        $step5_stmt->execute([$proj['id']]);
        $step5_date = $step5_stmt->fetchColumn();
        if ($step5_date) {
            $latest_completed_step = '5. ประกาศ e-Bidding';
            $latest_completed_date = $step5_date;
            $current_status_color = 'var(--primary)';
        }
        
        $step6_stmt = $pdo->prepare("SELECT MAX(announce_date) FROM announcements WHERE project_id = ? AND category_id = 5");
        $step6_stmt->execute([$proj['id']]);
        $step6_date = $step6_stmt->fetchColumn();
        if ($step6_date) {
            $latest_completed_step = '6. ประกาศผู้ชนะ';
            $latest_completed_date = $step6_date;
            $current_status_color = 'var(--primary)';
        }
        
        if ($track && ($track['step7_status'] ?? '') === 'completed' && !empty($track['step7_date'])) {
            $latest_completed_step = '7. ทำสัญญา/วางประกัน';
            $latest_completed_date = $track['step7_date'];
            $current_status_color = 'var(--primary)';
        }
        
        $inst_stmt = $pdo->prepare("SELECT MAX(delivery_date) as max_del, MAX(payment_date) as max_pay FROM project_installments WHERE project_id = ? AND (delivery_status='completed' OR payment_status='completed')");
        $inst_stmt->execute([$proj['id']]);
        $inst_dates = $inst_stmt->fetch();
        if (!empty($inst_dates['max_pay'])) {
            $latest_completed_step = '8. เบิกจ่ายเงิน';
            $latest_completed_date = $inst_dates['max_pay'];
            $current_status_color = 'var(--primary)';
        } elseif (!empty($inst_dates['max_del'])) {
            $latest_completed_step = '8. ส่งมอบ/ตรวจรับ';
            $latest_completed_date = $inst_dates['max_del'];
            $current_status_color = 'var(--primary)';
        }
        
        if ($track && ($track['step9_status'] ?? '') === 'completed' && !empty($track['step9_date'])) {
            $latest_completed_step = '9. บันทึกรายงานพิจารณา';
            $latest_completed_date = $track['step9_date'];
            $current_status_color = 'var(--success)';
        }
    }

    return [
        'progress_score' => $progress_score,
        'total_steps' => $total_steps,
        'progress_pct' => $progress_pct,
        'latest_completed_step' => $latest_completed_step,
        'latest_completed_date' => $latest_completed_date,
        'current_status_color' => $current_status_color
    ];
}

// ----------------------------------------------------
// HANDLERS (POST & GET)
// ----------------------------------------------------

// 1. Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit();
}

// AJAX: Get Plan Tracking Details for Modal
if (isset($_GET['action']) && $_GET['action'] === 'get_plan_tracking') {
    $plan_id = intval($_GET['plan_id']);
    
    // Fetch plan details
    $plan_stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
    $plan_stmt->execute([$plan_id]);
    $plan_info = $plan_stmt->fetch();
    
    if ($plan_info) {
        echo '<div style="margin-bottom: 20px; padding: 16px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">';
        echo '  <div>';
        echo '    <span style="font-size: 0.85rem; color: var(--text-muted); display: block;">วันที่ประกาศเผยแพร่แผนจัดซื้อจัดจ้างประจำปี:</span>';
        echo '    <strong style="font-size: 1.05rem; color: var(--primary-dark);">' . get_thai_date($plan_info['announce_date']) . '</strong>';
        echo '  </div>';
        echo '  <div>';
        echo '    <a href="' . htmlspecialchars($plan_info['file_path']) . '" target="_blank" class="btn btn-secondary" style="padding: 8px 16px; font-size: 0.85rem; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; border-radius: 4px;">';
        echo '      <svg style="width:16px;height:16px;fill:currentColor;vertical-align:middle;" viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg>';
        echo '      ดาวน์โหลดแผนจัดซื้อหลัก (PDF)';
        echo '    </a>';
        echo '  </div>';
        echo '</div>';
    }

    $stmt = $pdo->prepare("SELECT * FROM projects WHERE plan_id = ? ORDER BY id DESC");
    $stmt->execute([$plan_id]);
    $projs = $stmt->fetchAll();
    
    if (empty($projs)) {
        echo "<div style='text-align:center; padding:30px; color:var(--text-muted);'>ไม่มีข้อมูลโครงการในแผนนี้</div>";
        exit;
    }
    
    echo '<table class="data-table" style="width:100%;">';
    echo '<thead><tr><th style="width:5%; text-align:center;">#</th><th style="width:50%;">ชื่อโครงการ</th><th style="width:15%; text-align:right;">งบประมาณ (บาท)</th><th style="width:30%;">สถานะภาพรวม</th></tr></thead>';
    echo '<tbody>';
    $idx = 1;
    foreach ($projs as $p) {
        $prog = get_project_tracking_progress($pdo, $p);
        $pct = $prog['progress_pct'];
        $step = $prog['latest_completed_step'];
        
        echo '<tr>';
        echo '<td style="text-align:center; vertical-align:middle;">' . $idx++ . '</td>';
        echo '<td style="vertical-align:middle;"><strong style="color:var(--primary-dark);">' . htmlspecialchars($p['project_name']) . '</strong><br><span style="font-size:0.8rem; color:var(--text-muted);">วิธี: ' . htmlspecialchars($p['procurement_method']) . (!empty($p['responsible_person']) ? ' | ผู้รับผิดชอบ: ' . htmlspecialchars($p['responsible_person']) : '') . '</span></td>';
        echo '<td style="text-align:right; vertical-align:middle; font-weight:600;">' . number_format($p['budget'], 2) . '</td>';
        echo '<td style="vertical-align:middle;">';
        echo '<div style="width: 100%; background: #e2e8f0; border-radius: 10px; height: 8px; margin-bottom: 6px; overflow:hidden;">';
        echo '<div style="height: 10px; border-radius: 10px; background: ' . ($pct == 100 ? 'var(--success)' : 'var(--secondary)') . '; width: ' . $pct . '%;"></div>';
        echo '</div>';
        echo '<span style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">' . $pct . '% - ' . $step . '</span>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    exit;
}

// User Management: Delete User
if (isset($_GET['action']) && $_GET['action'] === 'delete_user') {
    if (!in_array($_SESSION['admin_role'] ?? '', ['superadmin', 'admin'])) die("Permission denied.");
    $user_id = intval($_GET['id']);
    
    if ($user_id === $_SESSION['admin_user_id']) {
        $_SESSION['error_flash'] = 'ไม่สามารถลบบัญชีของตนเองที่กำลังใช้งานอยู่ได้';
    } else {
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $_SESSION['success_flash'] = 'ลบผู้ใช้งานสำเร็จแล้ว';
    }
    header('Location: admin.php?view=users');
    exit();
}

// 2. Delete Annual Plan
if (isset($_GET['action']) && $_GET['action'] === 'delete_plan') {
    $plan_id = intval($_GET['id']);
    
    try {
        // Fetch and delete the plan PDF file
        $plan_file_stmt = $pdo->prepare("SELECT file_path FROM plans WHERE id = ?");
        $plan_file_stmt->execute([$plan_id]);
        $plan_file = $plan_file_stmt->fetchColumn();
        if ($plan_file && file_exists($plan_file)) {
            @unlink($plan_file);
        }
        
        // Fetch and delete all announcement PDFs under projects of this plan
        $ann_files_stmt = $pdo->prepare("SELECT a.file_path FROM announcements a JOIN projects p ON a.project_id = p.id WHERE p.plan_id = ?");
        $ann_files_stmt->execute([$plan_id]);
        $ann_files = $ann_files_stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ann_files as $file) {
            if ($file && file_exists($file)) {
                @unlink($file);
            }
        }
        
        // Delete plan (cascades database-wise to projects and announcements)
        $del_stmt = $pdo->prepare("DELETE FROM plans WHERE id = ?");
        $del_stmt->execute([$plan_id]);
        
        $_SESSION['success_flash'] = 'ลบแผนการจัดซื้อจัดจ้างประจำปี โครงการภายใต้แผน และไฟล์เอกสารที่เกี่ยวข้องทั้งหมดสำเร็จแล้ว';
        header('Location: admin.php?view=plans');
        exit();
    } catch (Exception $e) {
        $error_msg = 'ไม่สามารถลบแผนจัดซื้อได้: ' . $e->getMessage();
    }
}

// 3. Delete Project
if (isset($_GET['action']) && $_GET['action'] === 'delete_project') {
    $project_id = intval($_GET['id']);
    
    try {
        // Fetch all announcements to delete their PDF files first
        $ann_stmt = $pdo->prepare("SELECT file_path FROM announcements WHERE project_id = ?");
        $ann_stmt->execute([$project_id]);
        $files = $ann_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($files as $file) {
            if (!empty($file) && file_exists($file)) {
                @unlink($file);
            }
        }
        
        // Delete project from database (cascades in db)
        $del_stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
        $del_stmt->execute([$project_id]);
        
        $_SESSION['success_flash'] = 'ลบโครงการจัดซื้อและเอกสารประกอบสำเร็จเรียบร้อยแล้ว';
        $ref = isset($_GET['ref']) ? $_GET['ref'] : 'projects';
        $ref_plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
        if ($ref === 'plan_detail' && $ref_plan_id > 0) {
            header('Location: admin.php?view=plan_detail&id=' . $ref_plan_id);
        } else {
            header('Location: admin.php?view=projects');
        }
        exit();
    } catch (Exception $e) {
        $error_msg = 'ไม่สามารถลบโครงการได้: ' . $e->getMessage();
    }
}

// 4. Delete Announcement
if (isset($_GET['action']) && $_GET['action'] === 'delete_announcement') {
    $ann_id = intval($_GET['id']);
    $ret_project_id = intval($_GET['project_id']);
    
    try {
        // Get file path to delete from disk
        $stmt = $pdo->prepare("SELECT file_path FROM announcements WHERE id = ?");
        $stmt->execute([$ann_id]);
        $file = $stmt->fetchColumn();
        
        if ($file && file_exists($file)) {
            @unlink($file);
        }
        
        // Delete from database
        $del_stmt = $pdo->prepare("DELETE FROM announcements WHERE id = ?");
        $del_stmt->execute([$ann_id]);
        
        // Re-evaluate project status
        refresh_project_status($pdo, $ret_project_id);
        
        $_SESSION['success_flash'] = 'ลบประกาศและไฟล์เอกสารประกอบสำเร็จแล้ว';
        header("Location: admin.php?view_project=" . $ret_project_id);
        exit();
    } catch (Exception $e) {
        $error_msg = 'ไม่สามารถลบประกาศย่อยได้: ' . $e->getMessage();
    }
}

// Handle flash messages
if (isset($_SESSION['success_flash'])) {
    $success_msg = $_SESSION['success_flash'];
    unset($_SESSION['success_flash']);
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    // User Management: Create User
    if ($action === 'create_user') {
        if (!in_array($_SESSION['admin_role'] ?? '', ['superadmin', 'admin'])) die("Permission denied.");
        $u_username = trim($_POST['username'] ?? '');
        $u_name = trim($_POST['name'] ?? '');
        $u_pass = $_POST['password'] ?? '';
        $u_role = $_POST['role'] ?? 'admin';
        
        if ($u_username === '' || $u_name === '' || !in_array($u_role, ['superadmin', 'admin', 'executive'])) {
            $error_msg = 'กรุณากรอกข้อมูลผู้ใช้งานให้ครบถ้วนและถูกต้อง';
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$u_username]);
            if ($stmt->fetchColumn() > 0) {
                $error_msg = 'ชื่อผู้ใช้งาน (Username) นี้มีในระบบแล้ว';
            } else {
                $hashed = password_hash($u_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password, name, role) VALUES (?, ?, ?, ?)");
                $stmt->execute([$u_username, $hashed, $u_name, $u_role]);
                $_SESSION['success_flash'] = 'สร้างผู้ใช้งานใหม่เรียบร้อยแล้ว';
                header('Location: admin.php?view=users'); 
                exit();
            }
        }
    }
    
    // User Management: Edit User
    if ($action === 'edit_user') {
        if (!in_array($_SESSION['admin_role'] ?? '', ['superadmin', 'admin'])) die("Permission denied.");
        $u_id = intval($_POST['user_id'] ?? 0);
        $u_name = trim($_POST['name'] ?? '');
        $u_pass = $_POST['password'] ?? ''; // Optional
        $u_role = $_POST['role'] ?? 'admin';
        
        if ($u_id <= 0 || $u_name === '' || !in_array($u_role, ['superadmin', 'admin', 'executive'])) {
            $error_msg = 'ข้อมูลไม่ถูกต้อง';
        } else {
            if ($u_pass !== '') {
                $hashed = password_hash($u_pass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name=?, role=?, password=? WHERE id=?");
                $stmt->execute([$u_name, $u_role, $hashed, $u_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET name=?, role=? WHERE id=?");
                $stmt->execute([$u_name, $u_role, $u_id]);
            }
            // Update session if editing self
            if ($u_id === $_SESSION['admin_user_id']) {
                $_SESSION['admin_name'] = $u_name;
                $_SESSION['admin_role'] = $u_role;
            }
            $_SESSION['success_flash'] = 'อัปเดตข้อมูลผู้ใช้งานเรียบร้อยแล้ว';
            header('Location: admin.php?view=users'); 
            exit();
        }
    }

    // A. Create Annual Plan
    if ($action === 'create_plan') {
        $name = trim($_POST['plan_name']);
        $year = intval($_POST['fiscal_year']);
        $budget_source = trim($_POST['budget_source']);
        $date = trim($_POST['announce_date']);
        
        if ($name === '' || $year <= 0 || $date === '' || $budget_source === '') {
            $error_msg = 'กรุณากรอกข้อมูลแผนจัดซื้อประจำปีให้ครบถ้วน';
        } elseif (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = 'กรุณาอัปโหลดไฟล์แผนจัดซื้อจัดจ้างฉบับจริง (ไฟล์ PDF)';
        } else {
            $file_info = $_FILES['pdf_file'];
            $ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($file_info['tmp_name']);
            
            if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                $error_msg = 'ความปลอดภัย: อนุญาตให้อัปโหลดเฉพาะไฟล์เอกสารประเภท PDF เท่านั้น';
            } elseif ($file_info['size'] > 15 * 1024 * 1024) { // 15MB limit
                $error_msg = 'ไฟล์มีขนาดใหญ่เกินกว่า 15MB';
            } else {
                $dest_dir = 'uploads/';
                $new_filename = 'plan_' . $year . '_' . time() . '_' . uniqid() . '.pdf';
                $dest_path = $dest_dir . $new_filename;
                
                if (move_uploaded_file($file_info['tmp_name'], $dest_path)) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO plans (plan_name, fiscal_year, budget_source, announce_date, file_path) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $year, $budget_source, $date, $dest_path]);
                        $success_msg = 'สร้างแผนการจัดซื้อจัดจ้างประจำปีและอัปโหลดไฟล์เรียบร้อยแล้ว';
                    } catch (Exception $e) {
                        @unlink($dest_path);
                        $error_msg = 'เกิดข้อผิดพลาดในการบันทึกแผนงาน: ' . $e->getMessage();
                    }
                } else {
                    $error_msg = 'ไม่สามารถย้ายไฟล์ไปยังโฟลเดอร์ uploads ได้';
                }
            }
        }
    }
    
    // A.5 Edit Annual Plan
    if ($action === 'edit_plan') {
        $plan_id = intval($_POST['plan_id']);
        $name = trim($_POST['plan_name']);
        $year = intval($_POST['fiscal_year']);
        $budget_source = trim($_POST['budget_source']);
        $date = trim($_POST['announce_date']);
        
        if ($plan_id <= 0 || $name === '' || $year <= 0 || $date === '' || $budget_source === '') {
            $error_msg = 'กรุณากรอกข้อมูลแผนจัดซื้อประจำปีให้ครบถ้วน';
        } else {
            try {
                if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
                    $file_info = $_FILES['pdf_file'];
                    $ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
                    $mime = mime_content_type($file_info['tmp_name']);
                    if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                        $error_msg = 'ความปลอดภัย: อนุญาตให้อัปโหลดเฉพาะไฟล์เอกสารประเภท PDF เท่านั้น';
                    } elseif ($file_info['size'] > 15 * 1024 * 1024) {
                        $error_msg = 'ไฟล์มีขนาดใหญ่เกินกว่า 15MB';
                    } else {
                        $dest_dir = 'uploads/';
                        $new_filename = 'plan_' . $year . '_' . time() . '_' . uniqid() . '.pdf';
                        $dest_path = $dest_dir . $new_filename;
                        if (move_uploaded_file($file_info['tmp_name'], $dest_path)) {
                            // Get old file to delete
                            $stmt = $pdo->prepare("SELECT file_path FROM plans WHERE id = ?");
                            $stmt->execute([$plan_id]);
                            $old_file = $stmt->fetchColumn();
                            if ($old_file && file_exists($old_file)) @unlink($old_file);
                            
                            $stmt = $pdo->prepare("UPDATE plans SET plan_name = ?, fiscal_year = ?, budget_source = ?, announce_date = ?, file_path = ? WHERE id = ?");
                            $stmt->execute([$name, $year, $budget_source, $date, $dest_path, $plan_id]);
                            $success_msg = 'แก้ไขแผนงานและอัปโหลดไฟล์ใหม่เรียบร้อยแล้ว';
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE plans SET plan_name = ?, fiscal_year = ?, budget_source = ?, announce_date = ? WHERE id = ?");
                    $stmt->execute([$name, $year, $budget_source, $date, $plan_id]);
                    $success_msg = 'แก้ไขข้อมูลแผนงานเรียบร้อยแล้ว';
                }
            } catch (Exception $e) {
                $error_msg = 'เกิดข้อผิดพลาดในการแก้ไขแผนงาน: ' . $e->getMessage();
            }
        }
    }
    
    // B. Create Project
    if ($action === 'create_project') {
        $name = trim($_POST['project_name']);
        $budget = floatval($_POST['budget']);
        $plan_id = intval($_POST['plan_id']);
        $procurement_type = isset($_POST['procurement_type']) ? trim($_POST['procurement_type']) : '';
        $quantity = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';
        $required_date = isset($_POST['required_date']) ? trim($_POST['required_date']) : '';
        $procurement_method = isset($_POST['procurement_method']) ? trim($_POST['procurement_method']) : '';
        $request_month = isset($_POST['request_month']) ? trim($_POST['request_month']) : '';
        $contract_month = isset($_POST['contract_month']) ? trim($_POST['contract_month']) : '';
        $responsible_person = isset($_POST['responsible_person']) ? trim($_POST['responsible_person']) : '';
        
        if ($name === '' || $budget <= 0 || $plan_id <= 0 || $procurement_type === '' || $quantity === '' || $procurement_method === '') {
            $error_msg = 'กรุณากรอกข้อมูลโครงการให้ครบถ้วน';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO projects (plan_id, project_name, budget, procurement_type, quantity, required_date, procurement_method, request_month, contract_month, responsible_person, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'planning')");
                $stmt->execute([$plan_id, $name, $budget, $procurement_type, $quantity, $required_date, $procurement_method, $request_month, $contract_month, $responsible_person]);
                $success_msg = 'สร้างโครงการจัดซื้อจัดจ้างภายใต้แผนสำเร็จเรียบร้อยแล้ว';
            } catch (Exception $e) {
                $error_msg = 'เกิดข้อผิดพลาดในการบันทึกโครงการ: ' . $e->getMessage();
            }
        }
    }
    
    // C. Edit Project
    if ($action === 'edit_project') {
        $project_id = intval($_POST['project_id']);
        $name = trim($_POST['project_name']);
        $budget = floatval($_POST['budget']);
        $plan_id = intval($_POST['plan_id']);
        $procurement_type = isset($_POST['procurement_type']) ? trim($_POST['procurement_type']) : '';
        $quantity = isset($_POST['quantity']) ? trim($_POST['quantity']) : '';
        $required_date = isset($_POST['required_date']) ? trim($_POST['required_date']) : '';
        $procurement_method = isset($_POST['procurement_method']) ? trim($_POST['procurement_method']) : '';
        $request_month = isset($_POST['request_month']) ? trim($_POST['request_month']) : '';
        $contract_month = isset($_POST['contract_month']) ? trim($_POST['contract_month']) : '';
        $responsible_person = isset($_POST['responsible_person']) ? trim($_POST['responsible_person']) : '';
        
        if ($name === '' || $budget <= 0 || $plan_id <= 0 || $procurement_type === '' || $quantity === '' || $procurement_method === '') {
            $error_msg = 'กรุณากรอกข้อมูลให้ครบถ้วนถูกต้อง';
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE projects SET plan_id = ?, project_name = ?, budget = ?, procurement_type = ?, quantity = ?, required_date = ?, procurement_method = ?, request_month = ?, contract_month = ?, responsible_person = ? WHERE id = ?");
                $stmt->execute([$plan_id, $name, $budget, $procurement_type, $quantity, $required_date, $procurement_method, $request_month, $contract_month, $responsible_person, $project_id]);
                $success_msg = 'แก้ไขรายละเอียดโครงการสำเร็จเรียบร้อยแล้ว';
            } catch (Exception $e) {
                $error_msg = 'เกิดข้อผิดพลาดในการแก้ไขโครงการ: ' . $e->getMessage();
            }
        }
    }
    
    // D. Add Announcement (Upload PDF for steps 2 to 5)
    if ($action === 'add_announcement') {
        $project_id = intval($_POST['project_id']);
        $category_id = intval($_POST['category_id']);
        $title = trim($_POST['title']);
        $announce_date = trim($_POST['announce_date']);
        $notes = trim($_POST['notes']);
        
        if ($title === '' || $announce_date === '' || $category_id < 2 || $category_id > 5) {
            $error_msg = 'กรุณากรอกข้อมูลให้ครบถ้วนและเลือกขั้นตอนที่ถูกต้อง (ขั้นตอน 2-5)';
        } elseif (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $error_msg = 'กรุณาอัปโหลดไฟล์เอกสารประกอบ (ไฟล์ PDF เท่านั้น)';
        } else {
            $file_info = $_FILES['pdf_file'];
            $ext = strtolower(pathinfo($file_info['name'], PATHINFO_EXTENSION));
            $mime = mime_content_type($file_info['tmp_name']);
            
            if ($ext !== 'pdf' || $mime !== 'application/pdf') {
                $error_msg = 'ความปลอดภัย: อนุญาตให้อัปโหลดเฉพาะไฟล์เอกสารประเภท PDF (.pdf) เท่านั้น';
            } elseif ($file_info['size'] > 10 * 1024 * 1024) {
                $error_msg = 'ไฟล์ประกาศมีขนาดใหญ่เกินกว่า 10MB';
            } else {
                $dest_dir = 'uploads/';
                $new_filename = 'proj_' . $project_id . '_cat_' . $category_id . '_' . time() . '_' . uniqid() . '.pdf';
                $dest_path = $dest_dir . $new_filename;
                
                if (move_uploaded_file($file_info['tmp_name'], $dest_path)) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO announcements (project_id, category_id, title, announce_date, file_path, notes) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$project_id, $category_id, $title, $announce_date, $dest_path, $notes]);
                        
                        // Automatically update project status
                        refresh_project_status($pdo, $project_id);
                        
                        $success_msg = 'อัปโหลดประกาศและบันทึกข้อมูลเรียบร้อยแล้ว';
                    } catch (Exception $e) {
                        @unlink($dest_path);
                        $error_msg = 'เกิดข้อผิดพลาดในการบันทึกประกาศย่อย: ' . $e->getMessage();
                    }
                } else {
                    $error_msg = 'ไม่สามารถบันทึกไฟล์ได้ กรุณาตรวจสอบสิทธิ์การเขียนไฟล์ของโฟลเดอร์ uploads บนเซิร์ฟเวอร์';
                }
            }
        }
    }
    
    // E. Change Admin Password
    if ($action === 'change_password') {
        $old_pass = trim($_POST['old_password']);
        $new_pass = trim($_POST['new_password']);
        $confirm_pass = trim($_POST['confirm_password']);
        
        if ($old_pass === '' || $new_pass === '' || $confirm_pass === '') {
            $error_msg = 'กรุณากรอกข้อมูลรหัสผ่านให้ครบทุกช่อง';
        } elseif ($new_pass !== $confirm_pass) {
            $error_msg = 'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน';
        } else {
            try {
                $stmt = $pdo->prepare("SELECT * stroke FROM users WHERE id = ?"); // Wait, type error, let's select * from users instead of SELECT * stroke
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['admin_user_id']]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($old_pass, $user['password'])) {
                    $new_hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                    $up_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $up_stmt->execute([$new_hashed, $_SESSION['admin_user_id']]);
                    $success_msg = 'เปลี่ยนรหัสผ่านผู้ดูแลระบบสำเร็จเรียบร้อยแล้ว';
                } else {
                    $error_msg = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                }
            } catch (Exception $e) {
                $error_msg = 'เกิดข้อผิดพลาดในการบันทึกรหัสผ่านใหม่: ' . $e->getMessage();
            }
        }
    }
    
    // F. Update Tracking Steps (2,3,4,7,9)
    if ($action === 'update_tracking') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $step2_status = $_POST['step2_status'] ?? 'pending';
        $step2_date = !empty($_POST['step2_date']) ? $_POST['step2_date'] : null;
        $step3_status = $_POST['step3_status'] ?? 'pending';
        $step3_date = !empty($_POST['step3_date']) ? $_POST['step3_date'] : null;
        $step4_status = $_POST['step4_status'] ?? 'pending';
        $step4_date = !empty($_POST['step4_date']) ? $_POST['step4_date'] : null;
        $step7_status = $_POST['step7_status'] ?? 'pending';
        $step7_date = !empty($_POST['step7_date']) ? $_POST['step7_date'] : null;
        $step9_status = $_POST['step9_status'] ?? 'pending';
        $step9_date = !empty($_POST['step9_date']) ? $_POST['step9_date'] : null;
        
        $spec_step2_status = $_POST['spec_step2_status'] ?? 'pending';
        $spec_step2_date = !empty($_POST['spec_step2_date']) ? $_POST['spec_step2_date'] : null;
        $spec_step2b_status = $_POST['spec_step2b_status'] ?? 'pending';
        $spec_step2b_date = !empty($_POST['spec_step2b_date']) ? $_POST['spec_step2b_date'] : null;
        $spec_step3_status = $_POST['spec_step3_status'] ?? 'pending';
        $spec_step3_date = !empty($_POST['spec_step3_date']) ? $_POST['spec_step3_date'] : null;
        $spec_step4_status = $_POST['spec_step4_status'] ?? 'pending';
        $spec_step4_date = !empty($_POST['spec_step4_date']) ? $_POST['spec_step4_date'] : null;

        $check = $pdo->prepare("SELECT COUNT(*) FROM project_tracking WHERE project_id = ?");
        $check->execute([$project_id]);
        if ($check->fetchColumn() > 0) {
            $update = $pdo->prepare("UPDATE project_tracking SET 
                step2_status=?, step2_date=?, step3_status=?, step3_date=?, step4_status=?, step4_date=?, step7_status=?, step7_date=?, step9_status=?, step9_date=?,
                spec_step2_status=?, spec_step2_date=?, spec_step2b_status=?, spec_step2b_date=?, spec_step3_status=?, spec_step3_date=?, spec_step4_status=?, spec_step4_date=?
                WHERE project_id=?");
            $update->execute([$step2_status, $step2_date, $step3_status, $step3_date, $step4_status, $step4_date, $step7_status, $step7_date, $step9_status, $step9_date, $spec_step2_status, $spec_step2_date, $spec_step2b_status, $spec_step2b_date, $spec_step3_status, $spec_step3_date, $spec_step4_status, $spec_step4_date, $project_id]);
        } else {
            $insert = $pdo->prepare("INSERT INTO project_tracking (project_id, step2_status, step2_date, step3_status, step3_date, step4_status, step4_date, step7_status, step7_date, step9_status, step9_date, spec_step2_status, spec_step2_date, spec_step2b_status, spec_step2b_date, spec_step3_status, spec_step3_date, spec_step4_status, spec_step4_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert->execute([$project_id, $step2_status, $step2_date, $step3_status, $step3_date, $step4_status, $step4_date, $step7_status, $step7_date, $step9_status, $step9_date, $spec_step2_status, $spec_step2_date, $spec_step2b_status, $spec_step2b_date, $spec_step3_status, $spec_step3_date, $spec_step4_status, $spec_step4_date]);
        }
        
        // Update multiple contracts status
        if (isset($_POST['contract_status']) && is_array($_POST['contract_status'])) {
            $upd_c = $pdo->prepare("UPDATE project_contracts SET contract_status=?, contract_date=? WHERE id=? AND project_id=?");
            foreach ($_POST['contract_status'] as $cid => $cstatus) {
                $cdate = !empty($_POST['contract_date'][$cid]) ? $_POST['contract_date'][$cid] : null;
                $upd_c->execute([$cstatus, $cdate, $cid, $project_id]);
            }
        }
        
        $_SESSION['success_flash'] = 'อัปเดตข้อมูลการติดตามสถานะสำเร็จแล้ว';
        header('Location: admin.php?view=tracking_detail&id=' . $project_id);
        exit();
    }
    
    // G. Add Installment
    if ($action === 'add_installment') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $contract_id = !empty($_POST['contract_id']) ? intval($_POST['contract_id']) : null;
        $name = trim($_POST['installment_name'] ?? '');
        
        if ($project_id > 0 && $name !== '') {
            $stmt = $pdo->prepare("INSERT INTO project_installments (project_id, contract_id, installment_name) VALUES (?, ?, ?)");
            $stmt->execute([$project_id, $contract_id, $name]);
            $_SESSION['success_flash'] = 'เพิ่มงวดงานสำเร็จแล้ว';
        } else {
            $_SESSION['error_flash'] = 'กรุณากรอกชื่อ/หมายเลขงวดงาน';
        }
        header('Location: admin.php?view=tracking_detail&id=' . $project_id);
        exit();
    }
    
    // H. Update Installment
    if ($action === 'update_installment') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $inst_id = intval($_POST['installment_id'] ?? 0);
        $d_status = $_POST['delivery_status'] ?? 'pending';
        $d_date = !empty($_POST['delivery_date']) ? $_POST['delivery_date'] : null;
        $i_status = $_POST['inspection_status'] ?? 'pending';
        $i_date = !empty($_POST['inspection_date']) ? $_POST['inspection_date'] : null;
        $p_status = $_POST['payment_status'] ?? 'pending';
        $p_date = !empty($_POST['payment_date']) ? $_POST['payment_date'] : null;
        
        $stmt = $pdo->prepare("UPDATE project_installments SET delivery_status=?, delivery_date=?, inspection_status=?, inspection_date=?, payment_status=?, payment_date=? WHERE id=? AND project_id=?");
        $stmt->execute([$d_status, $d_date, $i_status, $i_date, $p_status, $p_date, $inst_id, $project_id]);
        
        $_SESSION['success_flash'] = 'อัปเดตข้อมูลรายงวดสำเร็จแล้ว';
        header('Location: admin.php?view=tracking_detail&id=' . $project_id);
        exit();
    }
    
    // Add Contract
    if ($action === 'add_contract') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $name = trim($_POST['company_name'] ?? '');
        
        if ($project_id > 0 && $name !== '') {
            $stmt = $pdo->prepare("INSERT INTO project_contracts (project_id, company_name) VALUES (?, ?)");
            $stmt->execute([$project_id, $name]);
            $_SESSION['success_flash'] = 'เพิ่มบริษัทผู้ชนะสำเร็จแล้ว';
        } else {
            $_SESSION['error_flash'] = 'กรุณากรอกชื่อบริษัท / ผู้รับจ้าง';
        }
        header('Location: admin.php?view=tracking_detail&id=' . $project_id);
        exit();
    }
    
    // Update Contract
    if ($action === 'update_contract') {
        $project_id = intval($_POST['project_id'] ?? 0);
        $contract_id = intval($_POST['contract_id'] ?? 0);
        $c_status = $_POST['contract_status'] ?? 'pending';
        $c_date = !empty($_POST['contract_date']) ? $_POST['contract_date'] : null;
        
        $stmt = $pdo->prepare("UPDATE project_contracts SET contract_status=?, contract_date=? WHERE id=? AND project_id=?");
        $stmt->execute([$c_status, $c_date, $contract_id, $project_id]);
        
        $_SESSION['success_flash'] = 'อัปเดตสถานะการทำสัญญาสำเร็จแล้ว';
        header('Location: admin.php?view=tracking_detail&id=' . $project_id);
        exit();
    }
}

// I. Delete Installment (GET action outside POST block)
if (isset($_GET['action']) && $_GET['action'] === 'delete_installment') {
    $inst_id = intval($_GET['id'] ?? 0);
    $project_id = intval($_GET['project_id'] ?? 0);
    if ($inst_id > 0 && $project_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM project_installments WHERE id = ? AND project_id = ?");
        $stmt->execute([$inst_id, $project_id]);
        $_SESSION['success_flash'] = 'ลบงวดงานสำเร็จแล้ว';
    }
    header('Location: admin.php?view=tracking_detail&id=' . $project_id);
    exit();
}

// Delete Contract (GET action)
if (isset($_GET['action']) && $_GET['action'] === 'delete_contract') {
    $contract_id = intval($_GET['id'] ?? 0);
    $project_id = intval($_GET['project_id'] ?? 0);
    if ($contract_id > 0 && $project_id > 0) {
        $stmt = $pdo->prepare("DELETE FROM project_contracts WHERE id = ? AND project_id = ?");
        $stmt->execute([$contract_id, $project_id]);
        $_SESSION['success_flash'] = 'ลบรายชื่อบริษัทสำเร็จแล้ว';
    }
    header('Location: admin.php?view=tracking_detail&id=' . $project_id);
    exit();
}

// ----------------------------------------------------
// READ DATA FOR UI
// ----------------------------------------------------
$admin_role = $_SESSION['admin_role'] ?? 'admin';
$view = isset($_GET['view']) ? $_GET['view'] : ($admin_role === 'executive' ? 'report' : 'projects');
if ($admin_role === 'executive' && !in_array($view, ['report', 'password', 'tracking'])) {
    $view = 'report';
}
$view_project_id = isset($_GET['view_project']) ? intval($_GET['view_project']) : 0;

// Get distinct fiscal years for filter
$years_stmt = $pdo->query("SELECT DISTINCT fiscal_year FROM plans ORDER BY fiscal_year DESC");
$admin_fiscal_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

$selected_year = isset($_GET['year']) ? intval($_GET['year']) : 0;
if ($selected_year == 0 && !empty($admin_fiscal_years)) {
    $selected_year = intval($admin_fiscal_years[0]);
}

// Fetch all annual plans (Filtered by selected year)
$plans_query = "SELECT * FROM plans WHERE 1=1";
$plans_params = [];
if ($selected_year > 0) {
    $plans_query .= " AND fiscal_year = ?";
    $plans_params[] = $selected_year;
}
$plans_query .= " ORDER BY fiscal_year DESC, id DESC";
$plans_stmt = $pdo->prepare($plans_query);
$plans_stmt->execute($plans_params);
$all_plans = $plans_stmt->fetchAll();

// If viewing a specific project to manage steps 2-5
$project_data = null;
$project_announcements = [];
$parent_plan = null;
if ($view_project_id > 0) {
    $proj_stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $proj_stmt->execute([$view_project_id]);
    $project_data = $proj_stmt->fetch();
    
    if ($project_data) {
        $ann_stmt = $pdo->prepare("SELECT * FROM announcements WHERE project_id = ? ORDER BY category_id ASC");
        $ann_stmt->execute([$view_project_id]);
        $project_announcements = $ann_stmt->fetchAll();
        
        $plan_stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
        $plan_stmt->execute([$project_data['plan_id']]);
        $parent_plan = $plan_stmt->fetch();
    } else {
        $view_project_id = 0;
    }
}

// Fetch all projects for the dashboard listing (Filtered by selected year and optionally plan_id)
$projects_query = "SELECT p.*, pl.plan_name, pl.fiscal_year, pl.budget_source FROM projects p JOIN plans pl ON p.plan_id = pl.id WHERE 1=1";
$projects_params = [];
if ($selected_year > 0) {
    $projects_query .= " AND pl.fiscal_year = ?";
    $projects_params[] = $selected_year;
}
$filter_plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;
if ($filter_plan_id > 0) {
    $projects_query .= " AND p.plan_id = ?";
    $projects_params[] = $filter_plan_id;
}
$projects_query .= " ORDER BY p.id DESC";
$projects_stmt = $pdo->prepare($projects_query);
$projects_stmt->execute($projects_params);
$all_projects = $projects_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ผู้ดูแลระบบ - ระบบประกาศจัดซื้อจัดจ้าง คณะวิทยาศาสตร์การแพทย์</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="admin-layout">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div>
                <div class="sidebar-brand">
                    <svg style="width:36px;height:36px;fill:#bca256;" viewBox="0 0 100 100">
                        <path d="M50 10 L85 30 L85 70 L50 90 L15 70 L15 30 Z" fill="none" stroke="#bca256" stroke-width="6"/>
                        <path d="M50 20 L75 35 L75 65 L50 80 L25 65 L25 35 Z" fill="#ffffff"/>
                    </svg>
                    <h2>ระบบหลังบ้านพัสดุ</h2>
                </div>
                <ul class="sidebar-menu">
                    <li class="<?= ($view === 'report') ? 'active' : '' ?>">
                        <a href="admin.php?view=report&year=<?= $selected_year ?>">
                            <svg style="width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round" viewBox="0 0 24 24">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="3" y1="9" x2="21" y2="9"></line>
                                <line x1="9" y1="21" x2="9" y2="9"></line>
                            </svg>
                            รายงานสำหรับผู้บริหาร
                        </a>
                    </li>
                    
                    <?php if ($admin_role !== 'executive'): ?>
                        <li class="<?= ($view === 'plans' || $view === 'plan_detail') ? 'active' : '' ?>">
                            <a href="admin.php?view=plans&year=<?= $selected_year ?>">
                                <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                    <path d="M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H8V4h12v12z"/>
                                </svg>
                                จัดการแผนจัดซื้อจัดจ้างประจำปี
                            </a>
                        </li>
                        <li class="<?= ($view === 'tracking' || $view === 'tracking_detail') ? 'active' : '' ?>">
                            <a href="admin.php?view=tracking&year=<?= $selected_year ?>">
                                <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                </svg>
                                ติดตามสถานะโครงการ
                            </a>
                        </li>
                        <li class="<?= ($view === 'projects' && $view_project_id === 0) ? 'active' : '' ?>">
                            <a href="admin.php?view=projects&year=<?= $selected_year ?>">
                                <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                                </svg>
                                ประกาศ E-Bidding
                            </a>
                        </li>
                        <li class="<?= ($view === 'users') ? 'active' : '' ?>">
                            <a href="admin.php?view=users">
                                <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                    <path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/>
                                </svg>
                                ระบบจัดการผู้ใช้งาน
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <div class="sidebar-footer">
                <p style="margin-bottom: 8px; font-size: 0.9rem;">ผู้ใช้: <strong><?= htmlspecialchars($_SESSION['admin_name']) ?></strong></p>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <a href="admin.php?view=password" class="btn btn-secondary" style="width:100%; padding: 8px 12px; font-size: 0.85rem; text-align: left; display: flex; align-items: center; justify-content: center; gap: 6px;">
                        <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        เปลี่ยนรหัสผ่าน
                    </a>
                    <a href="admin.php?action=logout" class="btn btn-danger" style="width:100%; padding: 8px 12px; font-size: 0.85rem;">
                        ออกจากระบบ
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main Workspace Area -->
        <main class="admin-main">
            <!-- Top Header -->
            <header class="admin-header">
                <div>
                    <?php if ($view === 'users'): ?>
                        <h1>ระบบจัดการผู้ใช้งาน (User Management)</h1>
                        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 4px;">
                            เพิ่ม ลบ และแก้ไขสิทธิ์การเข้าใช้งานระบบของบุคลากร
                        </p>
                    <?php else: ?>
                        <h1>ระบบจัดการประกาศโครงการจัดซื้อจัดจ้าง</h1>
                        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 4px;">
                            แผงควบคุมหลักสำหรับเจ้าหน้าที่พัสดุ คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา
                        </p>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 16px; align-items: center;">
                    <?php if ($view !== 'users' && $view !== 'password'): ?>
                    <form method="GET" action="admin.php" style="display:flex; align-items:center; gap:8px;">
                        <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
                        <label for="admin_year_filter" style="font-weight:600; color:var(--text-main); font-size:0.9rem; white-space:nowrap;">ปีงบประมาณ:</label>
                        <select name="year" id="admin_year_filter" class="form-control" style="width: auto; padding: 6px 30px 6px 12px; height: 38px; min-width: 120px;" onchange="this.form.submit()">
                            <option value="0">แสดงทั้งหมด</option>
                            <?php foreach ($admin_fiscal_years as $y): ?>
                                <option value="<?= $y ?>" <?= $selected_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                    <a href="index.php" target="_blank" class="btn btn-secondary" style="white-space:nowrap; padding: 8px 16px; height: 38px;">
                        <svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24">
                            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                        </svg>
                        ดูหน้าเว็บหลัก (Public)
                    </a>
                </div>
            </header>

            <!-- Alerts -->
            <?php if ($success_msg !== ''): ?>
                <div class="alert-message alert-success"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg !== ''): ?>
                <div class="alert-message alert-danger"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <!-- View 1: Manage Annual Plans -->
            <?php if ($view === 'plans'): ?>
                <section class="card-table-wrap">
                    <div class="card-table-header">
                        <h2>รายการแผนจัดซื้อจัดจ้างประจำปี</h2>
                        <button type="button" class="btn btn-primary" onclick="showModal('add-plan-modal')">
                            + เพิ่มแผนจัดซื้อประจำปี
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th width="80px">ลำดับ</th>
                                    <th>ชื่อแผนงาน/ประกาศจัดซื้อประจำปี</th>
                                    <th width="150px">ปีงบประมาณ</th>
                                    <th width="180px">วันที่ประกาศเผยแพร่</th>
                                    <th width="150px">เอกสาร PDF</th>
                                    <th width="150px" style="text-align: center;">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_plans)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align:center; padding: 40px; color: var(--text-muted);">
                                            ยังไม่มีแผนการจัดซื้อจัดจ้างประจำปีในระบบ กรุณากด "เพิ่มแผนจัดซื้อประจำปี" เพื่อเริ่มต้น
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $i = 1; foreach ($all_plans as $plan): 
                                        $sub_proj_stmt = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE plan_id = ?");
                                        $sub_proj_stmt->execute([$plan['id']]);
                                        $project_count = $sub_proj_stmt->fetchColumn();
                                    ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td>
                                                <strong style="color: var(--primary-dark); font-size:0.95rem; display: block; margin-bottom: 4px;"><?= htmlspecialchars($plan['plan_name']) ?></strong>
                                                <div style="font-size: 0.85rem; color: #475569; margin-bottom: 4px;">
                                                    แหล่งงบประมาณ: <strong><?= !empty($plan['budget_source']) ? htmlspecialchars($plan['budget_source']) : '-' ?></strong>
                                                </div>
                                                <span style="font-size: 0.8rem; color: var(--text-muted);">
                                                    มีโครงการจัดซื้อภายใต้แผนนี้ทั้งหมด: <strong><?= $project_count ?></strong> โครงการ
                                                </span>
                                            </td>
                                            <td>พ.ศ. <?= $plan['fiscal_year'] ?></td>
                                            <td><?= get_thai_date($plan['announce_date']) ?></td>
                                            <td>
                                                <a href="<?= htmlspecialchars($plan['file_path']) ?>" target="_blank" style="font-weight:600; color: var(--secondary-dark); text-decoration: underline;">
                                                    ดูไฟล์แผนจัดซื้อ
                                                </a>
                                            </td>
                                            <td style="text-align: center;">
                                                <div style="display: flex; gap: 8px; justify-content: center; align-items: center;">
                                                    <a href="admin.php?view=plan_detail&id=<?= $plan['id'] ?>" class="btn btn-primary" style="padding: 6px 12px; font-size:0.8rem;">
                                                        ดูรายละเอียดโครงการ
                                                    </a>
                                                    <button type="button" class="btn btn-secondary btn-icon-only" title="แก้ไขข้อมูลแผน"
                                                            onclick="openEditPlan(<?= $plan['id'] ?>, <?= htmlspecialchars(json_encode($plan['plan_name'])) ?>, <?= $plan['fiscal_year'] ?>, <?= htmlspecialchars(json_encode($plan['budget_source'])) ?>, <?= htmlspecialchars(json_encode($plan['announce_date'])) ?>)">
                                                        <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
                                                        </svg>
                                                    </button>
                                                    <a href="admin.php?action=delete_plan&id=<?= $plan['id'] ?>" class="btn btn-danger btn-icon-only" title="ลบแผนจัดซื้อและโครงการทั้งหมดในแผน"
                                                       onclick="return confirm('คำเตือน! การลบแผนงานนี้จะลบโครงการย่อยทั้งหมดและประกาศย่อยภายใต้โครงการจัดซื้อนั้นออกจากฐานข้อมูลรวมถึงไฟล์ PDF บนเซิร์ฟเวอร์ด้วย แน่ใจใช่หรือไม่?')">
                                                        <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                                                        </svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- MODAL: Add Plan moved to global bottom section -->

            <!-- View 1.5: Detailed Plan & Projects List -->
            <?php elseif ($view === 'plan_detail' && isset($_GET['id'])): 
                $plan_id = intval($_GET['id']);
                $plan_stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ?");
                $plan_stmt->execute([$plan_id]);
                $plan_info = $plan_stmt->fetch();
                
                if ($plan_info):
                    $sub_proj_stmt = $pdo->prepare("SELECT * FROM projects WHERE plan_id = ? ORDER BY id DESC");
                    $sub_proj_stmt->execute([$plan_id]);
                    $sub_projects = $sub_proj_stmt->fetchAll();
                else:
                    header('Location: admin.php?view=plans');
                    exit();
                endif;
            ?>
                <div style="margin-bottom: 24px;">
                    <a href="admin.php?view=plans" class="btn btn-secondary" style="padding: 8px 16px;">
                        &larr; กลับไปยังรายการแผนจัดซื้อจัดจ้างประจำปี
                    </a>
                </div>

                <!-- Plan Info Card -->
                <section class="project-card" style="margin-bottom: 30px;">
                    <div class="project-header" style="background-color: #fafbfd; cursor: default; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                        <div class="project-title-area">
                            <span style="font-size: 0.8rem; font-weight:700; color: var(--secondary-dark); text-transform: uppercase;">แผนการจัดซื้อจัดจ้างประจำปีหลัก:</span>
                            <h2 style="margin-top: 4px;"><?= htmlspecialchars($plan_info['plan_name']) ?></h2>
                            <div class="project-meta" style="margin-top: 8px; display: flex; gap: 12px; flex-wrap: wrap;">
                                <span class="meta-badge year">ปีงบประมาณ: พ.ศ. <?= $plan_info['fiscal_year'] ?></span>
                                <span class="meta-badge" style="background-color: #f1f5f9; color: var(--text-muted);">
                                    วันที่เผยแพร่: <?= get_thai_date($plan_info['announce_date']) ?>
                                </span>
                                <a href="<?= htmlspecialchars($plan_info['file_path']) ?>" target="_blank" class="meta-badge" style="background: var(--success-glow); color: var(--success); text-decoration: underline; font-weight: 600;">
                                    ดูไฟล์ประกาศแผนจัดซื้อหลัก (PDF)
                                </a>
                            </div>
                        </div>
                        <button type="button" class="btn btn-primary" onclick="openAddProject(<?= $plan_info['id'] ?>)">
                            + เพิ่มโครงการภายใต้แผนนี้
                        </button>
                    </div>
                </section>

                <!-- Sub-projects Table -->
                <section class="card-table-wrap">
                    <div class="card-table-header">
                        <h2>โครงการจัดซื้อจัดจ้างภายใต้แผนนี้ (<?= count($sub_projects) ?> โครงการ)</h2>
                    </div>
                    <div class="table-responsive">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th width="50px">ลำดับ</th>
                                    <th>ชื่อโครงการจัดซื้อจัดจ้างย่อย</th>
                                    <th width="150px">งบประมาณ</th>
                                    <th width="130px">ประเภทการจัดหา</th>
                                    <th width="180px">วิธีจัดซื้อจัดจ้าง</th>
                                    <th width="140px">ช่วงเดือนขอซื้อ</th>
                                    <th width="100px" style="text-align: center;">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sub_projects)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align:center; padding: 40px; color: var(--text-muted);">
                                            ยังไม่มีโครงการย่อยภายใต้แผนนี้ ท่านสามารถกดปุ่ม "+ เพิ่มโครงการภายใต้แผนนี้" เพื่อสร้างโครงการย่อยได้
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $i = 1; foreach ($sub_projects as $sp): 
                                        $status_info = get_status_info($sp['status']);
                                        $time_status = get_procurement_time_status($status_info['step'], $sp['request_month'], $plan_info['fiscal_year']);
                                    ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td>
                                                <strong style="color: var(--primary-dark); font-size:0.95rem; display: block; margin-bottom: 8px;"><?= htmlspecialchars($sp['project_name']) ?></strong>
                                                <div style="font-size:0.8rem; color: var(--text-muted); display: flex; gap: 16px; flex-wrap: wrap; align-items: center;">
                                                    <span>ปริมาณ: <strong style="color:#475569;"><?= htmlspecialchars($sp['quantity']) ?></strong></span>
                                                    <span>กำหนดใช้: <strong style="color:#475569;"><?= format_month_year($sp['required_date']) ?></strong></span>
                                                    <span>ผู้รับผิดชอบ: <strong style="color:var(--primary);"><?= !empty($sp['responsible_person']) ? htmlspecialchars($sp['responsible_person']) : '-' ?></strong></span>
                                                </div>
                                            </td>
                                            <td style="font-weight: 600;"><?= number_format($sp['budget'], 2) ?> บาท</td>
                                            <td><?= htmlspecialchars($sp['procurement_type']) ?></td>
                                            <td><?= htmlspecialchars($sp['procurement_method']) ?></td>
                                            <td><?= format_month_year($sp['request_month']) ?></td>
                                            <td style="text-align: center;">
                                                <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                                                    <button type="button" class="btn btn-secondary btn-icon-only" title="แก้ไขโครงการ" 
                                                            onclick="openEditProject(<?= $sp['id'] ?>, <?= htmlspecialchars(json_encode($sp['project_name'])) ?>, <?= $sp['budget'] ?>, <?= $sp['plan_id'] ?>, <?= htmlspecialchars(json_encode($sp['procurement_type'])) ?>, <?= htmlspecialchars(json_encode($sp['quantity'])) ?>, <?= htmlspecialchars(json_encode($sp['required_date'])) ?>, <?= htmlspecialchars(json_encode($sp['procurement_method'])) ?>, <?= htmlspecialchars(json_encode($sp['request_month'])) ?>, <?= htmlspecialchars(json_encode($sp['contract_month'])) ?>, <?= htmlspecialchars(json_encode($sp['responsible_person'])) ?>)">
                                                        <svg style="width:14px;height:14px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
                                                        </svg>
                                                    </button>
                                                    <a href="admin.php?action=delete_project&id=<?= $sp['id'] ?>&ref=plan_detail&plan_id=<?= $plan_info['id'] ?>" class="btn btn-danger btn-icon-only" title="ลบโครงการนี้"
                                                       onclick="return confirm('คุณแน่ใจว่าต้องการลบโครงการจัดซื้อนี้และเอกสารประกาศประกอบทั้งหมด? ไฟล์ PDF บนเซิร์ฟเวอร์จะถูกลบออกทั้งหมดจริง')">
                                                        <svg style="width:14px;height:14px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                                                        </svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <!-- View 2: Manage Projects -->
            <?php elseif ($view === 'projects' && $view_project_id === 0): ?>
                <section class="card-table-wrap">
                    <div class="card-table-header">
                        <h2>รายการโครงการจัดซื้อจัดจ้างภายใต้แผนการจัดหาพัสดุ</h2>
                        <?php if (empty($all_plans)): ?>
                            <span style="color:var(--danger); font-weight:600; font-size:0.9rem;">กรุณาสร้างแผนการจัดซื้อประจำปีหลักก่อนสร้างโครงการ!</span>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary" onclick="showModal('add-project-modal')">
                                + สร้างโครงการจัดซื้อใหม่
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="table-responsive">
                        <?php 
                            $e_bidding_projects = array_filter($all_projects, function($p) use ($pdo) {
                                if (trim($p['procurement_method']) !== 'ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)') {
                                    return false;
                                }
                                // Only show if tracking status is at least ready for Step 5 (i.e., Step 4 completed)
                                $stmt = $pdo->prepare("SELECT step4_status FROM project_tracking WHERE project_id = ?");
                                $stmt->execute([$p['id']]);
                                $step4 = $stmt->fetchColumn();
                                return ($step4 === 'completed');
                            });
                        ?>
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th width="80px">ลำดับ</th>
                                    <th>ชื่อโครงการจัดซื้อจัดจ้าง</th>
                                    <th>แผนงานประจำปีที่ผูก</th>
                                    <th width="150px">งบประมาณ</th>
                                    <th width="320px" style="text-align: center;">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($e_bidding_projects)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align:center; padding: 40px; color: var(--text-muted); line-height: 1.6;">
                                            <div style="font-size: 1.1rem; color: var(--primary); margin-bottom: 8px;">ไม่พบโครงการ E-Bidding ที่พร้อมประกาศ</div>
                                            โครงการจัดซื้อแบบ E-Bidding จะแสดงในหน้านี้ได้ ก็ต่อเมื่อมีการ <a href="admin.php?view=tracking" style="color:var(--primary-dark); font-weight:600; text-decoration:underline;">อัปเดตสถานะ Tracking</a> จนเสร็จสิ้นขั้นตอนที่ 4 (แต่งตั้งคณะกรรมการ) เรียบร้อยแล้ว<br>เพื่อดำเนินการประกาศในขั้นตอนที่ 5 (ประกาศ e-Bidding) และขั้นตอนที่ 6 (ประกาศผลผู้ชนะ) ต่อไป
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php $i = 1; foreach ($e_bidding_projects as $proj): 
                                        $status_info = get_status_info($proj['status']);
                                        $time_status = get_procurement_time_status($status_info['step'], $proj['request_month'], $proj['fiscal_year']);
                                    ?>
                                        <tr>
                                            <td><?= $i++ ?></td>
                                            <td>
                                                <strong style="color: var(--primary-dark); font-size:0.95rem; display: block; margin-bottom: 8px;"><?= htmlspecialchars($proj['project_name']) ?></strong>
                                                <div style="font-size:0.8rem; color: var(--text-muted);">
                                                    ผู้รับผิดชอบ: <strong style="color:var(--primary);"><?= !empty($proj['responsible_person']) ? htmlspecialchars($proj['responsible_person']) : '-' ?></strong>
                                                </div>
                                            </td>
                                            <td style="font-size:0.85rem; color: var(--text-muted);">
                                                <?= htmlspecialchars($proj['plan_name']) ?> (พ.ศ. <?= $proj['fiscal_year'] ?>)
                                                <div style="margin-top: 4px; color: #475569;">
                                                    แหล่งงบประมาณ: <strong><?= !empty($proj['budget_source']) ? htmlspecialchars($proj['budget_source']) : '-' ?></strong>
                                                </div>
                                            </td>
                                            <td style="font-weight: 600;"><?= number_format($proj['budget'], 2) ?> บาท</td>
                                            <td style="text-align: center; vertical-align: middle;">
                                                <div style="display: flex; gap: 8px; justify-content: center; align-items: center; flex-wrap: nowrap;">
                                                    <a href="admin.php?view_project=<?= $proj['id'] ?>" class="btn btn-primary" title="จัดการประกาศ e-Bidding" style="padding: 6px 12px; font-size:0.8rem; display: flex; align-items: center; gap: 4px;">
                                                        <svg style="width:14px;height:14px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                                                        </svg>
                                                        จัดการประกาศ
                                                    </a>
                                                    <button type="button" class="btn btn-secondary" title="แก้ไขโครงการ" style="padding: 6px 12px; font-size:0.8rem; display: flex; align-items: center; gap: 4px;"
                                                            onclick="openEditProject(<?= $proj['id'] ?>, <?= htmlspecialchars(json_encode($proj['project_name'])) ?>, <?= $proj['budget'] ?>, <?= $proj['plan_id'] ?>, <?= htmlspecialchars(json_encode($proj['procurement_type'])) ?>, <?= htmlspecialchars(json_encode($proj['quantity'])) ?>, <?= htmlspecialchars(json_encode($proj['required_date'])) ?>, <?= htmlspecialchars(json_encode($proj['procurement_method'])) ?>, <?= htmlspecialchars(json_encode($proj['request_month'])) ?>, <?= htmlspecialchars(json_encode($proj['contract_month'])) ?>, <?= htmlspecialchars(json_encode($proj['responsible_person'])) ?>)">
                                                        <svg style="width:14px;height:14px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/>
                                                        </svg>
                                                        แก้ไขข้อมูล
                                                    </button>
                                                    <a href="admin.php?action=delete_project&id=<?= $proj['id'] ?>" class="btn btn-danger btn-icon-only" title="ลบโครงการนี้"
                                                       onclick="return confirm('คุณแน่ใจว่าต้องการลบโครงการจัดซื้อนี้และเอกสารประกาศประกอบทั้งหมด? ไฟล์ PDF บนเซิร์ฟเวอร์จะถูกลบออกทั้งหมดจริง')">
                                                        <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24">
                                                            <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                                                        </svg>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- MODALs moved to global bottom section -->

            <!-- View 3: Detailed Project & Manage Step Announcements (2 to 5) -->
            <?php elseif ($view_project_id > 0 && $project_data): 
                $status_info = get_status_info($project_data['status']);
            ?>
                <div style="margin-bottom: 24px;">
                    <a href="admin.php?view=projects" class="btn btn-secondary" style="padding: 8px 16px;">
                        &larr; กลับไปยังรายการโครงการทั้งหมด
                    </a>
                </div>

                <!-- Project Info Card -->
                <section class="project-card" style="margin-bottom: 30px;">
                    <div class="project-header" style="background-color: #fafbfd; cursor: default;">
                        <div class="project-title-area">
                            <span style="font-size: 0.8rem; font-weight:700; color: var(--secondary-dark); text-transform: uppercase;">โครงการที่กำลังจัดการ:</span>
                            <h2 style="margin-top: 4px;"><?= htmlspecialchars($project_data['project_name']) ?></h2>
                            <div class="project-meta">
                                <span class="meta-badge budget">งบประมาณ: <?= number_format($project_data['budget'], 2) ?> บาท</span>
                                <span class="meta-badge" style="background: var(--primary-glow); color: var(--primary);">
                                    ผูกกับ: <?= htmlspecialchars($parent_plan['plan_name'] ?? 'ไม่มีแผนงาน') ?>
                                </span>
                                <span class="meta-badge" style="background-color: #f1f5f9; color: var(--text-muted);">
                                    เอกสารย่อยสะสม (ขั้นตอน 2-5): <?= count($project_announcements) ?> ขั้นตอน
                                </span>
                            </div>
                        </div>
                        <?php 
                            $time_status = get_procurement_time_status($status_info['step'], $project_data['request_month'], $parent_plan['fiscal_year'] ?? (date('Y') + 543));
                        ?>
                        <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                            <span class="project-status-badge <?= $status_info['class'] ?>">
                                ขั้นตอนที่ <?= $status_info['step'] ?>: <?= $status_info['label'] ?>
                            </span>
                            <span class="project-status-badge <?= $time_status['class'] ?>">
                                <?= $time_status['label'] ?>
                            </span>
                        </div>
                    </div>

                    <!-- Project Info Summary Grid -->
                    <div class="project-info-summary" style="border-top: 1px solid var(--border-color); background: #ffffff; padding: 16px 30px; display: grid;">
                        <div class="info-item">
                            <strong>ประเภทการจัดหา</strong>
                            <span><?= htmlspecialchars($project_data['procurement_type']) ?></span>
                        </div>
                        <div class="info-item">
                            <strong>วิธีจัดซื้อจัดจ้าง</strong>
                            <span><?= htmlspecialchars($project_data['procurement_method']) ?></span>
                        </div>
                        <div class="info-item">
                            <strong>ปริมาณ (จำนวน/หน่วย)</strong>
                            <span><?= htmlspecialchars($project_data['quantity']) ?></span>
                        </div>
                        <div class="info-item">
                            <strong>กำหนดใช้งานวัสดุ</strong>
                            <span><?= format_month_year($project_data['required_date']) ?></span>
                        </div>
                        <div class="info-item">
                            <strong>ช่วงเดือนขอซื้อขอจ้าง</strong>
                            <span><?= format_month_year($project_data['request_month']) ?></span>
                        </div>
                        <div class="info-item">
                            <strong>ช่วงเดือนทำสัญญา</strong>
                            <span><?= format_month_year($project_data['contract_month']) ?></span>
                        </div>
                        <div class="info-item">
                            <strong>ผู้รับผิดชอบ</strong>
                            <span><?= !empty($project_data['responsible_person']) ? htmlspecialchars($project_data['responsible_person']) : 'ไม่ระบุ' ?></span>
                        </div>
                    </div>

                    <!-- Visual Timeline -->
                    <div class="timeline-stepper" style="border-top: 1px solid var(--border-color); cursor: default;">
                        <div class="steps-container">
                            <?php 
                            $steps = [
                                1 => 'แผนการจัดซื้อจัดจ้าง',
                                2 => 'ร่างประกาศ/TOR',
                                3 => 'ประกาศเชิญชวน',
                                4 => 'การชี้แจงเพิ่มเติม',
                                5 => 'ประกาศผลผู้ชนะ'
                            ];
                            
                            $active_steps = [];
                            $active_steps[1] = true; // Step 1 is inherited from plan
                            foreach ($project_announcements as $ann) {
                                $active_steps[$ann['category_id']] = true;
                            }
                            
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
                </section>

                <div class="grid-2">
                    <!-- Form: Upload announcement PDF -->
                    <section class="card-table-wrap" style="height: fit-content;">
                        <div class="card-table-header">
                            <h2>อัปโหลดและสร้างประกาศตามขั้นตอน</h2>
                        </div>
                        <form action="admin.php?view_project=<?= $view_project_id ?>" method="POST" enctype="multipart/form-data" style="padding: 24px; display: flex; flex-direction: column; gap: 16px;">
                            <input type="hidden" name="action" value="add_announcement">
                            <input type="hidden" name="project_id" value="<?= $view_project_id ?>">
                            
                            <div class="form-group">
                                <label for="category_id">ขั้นตอน/ประเภทสิ่งที่จะประกาศ (ขั้นตอน 2-5)</label>
                                <select id="category_id" name="category_id" class="form-control" onchange="autoFillTitle()" required>
                                    <option value="" disabled selected>--- เลือกขั้นตอนประกาศ ---</option>
                                    <option value="2">ขั้นตอนที่ 2: ร่างประกาศและร่างเอกสารซื้อหรือจ้าง (เพื่อรับฟังความคิดเห็น)</option>
                                    <option value="3">ขั้นตอนที่ 3: ประกาศและเอกสารเชิญชวน (e-market, e-bidding, สอบราคา, ฯลฯ)</option>
                                    <option value="4">ขั้นตอนที่ 4: การชี้แจงรายละเอียดเพิ่มเติมหรือการแก้ไขเอกสาร</option>
                                    <option value="5">ขั้นตอนที่ 5: ประกาศผลผู้ชนะหรือผู้ได้รับการคัดเลือก</option>
                                </select>
                                <p style="color: var(--text-muted); font-size: 0.75rem; margin-top: 4px;">* หมายเหตุ: ขั้นตอนที่ 1 (แผนการจัดซื้อจัดจ้าง) จะได้รับสิทธิ์โดยอัตโนมัติจากแผนงานประจำปีหลัก</p>
                            </div>

                            <div class="form-group">
                                <label for="title">หัวข้อประกาศ (จะแสดงให้บุคคลทั่วไปเห็น)</label>
                                <input type="text" id="title" name="title" class="form-control" placeholder="พิมพ์ระบุชื่อหัวข้อประกาศ..." required>
                            </div>

                            <div class="form-group">
                                <label for="announce_date">วันที่ประกาศลงในเว็บไซต์</label>
                                <input type="date" id="announce_date" name="announce_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="pdf_file">เอกสารประกาศประกอบโครงการ (ไฟล์ PDF เท่านั้น)</label>
                                <input type="file" id="pdf_file" name="pdf_file" class="form-control" accept=".pdf" required>
                                <p style="color: var(--text-light); font-size: 0.75rem; margin-top: 4px;">ขนาดไฟล์สูงสุดไม่เกิน 10MB และต้องเป็นนามสกุล .pdf เท่านั้น</p>
                            </div>

                            <div class="form-group">
                                <label for="notes">บันทึกรายละเอียดเพิ่มเติม/หมายเหตุย่อ (ถ้ามี)</label>
                                <textarea id="notes" name="notes" class="form-control" rows="3" placeholder="ระบุเพิ่มเติม เช่น ข้อมูลชี้แจง รายละเอียดบิดดิ้ง..."></textarea>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                                <svg style="width:18px;height:18px;fill:currentColor;" viewBox="0 0 24 24">
                                    <path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/>
                                </svg>
                                อัปโหลดไฟล์ประกาศและอัปเดตสถานะโครงการ
                            </button>
                        </form>
                    </section>

                    <!-- List: Uploaded announcements for this project -->
                    <section class="card-table-wrap">
                        <div class="card-table-header">
                            <h2>เอกสารประกาศที่อยู่ในโครงการนี้ (ขั้นตอน 2-5)</h2>
                        </div>
                        <div style="padding: 24px;">
                            <?php if (empty($project_announcements)): ?>
                                <div style="text-align: center; padding: 40px; color: var(--text-muted); background: #fafbfd; border-radius: var(--radius-sm); border: 1px dashed var(--border-color);">
                                    ยังไม่มีเอกสารประกาศในโครงการนี้ (มีเฉพาะขั้นตอนที่ 1 แผนหลัก) กรุณากรอกฟอร์มฝั่งซ้ายเพื่ออัปโหลดขั้นตอน 2-5
                                </div>
                            <?php else: ?>
                                <div style="display:flex; flex-direction:column; gap: 16px;">
                                    <?php foreach ($project_announcements as $ann): 
                                        $cat_badges = [
                                            2 => 'badge-draft',
                                            3 => 'badge-bidding',
                                            4 => 'badge-clarification',
                                            5 => 'badge-completed'
                                        ];
                                        $badge_class = $cat_badges[$ann['category_id']] ?? 'badge-unknown';
                                    ?>
                                        <div style="border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding:16px; background:#ffffff; position:relative;">
                                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                                <span class="doc-step-indicator <?= $badge_class ?>" style="font-size:0.7rem; font-weight:700; padding: 4px 8px; border-radius:4px;">
                                                    ขั้นตอนที่ <?= $ann['category_id'] ?>: <?= get_category_name($ann['category_id']) ?>
                                                </span>
                                                <a href="admin.php?action=delete_announcement&id=<?= $ann['id'] ?>&project_id=<?= $view_project_id ?>" class="btn btn-danger btn-icon-only" style="padding:4px;" title="ลบประกาศขั้นตอนนี้"
                                                   onclick="return confirm('คุณแน่ใจว่าต้องการลบเอกสารประกาศนี้ใช่หรือไม่? ไฟล์ที่อยู่ในระบบจะถูกลบจริงทันที')">
                                                    <svg style="width:16px;height:16px;fill:currentColor;" viewBox="0 0 24 24">
                                                        <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/>
                                                    </svg>
                                                </a>
                                            </div>
                                            <h4 style="margin-top: 8px; font-size: 0.92rem; color: var(--primary-dark);"><?= htmlspecialchars($ann['title']) ?></h4>
                                            <div style="margin-top: 6px; font-size:0.8rem; color: var(--text-muted);">
                                                เผยแพร่วันที่: <strong><?= get_thai_date($ann['announce_date']) ?></strong>
                                            </div>
                                            <?php if (!empty($ann['notes'])): ?>
                                                <div style="font-size:0.8rem; background:#fafbfd; padding:6px 12px; border-left: 2px solid var(--secondary); margin-top:8px; color: var(--text-muted);">
                                                    <?= nl2br(htmlspecialchars($ann['notes'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top: 10px; display:flex; gap:8px;">
                                                <a href="<?= htmlspecialchars($ann['file_path']) ?>" target="_blank" class="btn btn-secondary" style="padding:4px 10px; font-size:0.75rem;">
                                                    ดูไฟล์ PDF
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <!-- Auto-fill title helper -->
                <script>
                    const projectTitle = "<?= htmlspecialchars(addslashes($project_data['project_name'])) ?>";
                    function autoFillTitle() {
                        const select = document.getElementById('category_id');
                        const titleInput = document.getElementById('title');
                        const categories = {
                            2: "ร่างประกาศและร่างเอกสารเผยแพร่จัดซื้อ " + projectTitle,
                            3: "ประกาศเชิญชวนเสนอราคาซื้อ/จ้าง " + projectTitle,
                            4: "ชี้แจงรายละเอียดเพิ่มเติมโครงการ " + projectTitle,
                            5: "ประกาศผลผู้ชนะเสนอราคาและเอกสารแนบ " + projectTitle
                        };
                        const selectedVal = select.value;
                        if (categories[selectedVal]) {
                            titleInput.value = categories[selectedVal];
                        }
                    }
                </script>

            <!-- View: Tracking Dashboard -->
            <?php elseif ($view === 'tracking'): 
                $projects_by_method = [];
                $completed_projects_by_plan = [];
                foreach ($all_projects as $p) {
                    $prog_info = get_project_tracking_progress($pdo, $p);
                    $p['prog_info'] = $prog_info;
                    
                    if ($prog_info['progress_pct'] == 100) {
                        $plan = !empty($p['plan_name']) ? $p['plan_name'] : 'ไม่ระบุแผน';
                        if (!isset($completed_projects_by_plan[$plan])) {
                            $completed_projects_by_plan[$plan] = [];
                        }
                        $completed_projects_by_plan[$plan][] = $p;
                    } else {
                        $method = !empty($p['procurement_method']) ? $p['procurement_method'] : 'ไม่ระบุวิธีจัดซื้อจัดจ้าง';
                        if (!isset($projects_by_method[$method])) {
                            $projects_by_method[$method] = [];
                        }
                        $projects_by_method[$method][] = $p;
                    }
                }
            ?>
                <div style="margin-bottom: 24px; padding: 0 10px;">
                    <h2 style="font-size: 1.5rem; color: var(--primary-dark); margin-bottom: 6px;">ติดตามสถานะโครงการ (Roadmap 9 ขั้นตอน)</h2>
                    <p style="color: var(--text-muted);">ระบบติดตามสถานะการดำเนินงานภายในของแต่ละโครงการ (จัดกลุ่มตามวิธีจัดซื้อจัดจ้าง) แยกโครงการที่เสร็จสมบูรณ์แล้วไว้ด้านล่าง</p>
                </div>

                <?php if (empty($projects_by_method)): ?>
                    <section class="card-table-wrap">
                        <div style="text-align:center; padding: 40px; color: var(--text-muted);">ไม่พบข้อมูลโครงการ</div>
                    </section>
                <?php else: ?>
                    <?php foreach ($projects_by_method as $method => $method_projects): ?>
                        <section class="card-table-wrap" style="margin-bottom: 30px;">
                            <div class="card-table-header" style="background: #fafbfd; border-bottom: 1px solid var(--border-color); padding: 16px 20px;">
                                <h3 style="color: var(--primary-dark); margin: 0; font-size: 1.1rem; display:flex; align-items:center; gap:8px;">
                                    <svg style="width:20px;height:20px;fill:var(--secondary);" viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
                                    วิธีจัดซื้อจัดจ้าง: <?= htmlspecialchars($method) ?> 
                                    <span style="font-size:0.85rem; background:var(--secondary); color:white; padding:2px 8px; border-radius:12px; margin-left:8px;"><?= count($method_projects) ?> โครงการ</span>
                                </h3>
                            </div>
                            <div style="overflow-x: auto;">
                                <table class="table" style="min-width: 1000px; margin-bottom: 0;">
                                    <thead style="background:#f8fafc;">
                                        <tr>
                                            <th width="5%" style="text-align:center; padding:12px;">#</th>
                                            <th width="35%" style="padding:12px;">ชื่อโครงการ</th>
                                            <th width="15%" style="text-align:right; padding:12px;">งบประมาณ</th>
                                            <th width="20%" style="text-align:center; padding:12px;">สถานะปัจจุบัน (ภายใน)</th>
                                            <th width="15%" style="padding:12px;">ความคืบหน้าภาพรวม</th>
                                            <th width="10%" style="text-align:center; padding:12px;">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php $i=1; foreach ($method_projects as $proj): 
                                            $prog = $proj['prog_info'];
                                            $progress_score = $prog['progress_score'];
                                            $total_steps = $prog['total_steps'];
                                            $progress_pct = $prog['progress_pct'];
                                            $latest_completed_step = $prog['latest_completed_step'];
                                            $latest_completed_date = $prog['latest_completed_date'];
                                            $current_status_color = $prog['current_status_color'];
                                        ?>
                                        <tr>
                                            <td style="text-align:center; vertical-align:middle;"><?= $i++ ?></td>
                                            <td style="vertical-align:middle;">
                                                <strong style="color: var(--primary-dark); font-size:0.95rem; line-height:1.4; display:block; margin-bottom:8px;"><?= htmlspecialchars($proj['project_name']) ?></strong>
                                                <div style="display:flex; gap: 8px; flex-wrap:wrap; align-items:center;">
                                                    <span style="font-size:0.8rem; color:var(--text-muted); padding:4px 8px; background:#f1f5f9; border-radius:4px; font-weight: 500;">แผน: <?= htmlspecialchars($proj['plan_name']) ?> (ปี <?= $proj['fiscal_year'] ?>)</span>
                                                    <?php if (!empty($proj['responsible_person'])): ?>
                                                        <span style="font-size:0.8rem; color:var(--primary); padding:4px 8px; background:var(--primary-glow); border-radius:4px; font-weight: 500;">ผู้รับผิดชอบ: <?= htmlspecialchars($proj['responsible_person']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td style="text-align:right; vertical-align:middle; font-weight:600; color:#475569;"><?= number_format($proj['budget'], 2) ?> บาท</td>
                                            <td style="text-align:center; vertical-align:middle;">
                                                <span style="font-size: 0.8rem; padding: 4px 10px; display:inline-block; background-color: <?= $current_status_color ?>; color: white; border-radius: 20px; font-weight: 500;"><?= $latest_completed_step ?></span>
                                                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">เมื่อ: <?= get_thai_date($latest_completed_date) ?></div>
                                            </td>
                                            <td style="vertical-align:middle;">
                                                <div style="width: 100%; background: #e2e8f0; border-radius: 10px; height: 8px; margin-bottom: 6px; overflow:hidden;">
                                                    <div style="height: 10px; border-radius: 10px; background: <?= $progress_pct == 100 ? 'var(--success)' : 'var(--secondary)' ?>; width: <?= $progress_pct ?>%;"></div>
                                                </div>
                                                <span style="font-size:0.75rem; color:var(--text-muted); font-weight:600;"><?= is_float($progress_score) ? rtrim(rtrim(number_format($progress_score, 2), '0'), '.') : $progress_score ?>/<?= $total_steps ?> ขั้นตอน (<?= $progress_pct ?>%)</span>
                                            </td>
                                            <td style="text-align: center; vertical-align:middle;">
                                                <?php if ($admin_role !== 'executive'): ?>
                                                    <a href="admin.php?view=tracking_detail&id=<?= $proj['id'] ?>" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.8rem; white-space:nowrap; border-radius: 20px;">อัปเดต Tracking</a>
                                                <?php else: ?>
                                                    <span style="font-size: 0.8rem; color: #999;">(เฉพาะ Admin)</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Completed Projects Section -->
                <?php if (!empty($completed_projects_by_plan)): ?>
                <div style="margin-top: 40px; margin-bottom: 24px; padding: 0 10px; border-top: 2px dashed var(--border-color); padding-top: 30px;">
                    <h2 style="font-size: 1.5rem; color: var(--success); margin-bottom: 6px;">โครงการที่ดำเนินการเสร็จสิ้น 100%</h2>
                    <p style="color: var(--text-muted);">แยกตามแผนจัดซื้อจัดจ้างประจำปี</p>
                </div>

                <?php foreach ($completed_projects_by_plan as $plan => $plan_projects): ?>
                    <section class="card-table-wrap" style="margin-bottom: 30px; border-left: 4px solid var(--success);">
                        <div class="card-table-header" style="background: #f0fdf4; border-bottom: 1px solid #bbf7d0; padding: 16px 20px;">
                            <h3 style="color: #166534; margin: 0; font-size: 1.1rem; display:flex; align-items:center; gap:8px;">
                                <svg style="width:20px;height:20px;fill:currentColor;" viewBox="0 0 24 24">
                                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                </svg>
                                แผนจัดซื้อจัดจ้าง: <?= htmlspecialchars($plan) ?> 
                                <span style="font-size:0.85rem; background:#22c55e; color:white; padding:2px 8px; border-radius:12px; margin-left:8px;"><?= count($plan_projects) ?> โครงการ</span>
                            </h3>
                        </div>
                        <div style="overflow-x: auto;">
                            <table class="table" style="min-width: 1000px; margin-bottom: 0;">
                                <thead style="background:#f8fafc;">
                                    <tr>
                                        <th width="5%" style="text-align:center; padding:12px;">#</th>
                                        <th width="35%" style="padding:12px;">ชื่อโครงการ</th>
                                        <th width="15%" style="text-align:right; padding:12px;">งบประมาณ</th>
                                        <th width="20%" style="text-align:center; padding:12px;">สถานะปัจจุบัน (ภายใน)</th>
                                        <th width="15%" style="padding:12px;">ความคืบหน้าภาพรวม</th>
                                        <th width="10%" style="text-align:center; padding:12px;">จัดการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $i=1; foreach ($plan_projects as $proj): 
                                        $prog = $proj['prog_info'];
                                        $progress_score = $prog['progress_score'];
                                        $total_steps = $prog['total_steps'];
                                        $progress_pct = $prog['progress_pct'];
                                        $latest_completed_step = $prog['latest_completed_step'];
                                        $latest_completed_date = $prog['latest_completed_date'];
                                        $current_status_color = $prog['current_status_color'];
                                    ?>
                                    <tr>
                                        <td style="text-align:center; vertical-align:middle;"><?= $i++ ?></td>
                                        <td style="vertical-align:middle;">
                                            <strong style="color: var(--primary-dark); font-size:0.95rem; line-height:1.4; display:block; margin-bottom:8px;"><?= htmlspecialchars($proj['project_name']) ?></strong>
                                            <div style="display:flex; gap: 8px; flex-wrap:wrap; align-items:center;">
                                                <span style="font-size:0.8rem; color:var(--text-muted); padding:4px 8px; background:#f1f5f9; border-radius:4px; font-weight: 500;">วิธี: <?= htmlspecialchars($proj['procurement_method']) ?></span>
                                                <?php if (!empty($proj['responsible_person'])): ?>
                                                    <span style="font-size:0.8rem; color:var(--primary); padding:4px 8px; background:var(--primary-glow); border-radius:4px; font-weight: 500;">ผู้รับผิดชอบ: <?= htmlspecialchars($proj['responsible_person']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="text-align:right; vertical-align:middle; font-weight:600; color:#475569;"><?= number_format($proj['budget'], 2) ?> บาท</td>
                                        <td style="text-align:center; vertical-align:middle;">
                                            <span style="font-size: 0.8rem; padding: 4px 10px; display:inline-block; background-color: <?= $current_status_color ?>; color: white; border-radius: 20px; font-weight: 500;"><?= $latest_completed_step ?></span>
                                            <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">เมื่อ: <?= get_thai_date($latest_completed_date) ?></div>
                                        </td>
                                        <td style="vertical-align:middle;">
                                            <div style="width: 100%; background: #e2e8f0; border-radius: 10px; height: 8px; margin-bottom: 6px; overflow:hidden;">
                                                <div style="height: 10px; border-radius: 10px; background: var(--success); width: 100%;"></div>
                                            </div>
                                            <span style="font-size:0.75rem; color:var(--success); font-weight:600;">เสร็จสมบูรณ์ (100%)</span>
                                        </td>
                                        <td style="text-align: center; vertical-align:middle;">
                                            <?php if ($admin_role !== 'executive'): ?>
                                                <a href="admin.php?view=tracking_detail&id=<?= $proj['id'] ?>" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.8rem; white-space:nowrap; border-radius: 20px;">ดูข้อมูล Tracking</a>
                                            <?php else: ?>
                                                <span style="font-size: 0.8rem; color: #999;">(ดูได้อย่างเดียว)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endforeach; endif; ?>

            <!-- View: Tracking Detail -->
            <?php elseif ($view === 'tracking_detail' && isset($_GET['id'])): 
                $proj_id = intval($_GET['id']);
                $p_stmt = $pdo->prepare("SELECT p.*, pl.plan_name, pl.fiscal_year, pl.announce_date as plan_date FROM projects p JOIN plans pl ON p.plan_id = pl.id WHERE p.id = ?");
                $p_stmt->execute([$proj_id]);
                $proj = $p_stmt->fetch();
                if (!$proj):
            ?>
                <p style="padding:40px; text-align:center;">ไม่พบข้อมูลโครงการ</p>
            <?php else: 
                $t_stmt = $pdo->prepare("SELECT * FROM project_tracking WHERE project_id = ?");
                $t_stmt->execute([$proj_id]);
                $track = $t_stmt->fetch() ?: [
                    'step2_status'=>'pending','step2_date'=>'',
                    'step3_status'=>'pending','step3_date'=>'',
                    'step4_status'=>'pending','step4_date'=>'',
                    'step7_status'=>'pending','step7_date'=>'',
                    'step9_status'=>'pending','step9_date'=>''
                ];
                
                $inst_stmt = $pdo->prepare("SELECT * FROM project_installments WHERE project_id = ? ORDER BY id ASC");
                $inst_stmt->execute([$proj_id]);
                $installments = $inst_stmt->fetchAll();
                
                $cont_stmt = $pdo->prepare("SELECT * FROM project_contracts WHERE project_id = ? ORDER BY id ASC");
                $cont_stmt->execute([$proj_id]);
                $contracts = $cont_stmt->fetchAll();
                
                // Group installments by contract
                $inst_by_contract = [];
                $inst_unassigned = [];
                foreach ($installments as $inst) {
                    if (!empty($inst['contract_id'])) {
                        $inst_by_contract[$inst['contract_id']][] = $inst;
                    } else {
                        $inst_unassigned[] = $inst;
                    }
                }
                
                $step5_done = in_array($proj['status'], ['draft', 'bidding', 'clarification', 'completed']);
                $step6_done = ($proj['status'] === 'completed');
                
                $inst_all_done = false;
                if (count($installments) > 0) {
                    $inst_all_done = true;
                    foreach ($installments as $inst) {
                        if ($inst['delivery_status'] !== 'completed' || $inst['inspection_status'] !== 'completed' || $inst['payment_status'] !== 'completed') {
                            $inst_all_done = false;
                            break;
                        }
                    }
                }
            ?>
                <div style="display: flex; gap: 16px; margin-bottom: 24px;">
                    <a href="admin.php?view=tracking" class="btn btn-secondary">
                        <svg style="width:16px;height:16px;fill:currentColor;vertical-align:bottom;margin-right:4px;" viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                        กลับไปหน้ารวม
                    </a>
                </div>

                <section class="card-table-wrap" style="margin-bottom: 30px;">
                    <div class="card-table-header">
                        <h2>ข้อมูลโครงการ</h2>
                    </div>
                    <div style="padding: 20px;">
                        <p style="font-size:1.1rem; font-weight:600; color:var(--primary-dark); margin-bottom:10px;"><?= htmlspecialchars($proj['project_name']) ?></p>
                        <p style="color:var(--text-muted); margin-bottom:5px;">แผน: <?= htmlspecialchars($proj['plan_name']) ?> (ปี <?= $proj['fiscal_year'] ?>)</p>
                        <p style="color:var(--text-muted);">งบประมาณ: <strong><?= number_format($proj['budget'], 2) ?> บาท</strong> | วิธีการจัดซื้อ: <?= htmlspecialchars($proj['procurement_method']) ?> | ผู้รับผิดชอบ: <strong><?= !empty($proj['responsible_person']) ? htmlspecialchars($proj['responsible_person']) : 'ไม่ระบุ' ?></strong></p>
                    </div>
                </section>

                <form action="admin.php" method="POST">
                    <input type="hidden" name="action" value="update_tracking">
                    <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                    
                    <section class="card-table-wrap" style="margin-bottom: 30px;">
                        <div class="card-table-header">
                            <h2>อัปเดตสถานะ 9 ขั้นตอน (Internal Tracking)</h2>
                        </div>
                        <div style="padding: 24px;">
                            <?php if ($proj['procurement_method'] === 'เฉพาะเจาะจง'): ?>
                                <!-- เฉพาะเจาะจง Steps -->
                                <!-- Step 1 -->
                                <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                    <h3 style="font-size:1rem; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                        <span style="background:var(--success); color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">1</span>
                                        การจัดทำแผนการจัดซื้อจัดจ้างประจำปี <span style="font-size:0.8rem; font-weight:normal; color:var(--success);">(เสร็จสิ้นจากระบบประกาศ)</span>
                                    </h3>
                                    <p style="font-size:0.85rem; color:var(--text-muted); margin-left:32px;">วันที่ประกาศ: <?= get_thai_date($proj['plan_date']) ?></p>
                                </div>
                                <!-- Step 2 -->
                                <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                    <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                        <span style="background:<?= ($track['spec_step2_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">2</span>
                                        การจัดขออนุมัติซื้อ/จ้าง / แต่งตั้งคณะกรรมการ
                                    </h3>
                                    <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                        <select name="spec_step2_status" class="form-control" style="width:200px;">
                                            <option value="pending" <?= ($track['spec_step2_status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                            <option value="completed" <?= ($track['spec_step2_status'] ?? '') === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                                        </select>
                                        <input type="date" name="spec_step2_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['spec_step2_date'] ?? '') ?>">
                                    </div>
                                </div>
                                <!-- Step 3 (New) -->
                                <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                    <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                        <span style="background:<?= ($track['spec_step2b_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">3</span>
                                        การจัดทำรายละเอียดพัสดุ
                                    </h3>
                                    <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                        <select name="spec_step2b_status" class="form-control" style="width:200px;">
                                            <option value="pending" <?= ($track['spec_step2b_status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                            <option value="completed" <?= ($track['spec_step2b_status'] ?? '') === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                                        </select>
                                        <input type="date" name="spec_step2b_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['spec_step2b_date'] ?? '') ?>">
                                    </div>
                                </div>
                                <!-- Step 4 -->
                                <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                    <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                        <span style="background:<?= ($track['spec_step3_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">4</span>
                                        การเจรจาตกลงราคา (ใบเสนอราคา)
                                    </h3>
                                    <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                        <select name="spec_step3_status" class="form-control" style="width:200px;">
                                            <option value="pending" <?= ($track['spec_step3_status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                            <option value="completed" <?= ($track['spec_step3_status'] ?? '') === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                                        </select>
                                        <input type="date" name="spec_step3_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['spec_step3_date'] ?? '') ?>">
                                    </div>
                                </div>
                                <!-- Step 5 -->
                                <div style="margin-bottom: 16px;">
                                    <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                        <span style="background:<?= ($track['spec_step4_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">5</span>
                                        การทำสัญญา / ใบสั่งซื้อ/จ้าง
                                    </h3>
                                    
                                    <!-- Add Contract Form -->
                                    <div style="margin-left:32px; margin-bottom:16px; background:#f8fafc; padding:12px; border-radius:8px; display:inline-block;">
                                        <div style="font-size:0.85rem; font-weight:600; margin-bottom:8px; color:var(--primary-dark);">เพิ่มบริษัทผู้ชนะ (กรณีมีหลายบริษัท)</div>
                                        <div style="display:flex; gap:8px; align-items:center;">
                                            <input type="text" id="spec_new_company_name" class="form-control" placeholder="ชื่อบริษัท / ผู้รับจ้าง" style="width:250px;" onkeypress="if(event.key === 'Enter'){ event.preventDefault(); addContract('spec_new_company_name'); }">
                                            <button type="button" class="btn btn-secondary" onclick="addContract('spec_new_company_name')" style="font-size:0.8rem; padding:6px 12px;">+ เพิ่ม</button>
                                        </div>
                                    </div>
                                    
                                    <!-- List Contracts -->
                                    <?php if (!empty($contracts)): ?>
                                    <div style="margin-left:32px; margin-bottom:16px;">
                                        <?php foreach ($contracts as $idx => $c): ?>
                                            <div style="display:flex; gap:16px; align-items:center; margin-bottom:8px; background:white; border:1px solid #e2e8f0; padding:8px 12px; border-radius:6px;">
                                                <div style="font-weight:600; font-size:0.85rem; width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($c['company_name']) ?>">
                                                    <?= ($idx+1).'. '.htmlspecialchars($c['company_name']) ?>
                                                </div>
                                                <select name="contract_status[<?= $c['id'] ?>]" class="form-control" style="width:130px; font-size:0.8rem; padding:4px;">
                                                    <option value="pending" <?= $c['contract_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                    <option value="completed" <?= $c['contract_status'] === 'completed' ? 'selected' : '' ?>>ทำสัญญาแล้ว</option>
                                                </select>
                                                <input type="date" name="contract_date[<?= $c['id'] ?>]" class="form-control" style="width:130px; font-size:0.8rem; padding:4px;" value="<?= htmlspecialchars($c['contract_date'] ?? '') ?>">
                                                <a href="admin.php?action=delete_contract&id=<?= $c['id'] ?>&project_id=<?= $proj_id ?>" class="btn btn-danger btn-icon-only" style="padding:2px 6px; font-size:0.75rem;" onclick="return confirm('ยืนยันลบรายชื่อบริษัทนี้?')">ลบ</a>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>

                                    <div style="display:flex; gap:16px; margin-left:32px; align-items:center; border-top:1px dashed #ccc; padding-top:12px;">
                                        <div style="font-size:0.85rem; font-weight:600; color:#475569; width:120px;">สถานะภาพรวม:</div>
                                        <select name="spec_step4_status" class="form-control" style="width:200px;">
                                            <option value="pending" <?= ($track['spec_step4_status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                            <option value="completed" <?= ($track['spec_step4_status'] ?? '') === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น (ทำสัญญาทุกรายแล้ว)</option>
                                        </select>
                                        <input type="date" name="spec_step4_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['spec_step4_date'] ?? '') ?>">
                                    </div>
                                </div>
                                <div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 16px;">
                                    <button type="submit" class="btn btn-primary">บันทึกข้อมูล Tracking (วิธีเฉพาะเจาะจง)</button>
                                </div>

                            <?php else: ?>
                            <!-- Step 1 -->
                            <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:var(--success); color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">1</span>
                                    การจัดทำแผนการจัดซื้อจัดจ้างประจำปี <span style="font-size:0.8rem; font-weight:normal; color:var(--success);">(เสร็จสิ้นจากระบบประกาศ)</span>
                                </h3>
                                <p style="font-size:0.85rem; color:var(--text-muted); margin-left:32px;">วันที่ประกาศ: <?= get_thai_date($proj['plan_date']) ?></p>
                            </div>

                            <!-- Step 2 -->
                            <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= ($track['step2_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">2</span>
                                    การจัดทำร่างขอบเขตของงาน (TOR)
                                </h3>
                                <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                    <select name="step2_status" class="form-control" style="width:200px;">
                                        <option value="pending" <?= $track['step2_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                        <option value="completed" <?= $track['step2_status'] === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                                    </select>
                                    <input type="date" name="step2_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['step2_date'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Step 3 -->
                            <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= ($track['step3_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">3</span>
                                    การจัดทำรายงานขอซื้อหรือขอจ้าง
                                </h3>
                                <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                    <select name="step3_status" class="form-control" style="width:200px;">
                                        <option value="pending" <?= $track['step3_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                        <option value="completed" <?= $track['step3_status'] === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                                    </select>
                                    <input type="date" name="step3_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['step3_date'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Step 4 -->
                            <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= ($track['step4_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">4</span>
                                    การแต่งตั้งคณะกรรมการซื้อหรือจ้าง
                                </h3>
                                <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                    <select name="step4_status" class="form-control" style="width:200px;">
                                        <option value="pending" <?= $track['step4_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                        <option value="completed" <?= $track['step4_status'] === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น</option>
                                    </select>
                                    <input type="date" name="step4_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['step4_date'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Step 5 & 6 -->
                            <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= $step5_done ? 'var(--success)' : '#ccc' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">5</span>
                                    ดำเนินการซื้อหรือจ้าง (e-Bidding)
                                    <span style="font-size:0.8rem; font-weight:normal; color:<?= $step5_done ? 'var(--success)' : '#999' ?>;">
                                        (<?= $step5_done ? 'มีประกาศแล้ว' : 'ยังไม่มีประกาศ' ?>)
                                    </span>
                                </h3>
                                <h3 style="font-size:1rem; margin-top:16px; margin-bottom:8px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= $step6_done ? 'var(--success)' : '#ccc' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">6</span>
                                    พิจารณาอนุมัติและประกาศผลผู้ชนะ
                                    <span style="font-size:0.8rem; font-weight:normal; color:<?= $step6_done ? 'var(--success)' : '#999' ?>;">
                                        (<?= $step6_done ? 'ได้ผู้ชนะแล้ว' : 'ยังไม่สิ้นสุดการประกวดราคา' ?>)
                                    </span>
                                </h3>
                                <div style="margin-left: 32px; margin-top: 12px;">
                                    <a href="admin.php?view_project=<?= $proj_id ?>" class="btn btn-primary" style="font-size: 0.8rem; padding: 6px 12px; border-radius: 20px; display: inline-flex; align-items: center; gap: 6px;">
                                        <svg style="width:14px; height:14px; fill:currentColor;" viewBox="0 0 24 24">
                                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                                        </svg>
                                        จัดการประกาศ e-Bidding (ขั้นตอน 5 และ 6)
                                    </a>
                                    <span style="font-size: 0.8rem; color: var(--text-muted); margin-left: 8px;">
                                        * สถานะและวันที่จะอัปเดตอัตโนมัติเมื่อมีการเพิ่มประกาศในระบบ
                                    </span>
                                </div>
                            </div>

                            <!-- Step 7 -->
                            <div style="border-bottom: 1px solid #eee; padding-bottom: 16px; margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= ($track['step7_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">7</span>
                                    การทำสัญญาและการวางหลักประกัน
                                </h3>
                                
                                <!-- Add Contract Form -->
                                <div style="margin-left:32px; margin-bottom:16px; background:#f8fafc; padding:12px; border-radius:8px; display:inline-block;">
                                    <div style="font-size:0.85rem; font-weight:600; margin-bottom:8px; color:var(--primary-dark);">เพิ่มบริษัทผู้ชนะ (กรณีมีหลายบริษัท)</div>
                                    <div style="display:flex; gap:8px; align-items:center;">
                                        <input type="text" id="gen_new_company_name" class="form-control" placeholder="ชื่อบริษัท / ผู้รับจ้าง" style="width:250px;" onkeypress="if(event.key === 'Enter'){ event.preventDefault(); addContract('gen_new_company_name'); }">
                                        <button type="button" class="btn btn-secondary" onclick="addContract('gen_new_company_name')" style="font-size:0.8rem; padding:6px 12px;">+ เพิ่ม</button>
                                    </div>
                                </div>
                                
                                <!-- List Contracts -->
                                <?php if (!empty($contracts)): ?>
                                <div style="margin-left:32px; margin-bottom:16px;">
                                    <?php foreach ($contracts as $idx => $c): ?>
                                        <div style="display:flex; gap:16px; align-items:center; margin-bottom:8px; background:white; border:1px solid #e2e8f0; padding:8px 12px; border-radius:6px;">
                                            <div style="font-weight:600; font-size:0.85rem; width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= htmlspecialchars($c['company_name']) ?>">
                                                <?= ($idx+1).'. '.htmlspecialchars($c['company_name']) ?>
                                            </div>
                                            <select name="contract_status[<?= $c['id'] ?>]" class="form-control" style="width:130px; font-size:0.8rem; padding:4px;">
                                                <option value="pending" <?= $c['contract_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                <option value="completed" <?= $c['contract_status'] === 'completed' ? 'selected' : '' ?>>ทำสัญญาแล้ว</option>
                                            </select>
                                            <input type="date" name="contract_date[<?= $c['id'] ?>]" class="form-control" style="width:130px; font-size:0.8rem; padding:4px;" value="<?= htmlspecialchars($c['contract_date'] ?? '') ?>">
                                            <a href="admin.php?action=delete_contract&id=<?= $c['id'] ?>&project_id=<?= $proj_id ?>" class="btn btn-danger btn-icon-only" style="padding:2px 6px; font-size:0.75rem;" onclick="return confirm('ยืนยันลบรายชื่อบริษัทนี้?')">ลบ</a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <div style="display:flex; gap:16px; margin-left:32px; align-items:center; border-top:1px dashed #ccc; padding-top:12px;">
                                    <div style="font-size:0.85rem; font-weight:600; color:#475569; width:120px;">สถานะภาพรวม:</div>
                                    <select name="step7_status" class="form-control" style="width:200px;">
                                        <option value="pending" <?= $track['step7_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                        <option value="completed" <?= $track['step7_status'] === 'completed' ? 'selected' : '' ?>>เสร็จสิ้น (ทำสัญญาทุกรายแล้ว)</option>
                                    </select>
                                    <input type="date" name="step7_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['step7_date'] ?? '') ?>">
                                </div>
                            </div>

                            <!-- Step 9 -->
                            <div style="margin-bottom: 16px;">
                                <h3 style="font-size:1rem; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                                    <span style="background:<?= ($track['step9_status'] ?? '') === 'completed' ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">9</span>
                                    การจัดทำบันทึกรายงานผลการพิจารณา (สิ้นสุดกระบวนการ)
                                </h3>
                                <div style="display:flex; gap:16px; margin-left:32px; align-items:center;">
                                    <select name="step9_status" class="form-control" style="width:200px;">
                                        <option value="pending" <?= $track['step9_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                        <option value="completed" <?= $track['step9_status'] === 'completed' ? 'selected' : '' ?>>เสร็จสิ้นสมบูรณ์</option>
                                    </select>
                                    <input type="date" name="step9_date" class="form-control" style="width:200px;" value="<?= htmlspecialchars($track['step9_date'] ?? '') ?>">
                                </div>
                            </div>
                            
                            <div style="margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 16px;">
                                <button type="submit" class="btn btn-primary">บันทึกข้อมูล Tracking (ขั้นที่ 2,3,4,7,9)</button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </section>
                </form>

                <!-- Step 8/5: Installments -->
                <section class="card-table-wrap" style="margin-bottom: 40px;">
                    <div class="card-table-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <h2 style="display:flex; align-items:center; gap:8px;">
                            <span style="background:<?= $inst_all_done ? 'var(--success)' : 'var(--primary)' ?>; color:white; border-radius:50%; width:24px; height:24px; display:flex; align-items:center; justify-content:center; font-size:12px;">
                                <?= ($proj['procurement_method'] === 'เฉพาะเจาะจง') ? '6' : '8' ?>
                            </span>
                            ส่งมอบพัสดุ / ตรวจรับ / เบิกจ่ายเงิน
                        </h2>
                    </div>
                    
                    <div style="padding: 20px;">
                        <?php if (empty($contracts) && empty($inst_unassigned)): ?>
                            <div style="background:#fff3cd; color:#856404; padding:16px; border-radius:8px; border:1px solid #ffeeba; text-align:center;">
                                ⚠️ กรุณาเพิ่ม "รายชื่อบริษัทผู้ชนะ" ในขั้นตอนการทำสัญญาก่อน จึงจะสามารถเพิ่มงวดงานได้
                            </div>
                        <?php endif; ?>

                        <?php foreach ($contracts as $c): ?>
                            <div style="margin-bottom: 30px; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
                                <div style="background:#f1f5f9; padding:12px 20px; font-weight:600; font-size:1.05rem; color:var(--primary-dark); border-bottom:1px solid var(--border-color);">
                                    บริษัท/ผู้รับจ้าง: <?= htmlspecialchars($c['company_name']) ?>
                                </div>
                                <div style="padding: 16px; background:#fafbfd; border-bottom: 1px solid #eee;">
                                    <form action="admin.php" method="POST" style="display:flex; gap:16px; align-items:flex-end;">
                                        <input type="hidden" name="action" value="add_installment">
                                        <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                                        <input type="hidden" name="contract_id" value="<?= $c['id'] ?>">
                                        <div class="form-group" style="margin:0; width: 300px;">
                                            <label style="font-size:0.85rem;">เพิ่มงวดงาน (เช่น งวดที่ 1, งวดสุดท้าย)</label>
                                            <input type="text" name="installment_name" class="form-control" placeholder="ชื่องวดงาน" required>
                                        </div>
                                        <button type="submit" class="btn btn-secondary">เพิ่มงวดงานให้บริษัทนี้</button>
                                    </form>
                                </div>
                                <div style="padding: 16px;">
                                    <?php if (empty($inst_by_contract[$c['id']])): ?>
                                        <p style="text-align:center; color:var(--text-muted); margin:0;">ยังไม่มีข้อมูลการส่งมอบพัสดุสำหรับบริษัทนี้</p>
                                    <?php else: ?>
                                        <?php foreach ($inst_by_contract[$c['id']] as $inst): ?>
                                            <div style="background:white; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; margin-bottom:16px;">
                                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                                                    <h4 style="font-size:1.05rem; font-weight:600; color:var(--primary-dark); margin:0;">
                                                        <?= htmlspecialchars($inst['installment_name']) ?>
                                                    </h4>
                                                    <a href="admin.php?action=delete_installment&id=<?= $inst['id'] ?>&project_id=<?= $proj_id ?>" 
                                                       class="btn btn-danger btn-icon-only" style="padding:4px 8px;"
                                                       onclick="return confirm('ยืนยันการลบงวดงานนี้?')">ลบ</a>
                                                </div>
                                                <form action="admin.php" method="POST">
                                                    <input type="hidden" name="action" value="update_installment">
                                                    <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                                                    <input type="hidden" name="installment_id" value="<?= $inst['id'] ?>">
                                                    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
                                                        <!-- Delivery -->
                                                        <div style="background:#f8fafc; padding:12px; border-radius:8px;">
                                                            <strong style="display:block; margin-bottom:8px; color:#475569;">1. ส่งมอบพัสดุ</strong>
                                                            <select name="delivery_status" class="form-control" style="margin-bottom:8px;">
                                                                <option value="pending" <?= $inst['delivery_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                                <option value="completed" <?= $inst['delivery_status'] === 'completed' ? 'selected' : '' ?>>ส่งมอบแล้ว</option>
                                                            </select>
                                                            <input type="date" name="delivery_date" class="form-control" value="<?= htmlspecialchars($inst['delivery_date'] ?? '') ?>">
                                                        </div>
                                                        <!-- Inspection -->
                                                        <div style="background:#f8fafc; padding:12px; border-radius:8px;">
                                                            <strong style="display:block; margin-bottom:8px; color:#475569;">2. ตรวจรับพัสดุ</strong>
                                                            <select name="inspection_status" class="form-control" style="margin-bottom:8px;">
                                                                <option value="pending" <?= $inst['inspection_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                                <option value="completed" <?= $inst['inspection_status'] === 'completed' ? 'selected' : '' ?>>ตรวจรับแล้ว</option>
                                                            </select>
                                                            <input type="date" name="inspection_date" class="form-control" value="<?= htmlspecialchars($inst['inspection_date'] ?? '') ?>">
                                                        </div>
                                                        <!-- Payment -->
                                                        <div style="background:#f8fafc; padding:12px; border-radius:8px;">
                                                            <strong style="display:block; margin-bottom:8px; color:#475569;">3. เบิกจ่ายเงิน</strong>
                                                            <select name="payment_status" class="form-control" style="margin-bottom:8px;">
                                                                <option value="pending" <?= $inst['payment_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                                <option value="completed" <?= $inst['payment_status'] === 'completed' ? 'selected' : '' ?>>เบิกจ่ายแล้ว</option>
                                                            </select>
                                                            <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($inst['payment_date'] ?? '') ?>">
                                                        </div>
                                                    </div>
                                                    <div style="text-align:right; margin-top:16px;">
                                                        <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:0.85rem;">บันทึกข้อมูลงวดนี้</button>
                                                    </div>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if (!empty($inst_unassigned)): ?>
                            <div style="margin-bottom: 30px; border: 1px solid #fde68a; border-radius: 8px; overflow: hidden;">
                                <div style="background:#fef3c7; padding:12px 20px; font-weight:600; font-size:1.05rem; color:#92400e; border-bottom:1px solid #fde68a;">
                                    งวดงานเดิม (ไม่ระบุบริษัท)
                                </div>
                                <div style="padding: 16px;">
                                    <?php foreach ($inst_unassigned as $inst): ?>
                                        <div style="background:white; border:1px solid var(--border-color); border-radius:var(--radius-md); padding:16px; margin-bottom:16px;">
                                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #f1f5f9; padding-bottom:12px;">
                                                <h4 style="font-size:1.05rem; font-weight:600; color:var(--primary-dark); margin:0;">
                                                    <?= htmlspecialchars($inst['installment_name']) ?>
                                                </h4>
                                                <a href="admin.php?action=delete_installment&id=<?= $inst['id'] ?>&project_id=<?= $proj_id ?>" 
                                                   class="btn btn-danger btn-icon-only" style="padding:4px 8px;"
                                                   onclick="return confirm('ยืนยันการลบงวดงานนี้?')">ลบ</a>
                                            </div>
                                            <form action="admin.php" method="POST">
                                                <input type="hidden" name="action" value="update_installment">
                                                <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                                                <input type="hidden" name="installment_id" value="<?= $inst['id'] ?>">
                                                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px;">
                                                    <div style="background:#f8fafc; padding:12px; border-radius:8px;">
                                                        <strong style="display:block; margin-bottom:8px; color:#475569;">1. ส่งมอบพัสดุ</strong>
                                                        <select name="delivery_status" class="form-control" style="margin-bottom:8px;">
                                                            <option value="pending" <?= $inst['delivery_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                            <option value="completed" <?= $inst['delivery_status'] === 'completed' ? 'selected' : '' ?>>ส่งมอบแล้ว</option>
                                                        </select>
                                                        <input type="date" name="delivery_date" class="form-control" value="<?= htmlspecialchars($inst['delivery_date'] ?? '') ?>">
                                                    </div>
                                                    <div style="background:#f8fafc; padding:12px; border-radius:8px;">
                                                        <strong style="display:block; margin-bottom:8px; color:#475569;">2. ตรวจรับพัสดุ</strong>
                                                        <select name="inspection_status" class="form-control" style="margin-bottom:8px;">
                                                            <option value="pending" <?= $inst['inspection_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                            <option value="completed" <?= $inst['inspection_status'] === 'completed' ? 'selected' : '' ?>>ตรวจรับแล้ว</option>
                                                        </select>
                                                        <input type="date" name="inspection_date" class="form-control" value="<?= htmlspecialchars($inst['inspection_date'] ?? '') ?>">
                                                    </div>
                                                    <div style="background:#f8fafc; padding:12px; border-radius:8px;">
                                                        <strong style="display:block; margin-bottom:8px; color:#475569;">3. เบิกจ่ายเงิน</strong>
                                                        <select name="payment_status" class="form-control" style="margin-bottom:8px;">
                                                            <option value="pending" <?= $inst['payment_status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                                                            <option value="completed" <?= $inst['payment_status'] === 'completed' ? 'selected' : '' ?>>เบิกจ่ายแล้ว</option>
                                                        </select>
                                                        <input type="date" name="payment_date" class="form-control" value="<?= htmlspecialchars($inst['payment_date'] ?? '') ?>">
                                                    </div>
                                                </div>
                                                <div style="text-align:right; margin-top:16px;">
                                                    <button type="submit" class="btn btn-secondary" style="padding:6px 12px; font-size:0.85rem;">บันทึกข้อมูลงวดนี้</button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
                
                <!-- Hidden form for contract management -->
                <form id="contract_action_form" action="admin.php" method="POST" style="display:none;">
                    <input type="hidden" name="action" id="ca_action" value="">
                    <input type="hidden" name="project_id" value="<?= $proj_id ?>">
                    <input type="hidden" name="company_name" id="ca_company_name" value="">
                </form>
                <script>
                function addContract(input_id) {
                    var name = document.getElementById(input_id).value;
                    if (!name.trim()) { alert('กรุณากรอกชื่อบริษัท'); return; }
                    document.getElementById('ca_action').value = 'add_contract';
                    document.getElementById('ca_company_name').value = name;
                    document.getElementById('contract_action_form').submit();
                }
                </script>
            <?php endif; ?>

            <!-- View 4: Change Password -->
            <?php elseif ($view === 'password'): ?>
                <section class="card-table-wrap" style="max-width: 500px; margin: 0 auto;">
                    <div class="card-table-header">
                        <h2>เปลี่ยนรหัสผ่านผู้ดูแลระบบ</h2>
                    </div>
                    <form action="admin.php?view=password" method="POST" style="padding: 24px; display: flex; flex-direction: column; gap: 16px;">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="form-group">
                            <label for="old_password">รหัสผ่านปัจจุบัน</label>
                            <input type="password" id="old_password" name="old_password" class="form-control" placeholder="ระบุรหัสผ่านดั้งเดิม..." required>
                        </div>
                        
                        <div class="form-group">
                            <label for="new_password">รหัสผ่านใหม่</label>
                            <input type="password" id="new_password" name="new_password" class="form-control" placeholder="ระบุรหัสผ่านใหม่..." required minlength="6">
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="ยืนยันรหัสผ่านใหม่อีกครั้ง..." required minlength="6">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width:100%; margin-top: 10px;">
                            บันทึกการเปลี่ยนรหัสผ่าน
                        </button>
                    </form>
                </section>
            <!-- View 5: Executive Report -->
            <?php elseif ($view === 'report'): 
                $selected_year_param = $selected_year > 0 ? $selected_year : (date('Y')+543);
                
                // Fetch all plans for this fiscal year
                $stmt = $pdo->prepare("SELECT id, plan_name FROM plans WHERE fiscal_year = ? ORDER BY id DESC");
                $stmt->execute([$selected_year_param]);
                $plans = $stmt->fetchAll();
                
                // Fetch all projects for this fiscal year with their plan information
                $stmt = $pdo->prepare("SELECT p.*, pl.plan_name, pl.fiscal_year FROM projects p JOIN plans pl ON p.plan_id = pl.id WHERE pl.fiscal_year = ?");
                $stmt->execute([$selected_year_param]);
                $projects = $stmt->fetchAll();
                
                $total_budget = 0;
                $total_projects = count($projects);
                $completed_projects = 0;
                
                // Keep track of methods and statuses for the charts
                $method_counts = [];
                $status_counts = [];
                $plan_project_counts = []; // plan_id => ['total' => 0, 'completed' => 0]
                
                // Initialize plan counts
                foreach ($plans as $pl) {
                    $plan_project_counts[$pl['id']] = [
                        'total' => 0,
                        'completed' => 0
                    ];
                }
                
                foreach ($projects as $p) {
                    $total_budget += $p['budget'];
                    
                    // Group by method
                    $method = $p['procurement_method'] ?: 'ไม่ระบุวิธี';
                    if (!isset($method_counts[$method])) $method_counts[$method] = 0;
                    $method_counts[$method]++;
                    
                    // Group by status (using announcement status)
                    $status = $p['status'] ?: 'planning';
                    if (!isset($status_counts[$status])) $status_counts[$status] = 0;
                    $status_counts[$status]++;
                    
                    // Group by plan
                    $p_plan_id = $p['plan_id'];
                    
                    // Calculate tracking progress
                    $prog = get_project_tracking_progress($pdo, $p);
                    $is_completed = ($prog['progress_pct'] == 100);
                    
                    if ($is_completed) {
                        $completed_projects++;
                    }
                    
                    if (isset($plan_project_counts[$p_plan_id])) {
                        $plan_project_counts[$p_plan_id]['total']++;
                        if ($is_completed) {
                            $plan_project_counts[$p_plan_id]['completed']++;
                        }
                    }
                }
                
                $ongoing_projects = $total_projects - $completed_projects;
                
                // Format for JS (Methods chart)
                $method_labels = array_keys($method_counts);
                $method_data = array_values($method_counts);
                
                // Format for JS (Status chart)
                $status_labels = [];
                $status_data = [];
                $status_map = ['planning'=>'กำลังจัดทำแผน', 'draft'=>'ร่างประกาศ', 'bidding'=>'กำลังประกวดราคา', 'clarification'=>'ชี้แจง', 'completed'=>'เสร็จสิ้นสมบูรณ์'];
                foreach ($status_counts as $status_key => $count) {
                    $status_labels[] = $status_map[$status_key] ?? $status_key;
                    $status_data[] = $count;
                }
                
                // Build $plan_breakdown matching the original structure
                $plan_breakdown = [];
                foreach ($plans as $pl) {
                    $plan_breakdown[] = [
                        'id' => $pl['id'],
                        'plan_name' => $pl['plan_name'],
                        'total_proj' => $plan_project_counts[$pl['id']]['total'],
                        'completed_proj' => $plan_project_counts[$pl['id']]['completed']
                    ];
                }
            ?>
                <section class="card-table-wrap" style="padding: 24px; background: transparent; box-shadow: none;">
                    <h2 style="margin-bottom: 24px; font-size: 1.5rem; color: var(--primary-dark);">รายงานสรุปผลการดำเนินการ ประจำปีงบประมาณ พ.ศ. <?= $selected_year_param ?></h2>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                        <div style="background: white; border-radius: var(--radius-lg); padding: 20px; box-shadow: var(--shadow-sm); border-left: 4px solid var(--primary);">
                            <h3 style="color: var(--text-muted); font-size: 1rem; margin-bottom: 8px;">รวมงบประมาณที่จัดหา</h3>
                            <div style="font-size: 1.8rem; font-weight: bold; color: var(--text-main);"><?= number_format($total_budget, 2) ?> <span style="font-size: 1rem; color: var(--text-muted);">บาท</span></div>
                        </div>
                        <div style="background: white; border-radius: var(--radius-lg); padding: 20px; box-shadow: var(--shadow-sm); border-left: 4px solid var(--secondary);">
                            <h3 style="color: var(--text-muted); font-size: 1rem; margin-bottom: 8px;">จำนวนโครงการทั้งหมด</h3>
                            <div style="font-size: 2rem; font-weight: bold; color: var(--text-main);"><?= $total_projects ?> <span style="font-size: 1rem; color: var(--text-muted);">โครงการ</span></div>
                        </div>
                        <div style="background: white; border-radius: var(--radius-lg); padding: 20px; box-shadow: var(--shadow-sm); border-left: 4px solid var(--success);">
                            <h3 style="color: var(--text-muted); font-size: 1rem; margin-bottom: 8px;">โครงการที่เสร็จสมบูรณ์</h3>
                            <div style="font-size: 2rem; font-weight: bold; color: var(--success);"><?= $completed_projects ?> <span style="font-size: 1rem; color: var(--text-muted);">โครงการ</span></div>
                        </div>
                    </div>


                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
                        <div style="background: white; padding: 24px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                            <h3 style="text-align: center; margin-bottom: 16px; color: var(--primary-dark);">สัดส่วนแยกตามวิธีจัดซื้อจัดจ้าง</h3>
                            <div style="height: 250px; position: relative;">
                                <?php if ($total_projects > 0): ?>
                                    <canvas id="methodChart"></canvas>
                                <?php else: ?>
                                    <div style="text-align:center; padding-top: 100px; color: #999;">ไม่มีข้อมูลโครงการในปีนี้</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="background: white; padding: 24px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm);">
                            <h3 style="text-align: center; margin-bottom: 16px; color: var(--primary-dark);">สถิติสถานะการดำเนินงาน</h3>
                            <div style="height: 250px; position: relative;">
                                <?php if ($total_projects > 0): ?>
                                    <canvas id="statusChart"></canvas>
                                <?php else: ?>
                                    <div style="text-align:center; padding-top: 100px; color: #999;">ไม่มีข้อมูลโครงการในปีนี้</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($total_projects > 0): ?>
                    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
                    <script>
                        const methodCtx = document.getElementById('methodChart').getContext('2d');
                        new Chart(methodCtx, {
                            type: 'pie',
                            data: {
                                labels: <?= json_encode($method_labels) ?>,
                                datasets: [{
                                    data: <?= json_encode($method_data) ?>,
                                    backgroundColor: ['#5d2d91', '#bca256', '#e2e8f0', '#94a3b8'],
                                }]
                            },
                            options: { responsive: true, maintainAspectRatio: false }
                        });
                        
                        const statusCtx = document.getElementById('statusChart').getContext('2d');
                        new Chart(statusCtx, {
                            type: 'bar',
                            data: {
                                labels: <?= json_encode($status_labels) ?>,
                                datasets: [{
                                    label: 'จำนวนโครงการ',
                                    data: <?= json_encode($status_data) ?>,
                                    backgroundColor: '#bca256',
                                    borderRadius: 4
                                }]
                            },
                            options: { 
                                responsive: true, 
                                maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                            }
                        });
                    </script>
                    <?php endif; ?>

                    <!-- Plan Breakdown Table -->
                    <div style="background: white; padding: 24px; border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: 24px;">
                        <h3 style="margin-bottom: 16px; color: var(--primary-dark);">รายละเอียดความคืบหน้าแยกตามแผนจัดซื้อจัดจ้าง</h3>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 5%; text-align: center;">ลำดับ</th>
                                        <th style="width: 50%;">ชื่อแผนการจัดซื้อจัดจ้าง</th>
                                        <th style="width: 15%; text-align: center;">จำนวนโครงการทั้งหมด</th>
                                        <th style="width: 15%; text-align: center;">เสร็จสิ้น</th>
                                        <th style="width: 15%; text-align: center;">กำลังดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($plan_breakdown) > 0): ?>
                                        <?php $idx = 1; foreach ($plan_breakdown as $pb): 
                                            $ongoing = $pb['total_proj'] - $pb['completed_proj'];
                                        ?>
                                        <tr>
                                            <td style="text-align: center;"><?= $idx++ ?></td>
                                            <td>
                                                <a href="javascript:void(0);" onclick="loadPlanDetailsAjax(<?= $pb['id'] ?>, '<?= htmlspecialchars(addslashes($pb['plan_name'])) ?>')" style="color: var(--primary); font-weight: 500; text-decoration: none; border-bottom: 1px dashed var(--primary);">
                                                    <?= htmlspecialchars($pb['plan_name']) ?>
                                                </a>
                                            </td>
                                            <td style="text-align: center; font-weight: bold;"><?= $pb['total_proj'] ?></td>
                                            <td style="text-align: center; color: var(--success); font-weight: bold;"><?= $pb['completed_proj'] ?></td>
                                            <td style="text-align: center; color: var(--primary); font-weight: bold;"><?= $ongoing ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5" style="text-align: center; color: #999;">ไม่มีข้อมูลแผนจัดซื้อจัดจ้างในปีนี้</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- AJAX Modal for Plan Details -->
                    <div class="modal" id="ajax-plan-modal">
                        <div class="modal-content" style="max-width: 900px; width: 90%;">
                            <div class="modal-header">
                                <h3 id="ajax-plan-title">รายละเอียดความคืบหน้าโครงการ</h3>
                                <button type="button" class="modal-close" onclick="closeModal('ajax-plan-modal')">&times;</button>
                            </div>
                            <div class="modal-body" id="ajax-plan-body" style="max-height: 60vh; overflow-y: auto;">
                                <div style="text-align:center; padding: 40px; color:var(--text-muted);">กำลังโหลดข้อมูล...</div>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                    function loadPlanDetailsAjax(planId, planName) {
                        document.getElementById('ajax-plan-title').innerText = 'รายละเอียดแผน: ' + planName;
                        document.getElementById('ajax-plan-body').innerHTML = '<div style="text-align:center; padding: 40px; color:var(--text-muted);">กำลังโหลดข้อมูล...</div>';
                        showModal('ajax-plan-modal');
                        
                        fetch('admin.php?action=get_plan_tracking&plan_id=' + planId)
                            .then(response => response.text())
                            .then(html => {
                                document.getElementById('ajax-plan-body').innerHTML = html;
                            })
                            .catch(error => {
                                document.getElementById('ajax-plan-body').innerHTML = '<div style="text-align:center; padding: 20px; color: red;">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>';
                            });
                    }
                    </script>
                </section>
                
            <!-- View: User Management -->
            <?php elseif ($view === 'users' && in_array($admin_role, ['superadmin', 'admin'])): 
                $stmt = $pdo->query("SELECT * FROM users ORDER BY id ASC");
                $users = $stmt->fetchAll();
            ?>
                <section class="card-table-wrap">
                    <div class="card-table-header">
                        <h2>รายชื่อผู้ใช้งานทั้งหมด</h2>
                        <button type="button" class="btn btn-primary" onclick="showModal('add-user-modal')">
                            + เพิ่มผู้ใช้งานใหม่
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table-admin">
                            <thead>
                                <tr>
                                    <th width="80px" style="text-align: center;">ลำดับ</th>
                                    <th>ชื่อผู้ใช้งาน (Username)</th>
                                    <th>ชื่อ-นามสกุล</th>
                                    <th width="150px" style="text-align: center;">ระดับสิทธิ์ (Role)</th>
                                    <th width="120px" style="text-align: center;">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $user_idx = 1; foreach ($users as $u): ?>
                                <tr>
                                    <td style="text-align: center;"><?= $user_idx++ ?></td>
                                    <td style="font-weight: 500;"><?= htmlspecialchars($u['username']) ?></td>
                                    <td><?= htmlspecialchars($u['name']) ?></td>
                                    <td style="text-align: center;">
                                        <?php if ($u['role'] === 'superadmin'): ?>
                                            <span style="font-size: 0.8rem; background: #e0e7ff; color: #4338ca; padding: 4px 8px; border-radius: 12px; font-weight: 600;">Super Admin</span>
                                        <?php elseif ($u['role'] === 'admin'): ?>
                                            <span style="font-size: 0.8rem; background: #fef08a; color: #854d0e; padding: 4px 8px; border-radius: 12px; font-weight: 600;">Admin</span>
                                        <?php elseif ($u['role'] === 'executive'): ?>
                                            <span style="font-size: 0.8rem; background: #bbf7d0; color: #166534; padding: 4px 8px; border-radius: 12px; font-weight: 600;">Executive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 0.8rem;" onclick="openEditUser(<?= $u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>', '<?= $u['role'] ?>')">แก้ไข</button>
                                        <?php if ($u['id'] !== $_SESSION['admin_user_id']): ?>
                                            <a href="admin.php?action=delete_user&id=<?= $u['id'] ?>" class="btn" style="background: #ef4444; color: white; padding: 4px 10px; font-size: 0.8rem;" onclick="return confirm('ยืนยันการลบผู้ใช้งานรายนี้?');">ลบ</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php endif; ?>
        </main>
    </div>

        </main>
    </div>

    <!-- GLOBAL MODALS -->
    <!-- MODAL: Add Plan -->
    <div class="modal" id="add-plan-modal">
        <div class="modal-content">
            <form action="admin.php?view=<?= htmlspecialchars($view) ?><?= isset($_GET['id']) ? '&id=' . intval($_GET['id']) : '' ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_plan">
                <div class="modal-header">
                    <h3>เพิ่มแผนการจัดซื้อจัดจ้างประจำปีใหม่</h3>
                    <button type="button" class="modal-close" onclick="closeModal('add-plan-modal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="plan_name">ชื่อหัวข้อแผนงานจัดซื้อ (ภาษาไทย)</label>
                        <input type="text" id="plan_name" name="plan_name" class="form-control" placeholder="เช่น แผนการจัดซื้อจัดจ้างประจำปีงบประมาณ พ.ศ. 2569..." required>
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="fiscal_year">ปีงบประมาณ (พ.ศ.)</label>
                            <input type="number" id="fiscal_year" name="fiscal_year" class="form-control" value="<?= (date('Y') + 543) ?>" required min="2560" max="2600">
                        </div>
                        <div class="form-group">
                            <label for="budget_source">แหล่งงบประมาณ</label>
                            <input type="text" id="budget_source" name="budget_source" class="form-control" placeholder="เช่น งบประมาณแผ่นดิน, รายได้..." required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="announce_date">วันที่เผยแพร่ประกาศ</label>
                        <input type="date" id="announce_date" name="announce_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="pdf_file">ไฟล์ประกาศแผนจัดซื้อจัดจ้างหลัก (ไฟล์ PDF ไม่เกิน 15MB)</label>
                        <input type="file" id="pdf_file" name="pdf_file" class="form-control" accept=".pdf" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-plan-modal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกและอัปโหลด</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Edit Plan -->
    <div class="modal" id="edit-plan-modal">
        <div class="modal-content">
            <form action="admin.php?view=<?= htmlspecialchars($view) ?><?= isset($_GET['id']) ? '&id=' . intval($_GET['id']) : '' ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_plan">
                <input type="hidden" id="edit_plan_modal_id" name="plan_id">
                <div class="modal-header">
                    <h3>แก้ไขข้อมูลแผนการจัดซื้อจัดจ้าง</h3>
                    <button type="button" class="modal-close" onclick="closeModal('edit-plan-modal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="edit_plan_name">ชื่อหัวข้อแผนงานจัดซื้อ (ภาษาไทย)</label>
                        <input type="text" id="edit_plan_name" name="plan_name" class="form-control" required>
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="edit_plan_fiscal_year">ปีงบประมาณ (พ.ศ.)</label>
                            <input type="number" id="edit_plan_fiscal_year" name="fiscal_year" class="form-control" required min="2560" max="2600">
                        </div>
                        <div class="form-group">
                            <label for="edit_plan_budget_source">แหล่งงบประมาณ</label>
                            <input type="text" id="edit_plan_budget_source" name="budget_source" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="edit_plan_announce_date">วันที่เผยแพร่ประกาศ</label>
                        <input type="date" id="edit_plan_announce_date" name="announce_date" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_plan_pdf_file">ไฟล์ประกาศแผนจัดซื้อจัดจ้างหลัก (เว้นว่างไว้หากไม่ต้องการเปลี่ยนไฟล์)</label>
                        <input type="file" id="edit_plan_pdf_file" name="pdf_file" class="form-control" accept=".pdf">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-plan-modal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Add Project -->
    <div class="modal" id="add-project-modal">
        <div class="modal-content">
            <form action="admin.php?view=<?= htmlspecialchars($view) ?><?= isset($_GET['id']) ? '&id=' . intval($_GET['id']) : '' ?>" method="POST">
                <input type="hidden" name="action" value="create_project">
                <div class="modal-header">
                    <h3>สร้างโครงการจัดซื้อจัดจ้างใหม่</h3>
                    <button type="button" class="modal-close" onclick="closeModal('add-project-modal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="plan_id">เลือกแผนการจัดซื้อจัดจ้างประจำปี (แผนหลัก)</label>
                        <select id="plan_id" name="plan_id" class="form-control" required>
                            <option value="" disabled selected>--- เลือกแผนงาน ---</option>
                            <?php foreach ($all_plans as $pl): ?>
                                <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['plan_name']) ?> (พ.ศ. <?= $pl['fiscal_year'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="project_name">ชื่อโครงการจัดซื้อจัดจ้างย่อย</label>
                        <input type="text" id="project_name" name="project_name" class="form-control" placeholder="เช่น จัดซื้อครุภัณฑ์การศึกษา..." required>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="budget">งบประมาณโครงการ (บาท)</label>
                        <input type="number" step="0.01" id="budget" name="budget" class="form-control" placeholder="ระบุงบประมาณ..." required min="1">
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="procurement_type">แผนการจัดหา (ประเภท)</label>
                            <select id="procurement_type" name="procurement_type" class="form-control" required>
                                <option value="" disabled selected>--- เลือกประเภทการจัดหา ---</option>
                                <option value="จัดซื้อ">จัดซื้อ</option>
                                <option value="จัดจ้าง">จัดจ้าง</option>
                                <option value="เช่า">เช่า</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="procurement_method">วิธีจัดซื้อจัดจ้าง</label>
                            <select id="procurement_method" name="procurement_method" class="form-control" required>
                                <option value="" disabled selected>--- เลือกวิธีจัดซื้อจัดจ้าง ---</option>
                                <option value="เฉพาะเจาะจง">เฉพาะเจาะจง</option>
                                <option value="ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)">ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)</option>
                                <option value="คัดเลือก">คัดเลือก</option>
                                <option value="สอบราคา">สอบราคา</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="quantity">ปริมาณ (จำนวน/หน่วย)</label>
                            <input type="text" id="quantity" name="quantity" class="form-control" placeholder="เช่น 8 รายการ / 1 ชุด" required>
                        </div>
                        <div class="form-group">
                            <label for="responsible_person">ผู้รับผิดชอบ</label>
                            <input type="text" id="responsible_person" name="responsible_person" class="form-control" placeholder="ระบุชื่อผู้รับผิดชอบ...">
                        </div>
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="required_date">กำหนดต้องการใช้วัสดุ</label>
                            <input type="month" id="required_date" name="required_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="request_month">ช่วงเดือนขอซื้อขอจ้าง</label>
                            <input type="month" id="request_month" name="request_month" class="form-control">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="contract_month">ช่วงเดือนทำสัญญา/สั่งซื้อสั่งจ้าง</label>
                        <input type="month" id="contract_month" name="contract_month" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-project-modal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">สร้างโครงการ</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: Edit Project -->
    <div class="modal" id="edit-project-modal">
        <div class="modal-content">
            <form action="admin.php?view=<?= htmlspecialchars($view) ?><?= isset($_GET['id']) ? '&id=' . intval($_GET['id']) : '' ?>" method="POST">
                <input type="hidden" name="action" value="edit_project">
                <input type="hidden" id="edit_project_id" name="project_id">
                <div class="modal-header">
                    <h3>แก้ไขรายละเอียดโครงการจัดซื้อ</h3>
                    <button type="button" class="modal-close" onclick="closeModal('edit-project-modal')">&times;</button>
                </div>
                <div class="modal-body">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="edit_plan_id">แผนการจัดซื้อจัดจ้างประจำปี (แผนหลัก)</label>
                        <select id="edit_plan_id" name="plan_id" class="form-control" required>
                            <?php foreach ($all_plans as $pl): ?>
                                <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['plan_name']) ?> (พ.ศ. <?= $pl['fiscal_year'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="edit_project_name">ชื่อโครงการจัดซื้อจัดจ้างย่อย</label>
                        <input type="text" id="edit_project_name" name="project_name" class="form-control" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="edit_budget">งบประมาณโครงการ (บาท)</label>
                        <input type="number" step="0.01" id="edit_budget" name="budget" class="form-control" required min="1">
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="edit_procurement_type">แผนการจัดหา (ประเภท)</label>
                            <select id="edit_procurement_type" name="procurement_type" class="form-control" required>
                                <option value="จัดซื้อ">จัดซื้อ</option>
                                <option value="จัดจ้าง">จัดจ้าง</option>
                                <option value="เช่า">เช่า</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="edit_procurement_method">วิธีจัดซื้อจัดจ้าง</label>
                            <select id="edit_procurement_method" name="procurement_method" class="form-control" required>
                                <option value="เฉพาะเจาะจง">เฉพาะเจาะจง</option>
                                <option value="ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)">ประกวดราคาอิเล็กทรอนิกส์ (e-bidding)</option>
                                <option value="คัดเลือก">คัดเลือก</option>
                                <option value="สอบราคา">สอบราคา</option>
                            </select>
                        </div>
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="edit_quantity">ปริมาณ (จำนวน/หน่วย)</label>
                            <input type="text" id="edit_quantity" name="quantity" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_responsible_person">ผู้รับผิดชอบ</label>
                            <input type="text" id="edit_responsible_person" name="responsible_person" class="form-control" placeholder="ระบุชื่อผู้รับผิดชอบ...">
                        </div>
                    </div>
                    <div class="grid-2" style="margin-bottom: 16px;">
                        <div class="form-group">
                            <label for="edit_required_date">กำหนดต้องการใช้วัสดุ</label>
                            <input type="month" id="edit_required_date" name="required_date" class="form-control">
                        </div>
                        <div class="form-group">
                            <label for="edit_request_month">ช่วงเดือนขอซื้อขอจ้าง</label>
                            <input type="month" id="edit_request_month" name="request_month" class="form-control">
                        </div>
                    </div>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label for="edit_contract_month">ช่วงเดือนทำสัญญา/สั่งซื้อสั่งจ้าง</label>
                        <input type="month" id="edit_contract_month" name="contract_month" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-project-modal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modals Script Functions -->
    <script>
        function showModal(id) {
            document.getElementById(id).style.display = 'flex';
        }
        
        function closeModal(id) {
            document.getElementById(id).style.display = 'none';
        }
        
        function openEditPlan(id, name, year, budgetSource, announceDate) {
            document.getElementById('edit_plan_modal_id').value = id;
            document.getElementById('edit_plan_name').value = name;
            document.getElementById('edit_plan_fiscal_year').value = year;
            document.getElementById('edit_plan_budget_source').value = budgetSource || '';
            document.getElementById('edit_plan_announce_date').value = announceDate;
            showModal('edit-plan-modal');
        }
        
        function openAddProject(planId) {
            const planSelect = document.getElementById('plan_id');
            if (planSelect) {
                planSelect.value = planId;
            }
            showModal('add-project-modal');
        }
        
        function openEditProject(id, name, budget, planId, procurementType, quantity, requiredDate, procurementMethod, requestMonth, contractMonth, responsiblePerson) {
            document.getElementById('edit_project_id').value = id;
            document.getElementById('edit_project_name').value = name;
            document.getElementById('edit_budget').value = budget;
            document.getElementById('edit_plan_id').value = planId;
            document.getElementById('edit_procurement_type').value = procurementType || '';
            document.getElementById('edit_procurement_method').value = procurementMethod || '';
            document.getElementById('edit_quantity').value = quantity || '';
            document.getElementById('edit_required_date').value = requiredDate || '';
            document.getElementById('edit_request_month').value = requestMonth || '';
            document.getElementById('edit_contract_month').value = contractMonth || '';
            document.getElementById('edit_responsible_person').value = responsiblePerson || '';
            showModal('edit-project-modal');
        }
        
        // User Management Modals
        function openEditUser(id, name, role) {
            document.getElementById('edit_user_id').value = id;
            document.getElementById('edit_user_name').value = name;
            document.getElementById('edit_user_role').value = role;
            document.getElementById('edit_user_password').value = '';
            showModal('edit-user-modal');
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let i = 0; i < modals.length; i++) {
                if (event.target === modals[i]) {
                    modals[i].style.display = 'none';
                }
            }
        }
    </script>
    
    <!-- Modal: Add User -->
    <div class="modal" id="add-user-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>เพิ่มผู้ใช้งานใหม่</h3>
                <button class="modal-close" onclick="closeModal('add-user-modal')">&times;</button>
            </div>
            <form action="admin.php" method="POST" style="padding: 24px;">
                <input type="hidden" name="action" value="create_user">
                <div class="form-group">
                    <label>ชื่อผู้ใช้งาน (Username) สำหรับเข้าสู่ระบบ</label>
                    <input type="text" name="username" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>รหัสผ่าน (Password) <span style="font-size:0.8rem;color:var(--text-muted); font-weight:normal;">(ปล่อยว่างได้หากใช้ UP Login)</span></label>
                    <input type="password" name="password" class="form-control">
                </div>
                <div class="form-group">
                    <label>ชื่อ-นามสกุล หรือ ตำแหน่ง</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>ระดับสิทธิ์ (Role)</label>
                    <select name="role" class="form-control" required>
                        <option value="admin">Admin (เจ้าหน้าที่พัสดุ - จัดการโครงการ)</option>
                        <option value="executive">Executive (ผู้บริหาร - ดูรายงาน)</option>
                        <option value="superadmin">Super Admin (ผู้ดูแลระบบสูงสุด)</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('add-user-modal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">เพิ่มผู้ใช้งาน</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Modal: Edit User -->
    <div class="modal" id="edit-user-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>แก้ไขข้อมูลผู้ใช้งาน</h3>
                <button class="modal-close" onclick="closeModal('edit-user-modal')">&times;</button>
            </div>
            <form action="admin.php" method="POST" style="padding: 24px;">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="form-group">
                    <label>ชื่อ-นามสกุล หรือ ตำแหน่ง</label>
                    <input type="text" name="name" id="edit_user_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>ระดับสิทธิ์ (Role)</label>
                    <select name="role" id="edit_user_role" class="form-control" required>
                        <option value="admin">Admin (เจ้าหน้าที่พัสดุ - จัดการโครงการ)</option>
                        <option value="executive">Executive (ผู้บริหาร - ดูรายงาน)</option>
                        <option value="superadmin">Super Admin (ผู้ดูแลระบบสูงสุด)</option>
                    </select>
                </div>
                <div class="form-group" style="margin-top: 20px; border-top: 1px dashed var(--border-color); padding-top: 16px;">
                    <label>ตั้งรหัสผ่านสำหรับ Local Auth <span style="font-size:0.8rem;color:var(--text-muted); font-weight:normal;">(ปล่อยว่างไว้หากใช้ UP Login)</span></label>
                    <input type="password" name="password" id="edit_user_password" class="form-control" placeholder="รหัสผ่านใหม่...">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('edit-user-modal')">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                </div>
            </form>
        </div>
    </div>

</body>
</html>
