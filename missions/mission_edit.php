<?php
session_start();
require_once '../config.php';
require_once '../auth.php'; // ตรวจสอบการล็อกอิน

// ตรวจสอบว่ามี ID ของภารกิจที่ต้องการแก้ไขหรือไม่
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: mission_list.php");
    exit();
}

$mission_id = $_GET['id'];
$user = $_SESSION['user_mslog'];
$responsible_person_session = $user['responsible_person']; // ผู้รับผิดชอบของผู้ที่ล็อกอิน
$user_role = strtolower($user['role'] ?? $user['level'] ?? '');
$is_admin = ($user_role === 'admin');

// ดึง mode และ filter จาก URL เพื่อใช้ในการ Redirect กลับไปหน้าเดิมหลังแก้ไข
$current_mode = $_GET['mode'] ?? 'current';
$current_filter = $_GET['filter'] ?? 'all_dates';
$redirect_url_view = "mission_view.php?id=" . urlencode($mission_id) . "&mode=" . urlencode($current_mode) . "&filter=" . urlencode($current_filter);
$redirect_url_edit = "mission_edit.php?id=" . urlencode($mission_id) . "&mode=" . urlencode($current_mode) . "&filter=" . urlencode($current_filter);


// -------------------------------------------------------------------
// --- 1. POST Request Handling (บันทึกการแก้ไข) ---
// -------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject']);
    $mission_date = $_POST['mission_date'];
    $end_date = $_POST['end_date'];
    $start_time = trim($_POST['start_time']);
    $end_time = trim($_POST['end_time']);
    $location = trim($_POST['location']);
    $responsible_person_new = trim($_POST['responsible_person']);
    $detail = trim($_POST['detail']);
    $status = $_POST['status'];

    // -------------------------------------------------------------------
    // *** 1.1 ตรวจสอบสิทธิ์การแก้ไข (Authorization Check) ***
    // -------------------------------------------------------------------
    // ดึงข้อมูลผู้รับผิดชอบเดิมของภารกิจนี้ก่อนบันทึก
    $auth_query = "SELECT responsible_person FROM missions WHERE id = ?";
    $auth_stmt = $mysqli->prepare($auth_query);
    $auth_stmt->bind_param("i", $mission_id);
    $auth_stmt->execute();
    $auth_result = $auth_stmt->get_result();

    if ($auth_result->num_rows === 0) {
        $_SESSION['error'] = "ไม่พบภารกิจที่ต้องการแก้ไข";
        header("Location: mission_list.php");
        exit();
    }
    
    $mission_owner_data = $auth_result->fetch_assoc();
    $mission_owner_responsible = $mission_owner_data['responsible_person'];
    $auth_stmt->close();
    
    // ตรวจสอบสิทธิ์: Admin หรือ ผู้รับผิดชอบตรงกัน
    $can_edit = $is_admin || ($responsible_person_session === $mission_owner_responsible);

    if (!$can_edit) {
        $_SESSION['error'] = "คุณไม่มีสิทธิ์ในการแก้ไขภารกิจ ID: {$mission_id} นี้ (ไม่ใช่ Admin และไม่ใช่ผู้รับผิดชอบ)";
        header("Location: " . $redirect_url_view); // Redirect ไปหน้า View เพื่อแสดงข้อผิดพลาด
        exit();
    }
    // -------------------------------------------------------------------

    // ตั้งค่าเวลาเป็น NULL ถ้าไม่มีการระบุ 
    $start_time_db = !empty($start_time) ? "{$start_time}:00" : NULL;
    $end_time_db = !empty($end_time) ? "{$end_time}:00" : NULL;

    // ตรวจสอบค่า end_date
    $end_date_db = !empty($end_date) ? $end_date : NULL;

    // ตรวจสอบความถูกต้องเบื้องต้น
    if (empty($subject) || empty($mission_date) || empty($responsible_person_new)) {
        $_SESSION['error'] = "กรุณากรอกหัวข้อ วันที่เริ่มต้น และผู้รับผิดชอบ";
        header("Location: " . $redirect_url_edit);
        exit();
    }

    // UPDATE Query ที่แก้ไขแล้ว (ลบคอมเมนต์ออกจากสตริง SQL)
    $update_query = "
        UPDATE missions SET 
            subject = ?, 
            mission_date = ?, 
            end_date = ?, 
            mission_time = ?, 
            end_time = ?, 
            location = ?, 
            responsible_person = ?, 
            detail = ?, 
            status = ? 
        WHERE id = ? 
    ";

    $stmt = $mysqli->prepare($update_query);

    if ($stmt === false) {
        $_SESSION['error'] = "SQL Prepare Error: " . $mysqli->error;
        header("Location: " . $redirect_url_edit);
        exit();
    }

    // 10 ตัวแปร: 9 strings, 1 int
    $stmt->bind_param(
        "sssssssssi",
        $subject,
        $mission_date,
        $end_date_db,
        $start_time_db,
        $end_time_db,
        $location,
        $responsible_person_new,
        $detail,
        $status,
        $mission_id
    );

    if ($stmt->execute()) {
        $_SESSION['message'] = "แก้ไขภารกิจ ID: {$mission_id} เรียบร้อยแล้ว";
        header("Location: " . $redirect_url_view);
        exit();
    } else {
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการบันทึก: " . $stmt->error;
        header("Location: " . $redirect_url_edit);
        exit();
    }
    $stmt->close();
}


