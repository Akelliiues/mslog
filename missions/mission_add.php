<?php
session_start();
require_once '../config.php';
require_once '../auth.php'; 

// ดึงข้อมูลจาก Session
$user = $_SESSION['user_mslog'];
$username = $user['username'];
$user_role = strtolower($user['role'] ?? $user['level'] ?? ''); // ดึง role มาใช้

// *** [1. แก้ไขสำคัญ: ป้องกันผู้มาเยือน (Guest) เข้าถึง] ***
if ($user_role === 'guest') {
    $_SESSION['error'] = "ผู้มาเยือนไม่ได้รับอนุญาตให้เพิ่มภารกิจ";
    // Redirect กลับไปหน้า list ในโหมดที่ Guest ดูได้
    header("Location: mission_list.php?mode=all&filter=today");
    exit();
}

// -------------------------------------------------------------------
// --- 1. POST Request Handling (บันทึกภารกิจ) ---
// -------------------------------------------------------------------

// *** [2. แก้ไข: ดึงค่าที่เคยกรอกจาก POST หรือ Session เพื่อใช้ในฟอร์ม] ***
$form_data = $_SESSION['form_data'] ?? [
    'subject' => '',
    'mission_date' => date('Y-m-d'),
    'end_date' => '',
    'start_time' => '08:30',
    'end_time' => '16:30',
    'location' => '',
    'responsible_person' => '',
    'detail' => ''
];
unset($_SESSION['form_data']); // ลบข้อมูลที่เก็บไว้หลังดึงมาใช้

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ดึงข้อมูลจาก POST
    $subject = trim($_POST['subject']);
    $mission_date = $_POST['mission_date'];
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : NULL;
    $start_time = trim($_POST['start_time']); 
    $end_time = trim($_POST['end_time']);
    $location = trim($_POST['location']);
    $responsible_person_new = trim($_POST['responsible_person']); 
    $detail = trim($_POST['detail']);

    // เก็บค่าที่ผู้ใช้กรอกไว้ใน Session เผื่อมี Error (เฉพาะกรณีที่ไม่ใช่ Sync Request)
    if (!isset($_POST['sync_request'])) {
        $_SESSION['form_data'] = $_POST;
    }

    // ตั้งค่าเวลาเป็น NULL ถ้าไม่มีการระบุ
    $start_time_db = !empty($start_time) ? "{$start_time}:00" : NULL;
    $end_time_db = !empty($end_time) ? "{$end_time}:00" : NULL;
    $default_status = 'รอดำเนินการ'; 
    $current_user_username = $user['username'];
    
    // ตรวจสอบความถูกต้องเบื้องต้น
    if (empty($subject) || empty($mission_date) || empty($responsible_person_new)) {
        $_SESSION['error'] = "กรุณากรอก ทำอะไร, เมื่อไหร่, และ ใคร";
        
        // 🔑 NEW: ถ้าเป็น Sync Request ให้ตอบกลับด้วย Error Code
        if (isset($_POST['sync_request']) && $_POST['sync_request'] === 'true') {
            http_response_code(400); 
            echo json_encode(['error' => 'Data incomplete: subject, date, or person missing.']);
            exit();
        }

        header("Location: mission_add.php");
        exit();
    }
    
    // VALIDATION: ตรวจสอบว่า end_date ไม่มาก่อน mission_date (ถ้ามีการระบุ end_date)
    if ($end_date && $end_date < $mission_date) {
        $_SESSION['error'] = "วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มต้น";
        
        if (isset($_POST['sync_request']) && $_POST['sync_request'] === 'true') {
            http_response_code(400); 
            echo json_encode(['error' => 'End date cannot be before start date.']);
            exit();
        }

        header("Location: mission_add.php");
        exit();
    }

    // MODIFIED: แก้ไข INSERT Query เพื่อเพิ่มคอลัมน์ end_date
    $insert_query = "
        INSERT INTO missions (
            subject, mission_date, end_date, mission_time, end_time, location, 
            responsible_person, detail, status, created_by, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ";
    
    $stmt = $mysqli->prepare($insert_query);
    
    if ($stmt === false) {
        $_SESSION['error'] = "SQL Prepare Error: " . $mysqli->error;
        if (isset($_POST['sync_request']) && $_POST['sync_request'] === 'true') {
            http_response_code(500); 
            echo json_encode(['error' => 'SQL Prepare Error.']);
            exit();
        }
        header("Location: mission_add.php");
        exit();
    }
    
    // 10 Strings: subject, date, end_date, start_time, end_time, location, person, detail, status, created_by
    $stmt->bind_param(
        "ssssssssss", 
        $subject, 
        $mission_date, 
        $end_date, 
        $start_time_db, 
        $end_time_db, 
        $location, 
        $responsible_person_new, 
        $detail, 
        $default_status,
        $current_user_username
    );

    if ($stmt->execute()) {
        $new_id = $mysqli->insert_id;
        $_SESSION['message'] = "บันทึกภารกิจใหม่ ID: {$new_id} เรียบร้อยแล้ว";
        
        // *** ลบ Session form_data ที่อาจเหลืออยู่ก่อน Redirect สำเร็จ ***
        unset($_SESSION['form_data']); 

        // 🔑 NEW: ตรวจสอบว่าเป็นการซิงค์ในเบื้องหลังหรือไม่
        if (isset($_POST['sync_request']) && $_POST['sync_request'] === 'true') {
            http_response_code(200); // ส่งรหัส 200 กลับไป Service Worker เพื่อยืนยันความสำเร็จ
            exit(); // ไม่ต้อง Redirect
        }
        
        header("Location: mission_list.php?mode=current&filter=all_dates"); 
        exit();
    } else {
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการบันทึก: " . $stmt->error;
        
        if (isset($_POST['sync_request']) && $_POST['sync_request'] === 'true') {
            http_response_code(500); 
            echo json_encode(['error' => 'Database execution error.']);
            exit();
        }
        
        header("Location: mission_add.php");
        exit();
    }
    $stmt->close();
}


