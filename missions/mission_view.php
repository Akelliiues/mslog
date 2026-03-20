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
$responsible_person_session = $user['responsible_person']; // ผู้รับผิดชอบจาก Session
$user_role = strtolower($user['role'] ?? $user['level'] ?? ''); // ดึงบทบาทผู้ใช้ (Admin Check)

// ตรวจสอบสิทธิ์ Admin เบื้องต้น
$is_admin = ($user_role === 'admin');

// ตัวแปรควบคุมสิทธิ์ (เริ่มต้น Admin ทำได้เสมอ)
$can_delete = $is_admin;
$can_edit = $is_admin; 

// ดึง mode และ filter จาก URL เพื่อใช้ในการ Redirect กลับไปหน้าเดิม
$current_mode = $_GET['mode'] ?? 'current';
$current_filter = $_GET['filter'] ?? 'all_dates';
$redirect_url_list = "mission_list.php?mode=" . urlencode($current_mode) . "&filter=" . urlencode($current_filter);


// -------------------------------------------------------------------
// --- 1.1 ฟังก์ชันแปลงวันที่เป็นรูปแบบเต็มภาษาไทย (พ.ศ.) ---
// -------------------------------------------------------------------
// กำหนด array ชื่อวันและเดือนนอกฟังก์ชันเพื่อให้ใช้งานร่วมกันได้ง่าย
$thaiDays = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
$thaiMonths = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];

function formatFullThaiDate($dateString)
{
    global $thaiDays, $thaiMonths;

    if (!$dateString || $dateString === '0000-00-00') {
        return '-';
    }

    $timestamp = strtotime($dateString);

    // 1. ชื่อวัน (อาทิตย์ - เสาร์)
    $dayIndex = date('w', $timestamp);
    $dayName = $thaiDays[$dayIndex];

    // 2. ชื่อเดือน (เต็ม)
    $monthIndex = (int) date('n', $timestamp);
    $monthName = $thaiMonths[$monthIndex];

    // 3. วันที่และปี พ.ศ.
    $day = date('j', $timestamp);
    $yearAD = date('Y', $timestamp);
    $yearBE = $yearAD + 543; // ปีพุทธศักราช

    return "วัน{$dayName}ที่ {$day} {$monthName} {$yearBE}";
}

// ฟังก์ชันแสดงช่วงวันที่สำหรับหน้า View
function formatMissionDateRangeView($start_date_string, $end_date_string)
{
    $start_full_formatted = formatFullThaiDate($start_date_string);

    if (!$end_date_string || $end_date_string === '0000-00-00' || $start_date_string === $end_date_string) {
        // กรณีภารกิจวันเดียว
        return $start_full_formatted;
    }

    // กรณีภารกิจหลายวัน (2 วันขึ้นไป)
    $end_full_formatted = formatFullThaiDate($end_date_string);

    return "{$start_full_formatted} - {$end_full_formatted}";
}