// -------------------------------------------------------------------
// --- 2. GET Request Handling (ดึงข้อมูลเดิมมาแสดง) ---
// -------------------------------------------------------------------

// ดึงข้อมูลภารกิจปัจจุบัน
$query = "SELECT * FROM missions WHERE id = ?";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("i", $mission_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "ไม่พบภารกิจที่ต้องการแก้ไข";
    header("Location: mission_list.php");
    exit();
}

$mission_data = $result->fetch_assoc();
$stmt->close();

// แยกเวลาเริ่มต้นและเวลาสิ้นสุดเพื่อใส่ใน input field
$time_start = substr(htmlspecialchars($mission_data['mission_time']), 0, 5); // HH:mm
$time_end = substr(htmlspecialchars($mission_data['end_time']), 0, 5);       // HH:mm

// ดึงค่า end_date มาใช้ใน input field
$end_date_data = htmlspecialchars($mission_data['end_date']);
// ถ้า end_date เป็น '0000-00-00' ให้แสดงค่าว่างใน input field
$end_date_display = ($end_date_data === '0000-00-00') ? '' : $end_date_data;


// -------------------------------------------------------------------
// --- ดึงและจัดเรียงรายชื่อผู้รับผิดชอบตามแนวคิด mission_add ---
// -------------------------------------------------------------------

$responsible_query = "
    SELECT responsible_person, username
    FROM users 
    WHERE 
        responsible_person IS NOT NULL 
        AND responsible_person != ''
        AND username NOT IN ('admin', 'user') 
    ORDER BY 
        (username = 'sso04') ASC, /* ผลัก sso04 ลงท้ายสุด */
        FIELD(username, 'sso01', 'sso02', 'sso03', 'sso05', 'sso06', 'sso07', 'sso08', 'sso09'), 
        responsible_person ASC
";