// -------------------------------------------------------------------
// --- 2. GET Request Handling (เตรียมข้อมูล) ---
// -------------------------------------------------------------------

// ดึงรายชื่อผู้รับผิดชอบ
$responsible_query = "
    SELECT DISTINCT responsible_person, username
    FROM users 
    WHERE 
        responsible_person IS NOT NULL 
        AND responsible_person != ''
        AND username NOT IN ('admin', 'user') 
    ORDER BY 
        (username = 'sso04') ASC,
        FIELD(username, 'sso01', 'sso02', 'sso03', 'sso05', 'sso06', 'sso07', 'sso08', 'sso09', 'sso10'), 
        responsible_person ASC
";

$responsible_result = $mysqli->query($responsible_query);
$responsible_people = [];
while ($row = $responsible_result->fetch_assoc()) {
    $responsible_people[] = htmlspecialchars($row['responsible_person']);
}

// ตรวจสอบข้อความแจ้งเตือน (ถ้ามี)
$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message']);
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSLog: สร้างภารกิจใหม่</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ... (ส่วน CSS คงเดิม) ... */
        * {
            box-sizing: border-box; 
        }

        body { 
            font-family: sans-serif; 
            background-color: #f0f2f5; 
            margin: 0; 
        }

        .container { 
            width: 95%; 
            max-width: 600px; 
            margin: 20px auto; 
            padding: 15px; 
            background: white; 
            border-radius: 10px; 
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15); 
        }

        .header { background-color: #28a745; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; margin: -15px -15px 15px -15px; } 
        .header h2 { margin: 0; font-size: 1.8em; }
        
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; color: #444; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box; 
            font-size: 1em;
        }
        .form-group textarea { resize: vertical; min-height: 100px; }
        
        .time-group { display: flex; gap: 10px; }
        .time-group > div { flex: 1; }
        
        .action-buttons { text-align: right; margin-top: 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; font-weight: bold; transition: background-color 0.3s; }
        .btn-submit { background-color: #28a745; color: white; }
        .btn-submit:hover { background-color: #1e7e34; }
        .btn-cancel { background-color: #6c757d; color: white; margin-right: 10px; }
        .btn-cancel:hover { background-color: #5a6268; }

        .alert-error { background-color: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #f5c6cb; }
        .alert-success { background-color: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #c3e6cb; }
    </style>
</head>
<body>
    <div class="accessibility-controls">
        <div class="font-dropdown">
            <button id="btn-toggle-font" class="font-btn" title="ตั้งค่าขนาดอักษร">Aa</button>
            <div class="font-dropdown-content" id="font-options">
                <button id="btn-decrease-font" class="font-btn" title="ลดขนาดตัวอักษร">A-</button>
                <button id="btn-reset-font" class="font-btn" title="ขนาดตัวอักษรปกติ">A</button>
                <button id="btn-increase-font" class="font-btn" title="เพิ่มขนาดตัวอักษร">A+</button>
            </div>
        </div>
        <button class="theme-toggle" title="สลับโหมด"></button>
    </div>
    <div class="container">
        <div class="header">
            <h2>สร้างภารกิจใหม่</h2>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form action="mission_add.php" method="POST">
            
            <div class="form-group">
                <input 
                    type="text" 
                    id="subject" 
                    name="subject" 
                    placeholder="**ระบุชื่อภารกิจหรืองานที่ต้องทำ" 
                    value="<?php echo htmlspecialchars($form_data['subject']); ?>" 
                    required>
            </div>

            <div class="form-group">
                <div class="time-group">
                    <div>
                        <label for="mission_date" style="font-weight: normal;">วันที่เริ่ม</label>
                        <input type="date" id="mission_date" name="mission_date" 
                            value="<?php echo htmlspecialchars($form_data['mission_date']); ?>" required>
                    </div>
                    <div>
                        <label for="end_date" style="font-weight: normal;">ถึงวันที่</label> 
                        <input 
                            type="date" 
                            id="end_date" 
                            name="end_date" 
                            value="<?php echo htmlspecialchars($form_data['end_date']); ?>"
                            onfocus="this.showPicker()">
                            
                        <small style="display: block; margin-top: 5px; color: #6c757d; font-size: 0.85em;">
                            *ถ้าภารกิจวันเดียว ให้ปล่อยช่องนี้ว่างไว้
                        </small>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="time-group">
                    <div>
                        <label for="start_time" style="font-weight: normal;">เวลาเริ่ม</label>
                        <input type="time" id="start_time" name="start_time" 
                            value="<?php echo htmlspecialchars($form_data['start_time']); ?>">
                    </div>
                    <div>
                        <label for="end_time" style="font-weight: normal;">ถึงเวลา</label>
                        <input type="time" id="end_time" name="end_time" 
                            value="<?php echo htmlspecialchars($form_data['end_time']); ?>"> 
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="location"></label>
                <input 
                    type="text" 
                    id="location" 
                    name="location"
                    placeholder="**ระบุว่าต้องไปที่ไหน"
                    value="<?php echo htmlspecialchars($form_data['location']); ?>">
            </div>

            <div class="form-group">
                <select id="responsible_person" name="responsible_person" required>
                    <option value="">-- เลือกผู้ปฏิบัติ --</option> 
                    <option value="เจ้าหน้าที่ทุกคน" 
                        <?php if ($form_data['responsible_person'] === 'เจ้าหน้าที่ทุกคน') echo 'selected'; ?>>
                        เจ้าหน้าที่ทุกคน
                    </option> 
                    <?php 
                    foreach ($responsible_people as $person): 
                    ?>
                        <option value="<?php echo $person; ?>"
                            <?php if ($form_data['responsible_person'] === $person) echo 'selected'; ?>>
                            <?php echo $person; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="detail"></label>
                <textarea 
                    id="detail" 
                    name="detail"
                    placeholder="**โปรดระบุว่าต้องทำอะไร/อย่างไรหรือต้องพาใครไปด้วย.."><?php echo htmlspecialchars($form_data['detail']); ?></textarea>
            </div>

            <div class="action-buttons">
                <a href="mission_list.php" class="btn btn-cancel">ยกเลิก</a>
                <button type="submit" class="btn btn-submit">บันทึกภารกิจ</button>
            </div>
        </form>
    </div>
    
    <script>
        const FORM = document.querySelector('form');
        // ใช้ Key เดียวกับที่ Service Worker ใช้
        const FORM_DATA_KEY = 'mslog_offline_mission'; 

        FORM.addEventListener('submit', function(e) {
            // ตรวจสอบสถานะการเชื่อมต่อ
            if (!navigator.onLine) {
                e.preventDefault();
                
                // 1. Serialize form data
                const formData = new FormData(FORM);
                const missionData = {};
                for (let pair of formData.entries()) {
                    missionData[pair[0]] = pair[1];
                }
                
                // 2. Save to localStorage
                localStorage.setItem(FORM_DATA_KEY, JSON.stringify(missionData));
                
                // 3. Register Background Sync
                if ('serviceWorker' in navigator && 'SyncManager' in window) {
                    navigator.serviceWorker.ready.then(reg => {
                        return reg.sync.register('new-mission-sync')
                    })
                    .then(() => {
                        alert('ภารกิจถูกบันทึกชั่วคราวแล้ว จะซิงค์อัตโนมัติเมื่อออนไลน์');
                        // Redirect ไปหน้า list หลังจาก Queue สำเร็จ
                        window.location.href = 'mission_list.php?mode=current&filter=all_dates';
                    })
                    .catch(err => {
                        console.error('Sync registration failed:', err);
                        alert('ไม่สามารถลงทะเบียนซิงค์ได้! กรุณาตรวจสอบการเชื่อมต่ออินเทอร์เน็ตแล้วลองเปิดแอปใหม่');
                    });
                } else {
                    alert('ไม่รองรับ Background Sync. ภารกิจถูกบันทึกในเครื่อง แต่ต้องซิงค์เองเมื่อออนไลน์');
                }
            }
            // ถ้าออนไลน์ จะทำการ submit form ตามปกติ
        });
    </script>
    <script src="../assets/js/theme-toggle.js"></script>
    <script src="../assets/js/font-toggle.js"></script>
</body>
</html>