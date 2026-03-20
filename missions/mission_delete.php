<?php
session_start();
require_once '../config.php';
require_once '../auth.php'; // ตรวจสอบว่ามีการล็อกอิน

// 1. ตรวจสอบ ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ไม่พบ ID ภารกิจที่ต้องการลบ";
    header("Location: mission_list.php");
    exit();
}

$mission_id = $_GET['id'];
$user = $_SESSION['user_mslog']; // ดึงข้อมูลผู้ใช้จาก Session
$responsible_person_session = $user['responsible_person']; // ผู้รับผิดชอบของผู้ที่ล็อกอิน
$user_role = strtolower($user['role'] ?? $user['level'] ?? ''); // บทบาทของผู้ที่ล็อกอิน (Admin)


// 2. ดึงข้อมูลภารกิจเพื่อตรวจสอบว่าใครคือผู้รับผิดชอบ
$check_query = "SELECT responsible_person FROM missions WHERE id = ?";
$check_stmt = $mysqli->prepare($check_query);
$check_stmt->bind_param("i", $mission_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    $_SESSION['error'] = "ไม่พบภารกิจที่ต้องการลบในระบบ";
    header("Location: mission_list.php");
    exit();
}

$mission_data = $check_result->fetch_assoc();
$mission_responsible_person = $mission_data['responsible_person'];
$check_stmt->close();


// 3. *** ตรวจสอบสิทธิ์ (Authorization Check) ***
$is_admin = ($user_role === 'admin');

// เงื่อนไขการอนุญาตให้ลบ: Admin หรือ ผู้รับผิดชอบภารกิจคนนี้ตรงกับผู้ที่ล็อกอินอยู่
$can_delete = $is_admin || ($responsible_person_session === $mission_responsible_person);


if (!$can_delete) {
    $_SESSION['error'] = "คุณไม่มีสิทธิ์ในการลบภารกิจ ID: {$mission_id} นี้ (ไม่ใช่ Admin และไม่ใช่ผู้รับผิดชอบ)";
    
    // ตั้งค่า Redirect กลับไปที่หน้า View เพื่อแสดงข้อความผิดพลาด
    $redirect_url_view = "mission_view.php?id=" . urlencode($mission_id);
    $redirect_url_view .= "&mode=" . urlencode($_GET['mode'] ?? 'current') . "&filter=" . urlencode($_GET['filter'] ?? 'all_dates');

    header("Location: " . $redirect_url_view);
    exit();
}
// *******************************************************************


// ดึง mode และ filter จาก URL เพื่อใช้ในการ Redirect กลับไปหน้า List
$current_mode = $_GET['mode'] ?? 'current';
$current_filter = $_GET['filter'] ?? 'all_dates';
$redirect_url_list = "mission_list.php?mode=" . urlencode($current_mode) . "&filter=" . urlencode($current_filter);


// 4. หากผ่านการตรวจสอบสิทธิ์: รันคำสั่ง DELETE
$delete_query = "DELETE FROM missions WHERE id = ?";
$stmt = $mysqli->prepare($delete_query);

if ($stmt === false) {
    $_SESSION['error'] = "SQL Prepare Error: " . $mysqli->error;
    header("Location: " . $redirect_url_list);
    exit();
}

$stmt->bind_param("i", $mission_id);

if ($stmt->execute()) {
    // 5. สำเร็จ: บันทึกข้อความและ Redirect
    $_SESSION['message'] = "ลบภารกิจ ID: {$mission_id} เรียบร้อยแล้ว";
} else {
    // 6. ล้มเหลว: บันทึกข้อความผิดพลาดและ Redirect
    $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบภารกิจ: " . $stmt->error;
}

$stmt->close();

// 7. Redirect กลับไปยังหน้ารายการภารกิจ
header("Location: " . $redirect_url_list);
exit();
?>