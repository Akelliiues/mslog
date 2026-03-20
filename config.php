<?php
// config.php
// ใช้ฐานข้อมูลเดียวกับ EDMS

// ข้อมูลเชื่อมต่อฐานข้อมูล (กรุณาตรวจสอบความถูกต้อง)
$host = 'localhost';
$db = 'tansum_edms';
$user = 'tansum_edms';
$pass = '-hk8nv9eoko';

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_errno) {
    // แสดงข้อความ error สำหรับผู้ดูแลระบบ
    die("Failed to connect to MySQL: " . $mysqli->connect_error);
}

// ตั้งค่า Charset เป็น UTF8 สำหรับการแสดงผลภาษาไทย
$mysqli->set_charset("utf8mb4");

// รายชื่อผู้รับผิดชอบหลัก
$responsible_persons = [
    "ผช.สสอ.นายพนิต เกตุทอง",
    "นายบุญธรรม พันธ์ใหญ่", 
    "นายกฤตกร ฤาชากูล", 
    "นายสกุลทิพย์ พิมพกรรณ์",  
    "น.ส.พัชรี ภูธร", 
    "น.ส.ภัทรจิตร คำน้อย",
    "น.ส.ศิริวิมล ทองก่ำ", 
    "น.ส.วันเพ็ญ จันทาโย"
    // สามารถอ้างอิงจากโค้ด edit.php เดิมที่คุณเคยให้มา
];