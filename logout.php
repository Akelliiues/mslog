<?php
// logout.php

session_start();
session_unset(); // ลบตัวแปร Session ทั้งหมด
session_destroy(); // ทำลาย Session
// *** บรรทัดนี้ถูกต้องแล้ว: มันจะเปลี่ยนเส้นทางกลับไปที่หน้า Login ***
header("Location: index.php"); 
exit();
?>