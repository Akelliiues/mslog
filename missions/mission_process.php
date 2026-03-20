<?php
session_start();
require_once '../config.php';
require_once '../auth.php'; 

$action = $_POST['action'] ?? '';
$user_logged = $_SESSION['user_mslog']['username'];
$redirect_to = 'mission_list.php'; 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ------------------------------------
    // *** การจัดการช่วงเวลาเริ่มต้นและสิ้นสุด ***
    // ------------------------------------
    $time_start = $_POST['mission_time_start'] ?? null;
    $time_end = $_POST['mission_time_end'] ?? null;
    $time = null;

    if (!empty($time_start) && !empty($time_end)) {
        // บันทึกเป็นช่วงเวลา เช่น '08:30 - 10:00'
        $time = $time_start . ' - ' . $time_end; 
    } elseif (!empty($time_start)) {
        // ถ้ามีแค่เวลาเริ่มต้น ให้บันทึกแค่ค่าเริ่มต้น
        $time = $time_start; 
    } elseif (!empty($time_end)) {
        // ถ้ามีแค่เวลาสิ้นสุด ให้บันทึกแค่ค่าสิ้นสุด
        $time = $time_end; 
    }

    // --- ADD MISSION ---
    if ($action === 'add') {
        $date = $_POST['mission_date'] ?? '';
        // $time ใช้ค่าจากด้านบนแล้ว
        $subject = $_POST['subject'] ?? '';
        $person = $_POST['responsible_person'] ?? '';
        $location = $_POST['location'] ?? '';
        $status = $_POST['status'] ?? 'กำลังดำเนินการ';
        $notes = $_POST['notes'] ?? '';

        if (!empty($date) && !empty($subject) && !empty($person) && !empty($location)) {
            $stmt = $mysqli->prepare("INSERT INTO missions (mission_date, mission_time, subject, responsible_person, location, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssssss", $date, $time, $subject, $person, $location, $status, $notes, $user_logged);
            
            if ($stmt->execute()) {
                $_SESSION['message'] = "บันทึกภารกิจสำเร็จ";
            } else {
                $_SESSION['error'] = "เกิดข้อผิดพลาดในการบันทึก: " . $stmt->error;
            }
            $stmt->close();
            
        } else {
            $_SESSION['error'] = "กรุณากรอกข้อมูลให้ครบถ้วน";
            $redirect_to = "mission_add.php"; 
        }
    }

    // --- EDIT MISSION ---
    elseif ($action === 'edit') {
        $id = intval($_POST['id'] ?? 0);
        $date = $_POST['mission_date'] ?? '';
        // $time ใช้ค่าจากด้านบนแล้ว
        $subject = $_POST['subject'] ?? '';
        $person = $_POST['responsible_person'] ?? '';
        $location = $_POST['location'] ?? '';
        $status = $_POST['status'] ?? 'กำลังดำเนินการ';
        $notes = $_POST['notes'] ?? '';

        $redirect_to = "mission_edit.php?id=$id"; 

        if ($id > 0 && !empty($date) && !empty($subject) && !empty($person) && !empty($location)) {
            $stmt = $mysqli->prepare("UPDATE missions SET mission_date=?, mission_time=?, subject=?, responsible_person=?, location=?, status=?, notes=? WHERE id=?");
            $stmt->bind_param("sssssssi", $date, $time, $subject, $person, $location, $status, $notes, $id);

            if ($stmt->execute()) {
                $_SESSION['message'] = "แก้ไขภารกิจสำเร็จ";
            } else {
                $_SESSION['error'] = "เกิดข้อผิดพลาดในการแก้ไข: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "ข้อมูลไม่ถูกต้องหรือไม่ครบถ้วน";
        }
    }

    // --- DELETE MISSION ---
    elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $mysqli->prepare("DELETE FROM missions WHERE id = ?");
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) {
                $_SESSION['message'] = "ลบภารกิจสำเร็จ";
                $redirect_to = 'mission_list.php'; 
            } else {
                $_SESSION['error'] = "เกิดข้อผิดพลาดในการลบ: " . $stmt->error;
                $redirect_to = "mission_edit.php?id=$id";
            }
            $stmt->close();
        } else {
            $_SESSION['error'] = "รหัสภารกิจไม่ถูกต้อง";
            $redirect_to = 'mission_list.php';
        }
    }
}

$mysqli->close();
header("Location: " . $redirect_to);
exit();