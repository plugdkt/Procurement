<?php
// login.php - Admin authentication
require_once 'db.php';
session_start();

// If already logged in, redirect to admin panel
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin.php');
    exit();
}

$error = '';
$max_attempts = 5;
$lockout_time = 60; // seconds

// Initialize login attempts session variables
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

if (!isset($_SESSION['lockout_until'])) {
    $_SESSION['lockout_until'] = 0;
}

// Check if currently locked out
if ($_SESSION['lockout_until'] > time()) {
    $remaining = $_SESSION['lockout_until'] - time();
    $error = "คุณระบุรหัสผ่านผิดหลายครั้งเกินไป ระบบถูกระงับการเข้าสู่ระบบชั่วคราว กรุณารออีก {$remaining} วินาที";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SESSION['lockout_until'] <= time()) {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? trim($_POST['password']) : '';

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้งานและรหัสผ่าน';
    } else {
        try {
            $pdo = db_connect();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            $auth_success = false;
            $admin_name = '';

            // Fallback for local superadmin account or existing local accounts
            if ($user && password_verify($password, $user['password'])) {
                $auth_success = true;
                $admin_name = $user['name'];
            } 
            // Try UP LDAP Authentication (SOAP) if local fails
            else {
                require_once 'login/src/nusoap.php';
                $user_b64 = base64_encode($username);
                $pass_b64 = base64_encode($password);
                
                $wsdl_login = "https://ws.up.ac.th/mobile/AuthenService.asmx?WSDL";
                $soapaction_login = "http://tempuri.org/Login";
                $body_login = '<Login xmlns="http://tempuri.org/">';
                $body_login .= '<username>'.$user_b64.'</username>';
                $body_login .= '<password>'.$pass_b64.'</password>';
                $body_login .= '<ProductName>voiceofstudentstaff</ProductName>';
                $body_login .= '</Login>';
                
                $client = new nusoap_client($wsdl_login, true);
                $client->decode_utf8 = false;
                $mysoapmsg = $client->serializeEnvelope($body_login, '', array(), 'document', 'literal');
                $response = $client->send($mysoapmsg, $soapaction_login);
                
                if (!$client->fault && !$client->getError()) {
                    $result = $response['LoginResult'] ?? '';
                    if (strlen($result) != 0 && $result != 'SESSION_LOCK') {
                        $sid = $result;
                        
                        $wsdl_staff = "https://ws.up.ac.th/mobile/StaffService.asmx?WSDL";
                        $soapaction_staff = "http://tempuri.org/GetStaffInfo";
                        $body_staff = '<GetStaffInfo xmlns="http://tempuri.org/">';
                        $body_staff .= '<sessionID>'.$sid.'</sessionID>';
                        $body_staff .= '</GetStaffInfo>';
                        
                        $client_staff = new nusoap_client($wsdl_staff, true);
                        $client_staff->decode_utf8 = false;
                        $mysoapmsg_staff = $client_staff->serializeEnvelope($body_staff, '', array(), 'document', 'literal');
                        $response_staff = $client_staff->send($mysoapmsg_staff, $soapaction_staff);
                        
                        $staff_info = $response_staff['GetStaffInfoResult'] ?? null;
                        
                        if ($staff_info && isset($staff_info['Faculty']) && $staff_info['Faculty'] === 'คณะวิทยาศาสตร์การแพทย์') {
                            if ($user) { // Must exist in our local system (Option 1)
                                $auth_success = true;
                                $admin_name = $staff_info['Title'] . $staff_info['FirstName_TH'] . ' ' . $staff_info['LastName_TH'];
                            } else {
                                $error = "คุณยังไม่ได้รับสิทธิ์ให้เข้าใช้งานระบบหลังบ้าน กรุณาติดต่อผู้ดูแลระบบ";
                            }
                        } else {
                            $error = "สงวนสิทธิ์เฉพาะบุคลากรคณะวิทยาศาสตร์การแพทย์เท่านั้น";
                        }
                    }
                }
            }

            if ($auth_success) {
                // Login successful - Reset login attempts
                $_SESSION['login_attempts'] = 0;
                $_SESSION['lockout_until'] = 0;
                
                // Set session variables
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['admin_name'] = $admin_name;
                $_SESSION['admin_role'] = $user['role'] ?? 'admin';

                header('Location: admin.php');
                exit();
            } else if ($error === '') {
                // Login failed and no specific error set yet
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= $max_attempts) {
                    $_SESSION['lockout_until'] = time() + $lockout_time;
                    $error = "คุณระบุรหัสผ่านผิดหลายครั้งเกินไป ระบบถูกระงับการเข้าสู่ระบบชั่วคราว กรุณารออีก {$lockout_time} วินาที";
                } else {
                    $remaining_attempts = $max_attempts - $_SESSION['login_attempts'];
                    $error = "ชื่อผู้ใช้งานหรือรหัสผ่านไม่ถูกต้อง (สามารถลองได้อีก {$remaining_attempts} ครั้ง)";
                }
            }
        } catch (Exception $e) {
            $error = 'เกิดข้อผิดพลาดของระบบฐานข้อมูลในการเข้าสู่ระบบ';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบผู้ดูแลระบบ - ระบบประกาศจัดซื้อจัดจ้าง</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">

    <div class="login-card">
        <div class="login-header">
            <!-- Royal Logo SVG -->
            <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                <path d="M50 10 L85 30 L85 70 L50 90 L15 70 L15 30 Z" fill="none" stroke="#bca256" stroke-width="4"/>
                <path d="M50 18 L78 34 L78 66 L50 82 L22 66 L22 34 Z" fill="#5d2d91"/>
                <circle cx="50" cy="50" r="16" fill="#bca256"/>
                <path d="M50 38 L50 62 M38 50 L62 50" stroke="#ffffff" stroke-width="4" stroke-linecap="round"/>
            </svg>
            <h1>เข้าสู่ระบบผู้ดูแลระบบ</h1>
            <p>ระบบจัดการประกาศจัดซื้อจัดจ้าง คณะวิทยาศาสตร์การแพทย์</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert-message alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group" style="margin-bottom: 20px;">
                <label for="username">ชื่อผู้ใช้งาน (Username)</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="พิมพ์ชื่อผู้ใช้งาน..." required autofocus autocomplete="username">
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label for="password">รหัสผ่าน (Password)</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="พิมพ์รหัสผ่าน..." required autocomplete="current-password">
            </div>

            <div style="display: flex; flex-direction: column; gap: 12px;">
                <button type="submit" class="btn btn-primary" style="width: 100%;" <?= ($_SESSION['lockout_until'] > time()) ? 'disabled' : '' ?>>
                    เข้าสู่ระบบ
                </button>
                <a href="index.php" class="btn btn-secondary" style="width: 100%; text-align: center;">
                    กลับไปยังหน้าหลักสาธารณะ
                </a>
            </div>
        </form>

        <div style="text-align: center; margin-top: 30px; font-size: 0.8rem; color: var(--text-muted);">
            สงวนสิทธิ์การเข้าถึงสำหรับเจ้าหน้าที่คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา เท่านั้น
        </div>
    </div>

</body>
</html>
