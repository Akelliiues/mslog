<?php
// ในไฟล์ ../auth.php
if (!isset($_SESSION['user_mslog']) || empty($_SESSION['user_mslog'])) {
    // ถ้าไม่มี Session ให้ Redirect ไปหน้า Login
    header('Location: index.php'); // หรือ /login.php
    exit();
}
// หากมี Session ก็ให้โปรแกรมดำเนินต่อไป
?>