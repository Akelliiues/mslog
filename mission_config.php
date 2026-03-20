<?php
/**
 * mission_config.php
 * ไฟล์กำหนดค่าช่วงเวลา (Time Slots) สำหรับ Mission Log
 * ช่วงเวลาราชการ: 08:00 น. - 16:30 น.
 */

$mission_time_slots = [];

$start_time = strtotime('08:00');
$end_time = strtotime('16:30');
$interval = 30 * 60; // 30 นาที ในหน่วยวินาที

while ($start_time <= $end_time) {
    $mission_time_slots[] = date('H:i', $start_time);
    $start_time += $interval;
}
?>