$responsible_result = $mysqli->query($responsible_query);
$responsible_people = [];
while ($row = $responsible_result->fetch_assoc()) {
    $responsible_people[] = htmlspecialchars($row['responsible_person']);
}
// -------------------------------------------------------------------

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
    <title>MSLog: แก้ไขภารกิจ </title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            font-family: sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            /* FIX: ลบ overflow: hidden; เพื่อให้ scroll แนวตั้งได้เสมอ */
        }

        .container {
            max-width: 600px;
            width: 100%; /* FIX: ใช้ความกว้างเต็มที่บนมือถือ */
            box-sizing: border-box; /* FIX: ให้ padding ถูกรวมในความกว้าง */
            margin: 20px auto;
            padding: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        .header {
            background-color: #007bff;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 10px 10px 0 0;
            margin: -25px -25px 25px -25px;
        }

        .header h2 {
            margin: 0;
            font-size: 1.8em;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #444;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
            font-size: 1em;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        /* ปรับ layout สำหรับกลุ่มวันที่และเวลา */
        .date-time-wrapper {
            display: flex;
            gap: 15px;
        }

        .date-group {
            flex: 1;
        }

        .time-group {
            flex: 1;
            display: flex;
            gap: 10px;
        }

        .time-group>div {
            flex: 1;
        }

        /* FIX: ใช้ Flexbox จัดเรียงปุ่ม และเพิ่มเส้นแบ่ง */
        .action-buttons {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #eee;
            display: flex; 
            justify-content: flex-end; /* จัดปุ่มไปทางขวา */
            gap: 10px; /* ระยะห่างระหว่างปุ่ม */
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s;
            white-space: nowrap; /* FIX: ป้องกันข้อความในปุ่มขึ้นบรรทัดใหม่ */
        }

        .btn-submit {
            background-color: #007bff;
            color: white;
        }

        .btn-submit:hover {
            background-color: #0056b3;
        }

        .btn-cancel {
            background-color: #6c757d;
            color: white;
            /* ลบ margin-right: 10px; เพราะใช้ gap: 10px แทน */
        }

        .btn-cancel:hover {
            background-color: #5a6268;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #f5c6cb;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
            border: 1px solid #c3e6cb;
        }

        /* *** Media Query สำหรับมือถือ (หน้าจอแคบ) *** */
        @media (max-width: 650px) {
            .container {
                /* FIX: ไม่มี margin ด้านข้าง และ padding ลดลงเล็กน้อย */
                margin: 0; 
                padding: 15px; 
                border-radius: 0; 
                box-shadow: none; 
            }

            .header {
                /* FIX: ปรับ negative margin ให้เข้ากับ padding ใหม่ */
                margin: -15px -15px 15px -15px; 
                padding: 15px; 
            }

            .date-time-wrapper {
                /* จัดให้วันที่เรียงแนวตั้งบนมือถือเพื่อลดความกว้าง */
                flex-direction: column;
                gap: 5px;
            }
            .time-group {
                /* จัดให้เวลากลับมาเรียงกันในแนวนอน */
                flex-direction: row;
                gap: 10px;
            }
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
    <div class="container">
        <div class="header">
            <h2>แก้ไขภารกิจ </h2>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form
            action="<?php echo $redirect_url_edit; ?>"
            method="POST">

            <div class="form-group">
                <label for="subject">หัวข้อภารกิจ <span style="color: red;">*</span></label>
                <input type="text" id="subject" name="subject"
                    value="<?php echo htmlspecialchars($mission_data['subject']); ?>" required>
            </div>

            <div class="form-group">
                <div class="date-time-wrapper">
                    <div class="date-group">
                        <label for="mission_date" style="font-weight: normal;">วันที่เริ่ม <span style="color: red;">*</span></label>
                        <input type="date" id="mission_date" name="mission_date"
                            value="<?php echo htmlspecialchars($mission_data['mission_date']); ?>" required>
                    </div>
                    <div class="date-group">
                        <label for="end_date" style="font-weight: normal;">ถึงวันที่</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo $end_date_display; ?>">
                        <small style="color: #6c757d;">*ถ้าภารกิจวันเดียว ให้ปล่อยช่องนี้ว่างไว้</small>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <div class="time-group">
                    <div>
                        <label for="start_time" style="font-weight: normal;">เวลาเริ่ม</label>
                        <input type="time" id="start_time" name="start_time"
                            value="<?php echo htmlspecialchars($time_start); ?>">
                    </div>
                    <div>
                        <label for="end_time" style="font-weight: normal;">ถึงเวลา</label>
                        <input type="time" id="end_time" name="end_time"
                            value="<?php echo htmlspecialchars($time_end); ?>">
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="location">ที่ไหน</label>
                <input type="text" id="location" name="location"
                    value="<?php echo htmlspecialchars($mission_data['location']); ?>">
            </div>

            <div class="form-group">
                <label for="responsible_person">ใคร <span style="color: red;">*</span></label>
                <select id="responsible_person" name="responsible_person" required>
                    <?php
                    // ค่าผู้รับผิดชอบปัจจุบันที่ดึงมาจากฐานข้อมูล
                    $current_responsible = $mission_data['responsible_person'];

                    // *** FIX: เพิ่มตัวเลือก 'เจ้าหน้าที่ทุกคน' และเช็คว่าต้องถูกเลือกหรือไม่ ***
                    $selected_all = ($current_responsible === 'เจ้าหน้าที่ทุกคน') ? 'selected' : '';
                    echo '<option value="เจ้าหน้าที่ทุกคน" ' . $selected_all . '>เจ้าหน้าที่ทุกคน</option>';
                    // ***************************************************************

                    foreach ($responsible_people as $person):
                        // Logic: ถ้า $person ตรงกับผู้รับผิดชอบปัจจุบัน และไม่ใช่ 'เจ้าหน้าที่ทุกคน' ให้ 'selected'
                        $selected = ($person === $current_responsible) ? 'selected' : '';
                        ?>
                        <option value="<?php echo $person; ?>" <?php echo $selected; ?>>
                            <?php echo $person; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="detail">อย่างไร</label>
                <textarea id="detail" name="detail"><?php echo htmlspecialchars($mission_data['detail']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="status">สถานะ</label>
                <select id="status" name="status">
                    <?php
                    $statuses = ['รอดำเนินการ', 'กำลังดำเนินการ', 'เสร็จสิ้น', 'ยกเลิก'];
                    foreach ($statuses as $status_option): ?>
                        <option value="<?php echo $status_option; ?>" <?php echo ($mission_data['status'] === $status_option) ? 'selected' : ''; ?>>
                            <?php echo $status_option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="action-buttons">
                <a href="<?php echo $redirect_url_view; ?>" class="btn btn-cancel">ยกเลิก</a>
                <button type="submit" class="btn btn-submit">บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
    <script src="../assets/js/theme-toggle.js"></script>
    <script src="../assets/js/font-toggle.js"></script>
</body>

</html>