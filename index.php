<?php
// ไฟล์: index.php (หน้า Login หลัก)

// *** 1. การตั้งค่า Session ***
ini_set('session.cookie_lifetime', 0);
ini_set('session.gc_maxlifetime', 1440);

session_start();
require_once 'config.php'; 

// 2. ตรวจสอบว่าล็อกอินอยู่แล้วหรือไม่
if (isset($_SESSION['user_mslog'])) {
    header("Location: missions/mission_list.php");
    exit();
}

$error_message = '';
$is_guest_login = false;

// 3. ตรวจสอบการส่งค่าจากฟอร์ม (POST) หรือการเข้าสู่โหมด Guest (GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- โหมด: ผู้ใช้ปกติเข้าสู่ระบบ ---
    $username = $mysqli->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    // 4. ดึงข้อมูลผู้ใช้จากตาราง users
    $stmt = $mysqli->prepare("SELECT username, responsible_person, role, password FROM users WHERE username = ?");
    
    if ($stmt === false) {
         $error_message = "เกิดข้อผิดพลาดในการเตรียมคำสั่ง SQL: " . $mysqli->error;
    } else {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
    
        if ($result && $result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $stored_password = $user['password'];
    
            // 5. การตรวจสอบรหัสผ่าน
            $login_successful = false;
    
            // A. ตรวจสอบแบบ Hashing (แนะนำ)
            if (password_verify($password, $stored_password)) {
                $login_successful = true;
            }
            // B. ตรวจสอบแบบ Plain Text (ถ้าจำเป็น)
            else if ($password === $stored_password) {
                $login_successful = true;
            }
    
            if ($login_successful) {
                // ล็อกอินสำเร็จ
                $_SESSION['user_mslog'] = [
                    'username' => $user['username'],
                    'responsible_person' => $user['responsible_person'],
                    'role' => strtolower($user['role'] ?? 'user')
                ];
                $stmt->close();
                header("Location: missions/mission_list.php");
                exit();
            } else {
                $error_message = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
            }
        } else {
            $error_message = "ไม่พบชื่อผู้ใช้ในระบบ";
        }
        
        if (isset($stmt) && $stmt->close) {
            $stmt->close();
        }
    }
} else if (isset($_GET['mode']) && $_GET['mode'] === 'guest') {
    // --- โหมด: ผู้มาเยือนเข้าสู่ระบบ (Guest Mode) ---
    $is_guest_login = true;

    // สร้าง Session สำหรับ Guest
    $_SESSION['user_mslog'] = [
        'username' => 'guest_user',
        'responsible_person' => 'ผู้มาเยือน', 
        'role' => 'guest', 
    ];

    header('Location: missions/mission_list.php?mode=all&filter=today');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <link rel="manifest" href="/manifest.json">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSLog : เข้าสู่ระบบ</title>
    <link rel="stylesheet" href="assets/css/style.css"> 
    <style>
        /* (CSS คงเดิม) */
        body {
            background-color: #f0f2f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            font-family: sans-serif;
            margin: 0;
            flex-direction: column; 
        }

        .login-container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            width: 90%;
            max-width: 350px;
            text-align: center;
        }

        h2 {
            color: #007bff;
            margin-bottom: 25px;
            font-size: 1.5em;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            font-size: 1em;
        }

        .btn-login {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
            transition: background-color 0.2s;
            margin-bottom: 0;
        }

        .btn-login:hover {
            background-color: #0056b3;
        }

        .error {
            color: #dc3545;
            font-weight: bold;
            text-align: center;
            margin-bottom: 15px;
        }
        
        .guest-section {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }
        .guest-link {
            display: block;
            padding: 8px 15px;
            background-color: #6c757d; 
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 0.9em;
            transition: background-color 0.2s;
            margin-top: 10px;
        }
        .guest-link:hover {
            background-color: #495057;
        }

        /* สไตล์ปุ่มติดตั้ง PWA */
        #installBtn {
            display: none; 
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #28a745;
            color: white;
            border: none;
            border-radius: 25px;
            font-weight: bold;
            box-shadow: 0 4px 6px rgba(0,0,0,0.2);
            cursor: pointer;
            font-size: 1em;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% {transform: translateY(0);}
            40% {transform: translateY(-5px);}
            60% {transform: translateY(-3px);}
        }
    </style>
</head>

<body>
    <div class="accessibility-controls">
        <div class="font-dropdown">
            <button id="btn-toggle-font" class="font-btn" title="ตั้งค่าขนาดอักษร">A</button>
            <div class="font-dropdown-content" id="font-options">
                <button id="btn-decrease-font" class="font-btn" title="ลดขนาดตัวอักษร">A-</button>
                <button id="btn-reset-font" class="font-btn" title="ขนาดตัวอักษรปกติ">A</button>
                <button id="btn-increase-font" class="font-btn" title="เพิ่มขนาดตัวอักษร">A+</button>
            </div>
        </div>
        <button class="theme-toggle" title="สลับโหมด"></button>
    </div>
    <div class="login-container">
        <h2>ระบบบันทึกภารกิจ (MSLog)</h2>
        <?php if ($error_message): ?>
            <p class="error"><?php echo $error_message; ?></p>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="username" placeholder="ชื่อผู้ใช้ (Username)" required>
            <input type="password" name="password" placeholder="รหัสผ่าน (Password)" required>
            <button type="submit" class="btn-login">เข้าสู่ระบบ</button>
        </form>

        <div class="guest-section">
            <p style="color:#6c757d; font-size:0.9em; margin-bottom: 0;">เข้าดูบันทึกภารกิจโดยไม่ต้องล็อกอิน:</p>
            <a href="index.php?mode=guest" class="guest-link">
                เข้าสู่ระบบในฐานะผู้มาเยือน (ดูอย่างเดียว)
            </a>
        </div>
    </div>

    <button id="installBtn">📲 ติดตั้งแอพลงมือถือ</button>

    <script>
        // 1. ลงทะเบียน Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js') 
                    .then(reg => {
                        console.log('SW Registered successfully: ', reg);
                        // 🔑 NEW: ตรวจสอบสถานะการทำงานของ Service Worker
                        if (reg.active) {
                            console.log('SW status: Active');
                        }
                    })
                    .catch(error => {
                        // 🔑 CRITICAL: แสดงข้อผิดพลาดการลงทะเบียน SW
                        console.error('Service Worker Registration failed:', error);
                    });
            });
        }

        // 2. จัดการปุ่ม Install
        let deferredPrompt;
        const installBtn = document.getElementById('installBtn');
        
        // 3. ตรวจสอบว่าติดตั้งแล้วหรือไม่เพื่อซ่อนปุ่ม
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
            installBtn.style.display = 'none';
        }

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            // แสดงปุ่มติดตั้งของเรา ถ้ายังไม่ติดตั้ง
            if (installBtn.style.display !== 'none') {
                 installBtn.style.display = 'block';
            }
        });

        installBtn.addEventListener('click', (e) => {
            installBtn.style.display = 'none';
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then((choiceResult) => {
                if (choiceResult.outcome === 'accepted') {
                    console.log('User accepted the A2HS prompt');
                } else {
                    console.log('User dismissed the A2HS prompt');
                }
                deferredPrompt = null;
            });
        });
    </script>

<script src="assets/js/theme-toggle.js"></script>
<script src="assets/js/font-toggle.js"></script>
</body>

</html>