// -------------------------------------------------------------------
// --- 1.2 ฟังก์ชันแปลงวันที่เป็นรูปแบบย่อภาษาไทย (3 ต.ค.68) ---
// -------------------------------------------------------------------
function formatShortThaiDate($datetimeString)
{
    if (!$datetimeString || $datetimeString === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($datetimeString);

    // ชื่อเดือนย่อ
    $thaiMonthsShort = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    $monthIndex = (int) date('n', $timestamp);
    $monthName = $thaiMonthsShort[$monthIndex];

    // วันที่และปี พ.ศ. สองหลัก
    $day = date('j', $timestamp);
    $yearAD = date('Y', $timestamp);
    $yearBE_short = substr(($yearAD + 543), 2, 2);

    // ผลลัพธ์: 3 ต.ค.68
    return "{$day} {$monthName}{$yearBE_short}";
}
// -------------------------------------------------------------------
// --- 2. GET Request Handling (ดึงข้อมูลภารกิจพร้อมชื่อผู้สร้าง) ---
// -------------------------------------------------------------------

// Join กับตาราง users เพื่อดึง responsible_person ของผู้สร้าง (created_by)
$query = "
    SELECT 
        m.*, 
        u.responsible_person AS creator_responsible_person 
    FROM missions m
    LEFT JOIN users u ON m.created_by = u.username
    WHERE m.id = ?
";
$stmt = $mysqli->prepare($query);
$stmt->bind_param("i", $mission_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "ไม่พบภารกิจที่ต้องการดูรายละเอียด";
    header("Location: " . $redirect_url_list);
    exit();
}

$mission_data = $result->fetch_assoc();
$stmt->close();

// *** กำหนดสิทธิ์การแก้ไข/ลบขั้นสุดท้ายหลังดึงข้อมูลภารกิจ ***
$mission_responsible_person = htmlspecialchars($mission_data['responsible_person']);

// ถ้าไม่ใช่ Admin ให้ตรวจสอบว่าเป็นผู้รับผิดชอบภารกิจนี้เองหรือไม่
if (!$can_delete) {
    if ($mission_responsible_person === $responsible_person_session) {
        $can_delete = true;
    }
}
if (!$can_edit) { // ใช้ตรรกะเดียวกันสำหรับปุ่มแก้ไข
    if ($mission_responsible_person === $responsible_person_session) {
        $can_edit = true;
    }
}
// **********************************************************


// แยกเวลาเริ่มต้นและเวลาสิ้นสุด
$time_start = substr(htmlspecialchars($mission_data['mission_time']), 0, 5); // HH:mm
$time_end = substr(htmlspecialchars($mission_data['end_time']), 0, 5);       // HH:mm

// สร้างข้อความช่วงเวลา
$time_display = '';
if (!empty($time_start) && !empty($time_end)) {
    $time_display = "{$time_start} - {$time_end} น.";
} elseif (!empty($time_start)) {
    $time_display = "{$time_start} น.";
}

// กำหนด Class, Text และ Color สำหรับ Status Bar
$status_text = htmlspecialchars($mission_data['status']);
$status_class = '';
$status_text_color = 'white'; // ค่าเริ่มต้นเป็นสีขาว

if ($status_text === 'กำลังดำเนินการ') {
    $status_class = 'status-processing';
    $status_text_color = '#333'; // เปลี่ยนเป็นสีเข้มเพื่อให้เห็นชัดเจนบนพื้นหลังสีเหลืองอ่อน
} elseif ($status_text === 'เสร็จสิ้น') {
    $status_class = 'status-completed';
} elseif ($status_text === 'ยกเลิก') {
    $status_class = 'status-cancelled';
} else {
    $status_class = 'status-pending';
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
    <title>MSLog: รายละเอียดภารกิจ</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            font-family: sans-serif;
            background-color: #f0f2f5;
            margin: 0;
            /* แก้ไข: ลบ overflow: hidden; เพื่อให้ scroll แนวตั้งได้เสมอ */
        }

        .container {
            max-width: 650px;
            width: 100%; /* FIX: ใช้ความกว้างเต็มที่บนมือถือ */
            box-sizing: border-box; /* FIX: ให้ padding ถูกรวมในความกว้าง */
            margin: 20px auto;
            padding: 25px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
            /* min-height: 100vh; /* เพื่อให้เห็น Status Bar/Footer แม้เนื้อหาน้อย */
        }

        .header {
            background-color: #007bff;
            color: white;
            padding: 20px;
            text-align: center;
            border-radius: 10px 10px 0 0;
            /* FIX: margin จะถูกปรับใน Media Query */
            margin: -25px -25px 25px -25px; 
        }

        .header h2 {
            margin: 0;
            font-size: 1.8em;
        }

        /* ซ่อนแถวที่แสดง ID: */
        .header p {
            display: none;
            margin-top: 5px;
            font-size: 0.9em;
            opacity: 0.8;
        }

        .detail-row {
            margin-bottom: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            display: flex;
            align-items: flex-start;
        }

        .detail-row:last-of-type {
            border-bottom: 1px solid #eee;
            margin-bottom: 0;
        }

        .detail-row strong {
            font-weight: bold;
            color: #333;
            min-width: 120px;
            font-size: 1em;
            padding-right: 15px;
        }

        .detail-row span {
            flex-grow: 1;
            color: #555;
            font-size: 1em;
            line-height: 1.5;
            word-wrap: break-word; /* เพื่อให้ข้อความยาวๆ ขึ้นบรรทัดใหม่ได้ */
            min-width: 0;
        }

        .subject {
            font-size: 1.4em;
            font-weight: bold;
            color: #0056b3;
            margin-bottom: 20px;
            border-left: 5px solid #007bff;
            padding-left: 10px;
        }

        /* CSS สำหรับแถบคาดสถานะเต็มความกว้างและจัดกลาง */
        .status-bar-full {
            /* ลบ padding ของ container ออกชั่วคราวเพื่อให้คาดเต็ม */
            margin: 20px -25px -25px -25px;
            padding: 15px 0;

            /* จัดการข้อความ */
            text-align: center;
            font-weight: bold;
            font-size: 1.2em;

            /* การออกแบบแถบคาด */
            border-radius: 0 0 10px 10px;
            /* ให้โค้งเฉพาะด้านล่างของการ์ด */
        }

        /* สไตล์สีพื้นหลังของแต่ละสถานะ */
        .status-bar-full.status-pending {
            background-color: #007bff;
        }

        .status-bar-full.status-processing {
            background-color: #ffc107;
        }

        /* พื้นหลังสีเหลือง */
        .status-bar-full.status-completed {
            background-color: #28a745;
        }

        .status-bar-full.status-cancelled {
            background-color: #dc3545;
        }

        /* FIX: ใช้ Flexbox จัดเรียงปุ่ม */
        .action-buttons {
            margin-top: 30px;
            border-top: 1px solid #eee;
            padding-top: 20px;
            display: flex; 
            justify-content: center; 
            flex-wrap: wrap; /* ให้ปุ่มสามารถขึ้นบรรทัดใหม่ได้ หากจอแคบเกินไปจริง ๆ */
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

        .btn-edit {
            background-color: #ffc107;
            color: #333;
        }

        .btn-edit:hover {
            background-color: #e0a800;
        }

        .btn-back {
            background-color: #6c757d;
            color: white;
        }

        .btn-back:hover {
            background-color: #5a6268;
        }
        
        /* *** NEW: CSS สำหรับปุ่มลบ (สีแดง) *** */
        .btn-delete {
            background-color: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background-color: #c82333;
        }
        /* ************************************ */

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
        @media (max-width: 700px) {
            .container {
                /* FIX: ไม่ต้องมี margin ด้านข้าง และ padding ลดลงเล็กน้อย */
                margin: 0; 
                padding: 15px; 
                border-radius: 0; 
                box-shadow: none; 
            }
            
            /* FIX: ปรับ negative margin ให้เข้ากับ padding ใหม่ */
            .header {
                margin: -15px -15px 15px -15px; 
                padding: 15px; 
            }
            .status-bar-full {
                margin: 20px -15px -15px -15px; 
            }
        }
    </style>
</head>

<body>
    <button class="theme-toggle" title="สลับโหมด"></button>
    <div class="container">
        <div class="header">
            <h2>รายละเอียดภารกิจ</h2>
            <p></p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="subject">
            <?php echo htmlspecialchars($mission_data['subject']); ?>
        </div>

        <div class="detail-row">
            <strong>📅 วันที่:</strong>
            <span>
                <?php 
                echo formatMissionDateRangeView($mission_data['mission_date'], $mission_data['end_date']); 
                ?>
            </span>
        </div>

        <div class="detail-row">
            <strong>⏱️ เวลา:</strong>
            <span>
                <?php echo (!empty($time_display) ? $time_display : '-'); ?>
            </span>
        </div>

        <div class="detail-row">
            <strong>👤 ใคร:</strong>
            <span>
                <?php echo htmlspecialchars($mission_data['responsible_person']); ?>
            </span>
        </div>

        <div class="detail-row">
            <strong>📍 ที่ไหน:</strong>
            <span>
                <?php echo (!empty($mission_data['location']) ? htmlspecialchars($mission_data['location']) : '-'); ?>
            </span>
        </div>

        <?php if (!empty($mission_data['detail'])): ?>
            <div class="detail-row">
                <strong>📝 อย่างไร:</strong>
                <span>
                    <?php
                    echo nl2br(htmlspecialchars($mission_data['detail']));
                    ?>
                </span>
            </div>
        <?php endif; ?>

        <div class="detail-row">
            <strong>ข้อมูลโดย:</strong>
            <span>
                <?php
                $creator_name = !empty($mission_data['creator_responsible_person']) ? $mission_data['creator_responsible_person'] : $mission_data['created_by'];
                echo htmlspecialchars($creator_name);
                echo " (" . formatShortThaiDate($mission_data['created_at']) . ")";
                ?>
            </span>
        </div>

        <div class="status-bar-full <?php echo $status_class; ?>" style="color: <?php echo $status_text_color; ?>;">
            <?php echo $status_text; ?>
        </div>
        
        <div class="action-buttons">
            <a href="<?php echo $redirect_url_list; ?>" class="btn btn-back">ย้อนกลับ</a>
            
            <?php if ($can_edit): ?>
            <a href="mission_edit.php?id=<?php echo $mission_id; ?>&mode=<?php echo htmlspecialchars($current_mode); ?>&filter=<?php echo htmlspecialchars($current_filter); ?>"
                class="btn btn-edit">แก้ไข</a>
            <?php endif; ?>
            
            <?php if ($can_delete): ?>
            <a href="mission_delete.php?id=<?php echo $mission_id; ?>&mode=<?php echo htmlspecialchars($current_mode); ?>&filter=<?php echo htmlspecialchars($current_filter); ?>"
                onclick="return confirm('คุณแน่ใจหรือไม่ที่จะลบภารกิจ ID: <?php echo $mission_id; ?> นี้? การดำเนินการนี้ไม่สามารถย้อนกลับได้');"
                class="btn btn-delete">ลบ</a>
            <?php endif; ?>
        </div>
    </div>
    <script src="../assets/js/theme-toggle.js"></script>
</body>

</html>