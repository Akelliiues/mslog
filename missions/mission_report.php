<?php
session_start();
// ตรวจสอบว่าไฟล์ config และ auth ถูกเรียกใช้ได้
if (!file_exists('../config.php') || !file_exists('../auth.php')) {
    die("Configuration files (config.php or auth.php) are missing.");
}
require_once '../config.php';
require_once '../auth.php'; 

// -----------------------------------------------------------
// 1. ตรวจสอบสิทธิ์และการกำหนดช่วงเวลา
// -----------------------------------------------------------

// ตรวจสอบสิทธิ์: อนุญาตเฉพาะผู้ดูแลระบบ (admin) หรือผู้มีสิทธิ์เข้าถึง (sso) เท่านั้น
$user_role = strtolower($_SESSION['user_mslog']['role'] ?? $_SESSION['user_mslog']['level'] ?? '');
if ($user_role !== 'admin' && $user_role !== 'sso') { // ตรวจสอบสิทธิ์ admin/sso
    $_SESSION['error'] = "คุณไม่มีสิทธิ์เข้าถึงหน้ารายงาน";
    header("Location: mission_list.php"); 
    exit();
}

// กำหนดช่วงเวลาที่ต้องการดึงข้อมูล (รับค่าจาก GET หรือใช้เดือน/ปี ปัจจุบัน)
$today = date('Y-m-d');
$default_year = date('Y');
$default_month = date('m');

// รับค่าจาก URL
$current_year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)$default_year;
$current_month = isset($_GET['month']) && is_numeric($_GET['month']) ? str_pad($_GET['month'], 2, '0', STR_PAD_LEFT) : $default_month;

// ปรับค่าปีและเดือนให้ถูกต้องตาม SQL
$current_year_sql = $mysqli->real_escape_string($current_year);
$current_month_sql = $mysqli->real_escape_string($current_month);

// คำนวณปีพุทธศักราช
$current_year_be = $current_year + 543;

// คำนวณวันเริ่มต้นและวันสิ้นสุดของสัปดาห์ปัจจุบัน (ยึดตามวันปัจจุบันของ Server)
$start_of_week = date('Y-m-d', strtotime('monday this week', strtotime($today)));
$end_of_week = date('Y-m-d', strtotime('sunday this week', strtotime($today)));

$thai_month_names = [
    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
];
$report_title = "รายงานภารกิจประจำเดือน " . $thai_month_names[(int)$current_month] . " " . $current_year_be;

// -----------------------------------------------------------
// 2. QUERY: สรุปภารกิจรายบุคคลสำหรับเดือนที่เลือก (ตารางหลักและกราฟรายบุคคล)
// -----------------------------------------------------------
$main_query = "
    SELECT 
        responsible_person, 
        COUNT(id) AS total_missions,
        SUM(CASE WHEN status = 'เสร็จสิ้น' THEN 1 ELSE 0 END) AS completed_missions,
        SUM(CASE WHEN status = 'กำลังดำเนินการ' THEN 1 ELSE 0 END) AS in_progress_missions,
        SUM(CASE WHEN status = 'ยกเลิก' THEN 1 ELSE 0 END) AS cancelled_missions
    FROM 
        missions
    WHERE 
        YEAR(mission_date) = $current_year_sql AND MONTH(mission_date) = $current_month_sql
    GROUP BY 
        responsible_person
    ORDER BY 
        total_missions DESC;
";
$main_result = $mysqli->query($main_query);
$report_data = $main_result->fetch_all(MYSQLI_ASSOC);

// เตรียมข้อมูลสำหรับกราฟรายบุคคล (รวมถึง 'เจ้าหน้าที่ทุกคน' ถ้ามี)
$person_labels = [];
$person_totals = [];
foreach ($report_data as $row) {
    // ปรับการแสดงผลชื่อ 'เจ้าหน้าที่ทุกคน' ให้สั้นลง (ถ้าต้องการ) หรือใช้ชื่อเต็มไปเลย
    $label = htmlspecialchars($row['responsible_person']);
    
    $person_labels[] = $label;
    $person_totals[] = $row['total_missions'];
}


// -----------------------------------------------------------
// 3. QUERY: สรุปภารกิจตามช่วงเวลา (ตารางสรุป)
// -----------------------------------------------------------
$summary_query = "
    SELECT 
        responsible_person,
        COUNT(id) AS total_all_time,
        SUM(CASE WHEN mission_date >= '$start_of_week' AND mission_date <= '$end_of_week' THEN 1 ELSE 0 END) AS total_this_week,
        SUM(CASE WHEN YEAR(mission_date) = $current_year_sql AND MONTH(mission_date) = $current_month_sql THEN 1 ELSE 0 END) AS total_this_month, 
        SUM(CASE WHEN YEAR(mission_date) = $current_year_sql THEN 1 ELSE 0 END) AS total_this_year
    FROM 
        missions
    GROUP BY 
        responsible_person
    ORDER BY
        total_all_time DESC;
";
$summary_result = $mysqli->query($summary_query);
$summary_data = $summary_result->fetch_all(MYSQLI_ASSOC);

// สร้างข้อความสัปดาห์สำหรับหัวตาราง
$week_title = "รวมสัปดาห์นี้ (" . date('d', strtotime($start_of_week)) . '-' . date('d', strtotime($end_of_week)) . " " . $thai_month_names[(int)date('m', strtotime($start_of_week))] . ")";


// -----------------------------------------------------------
// 4. คำนวณยอดรวมและ KPI ระดับองค์รวม (สำหรับตารางหลัก)
// -----------------------------------------------------------
$grand_total = 0;
$grand_completed = 0;
$grand_in_progress = 0;
$grand_cancelled = 0;
$num_people = count($report_data);

foreach ($report_data as $row) { 
    $grand_total += $row['total_missions'];
    $grand_completed += $row['completed_missions'];
    $grand_in_progress += $row['in_progress_missions'];
    $grand_cancelled += $row['cancelled_missions'];
}

// คำนวณ KPI
$successful_missions = $grand_completed;
$total_countable = $grand_total - $grand_cancelled; // ภารกิจที่ต้องนับรวมในการประเมินประสิทธิภาพ (รวมทั้งหมด - ยกเลิก)
$success_rate = ($total_countable > 0) ? round(($successful_missions / $total_countable) * 100, 2) : 0;
$cancellation_rate = ($grand_total > 0) ? round(($grand_cancelled / $grand_total) * 100, 2) : 0;
$avg_per_person = ($num_people > 0) ? round($grand_total / $num_people, 2) : 0;

// -----------------------------------------------------------
// 5. คำนวณยอดรวมสำหรับตารางสรุปช่วงเวลา (Total Summary Row)
// -----------------------------------------------------------
$sum_total_all_time = 0;
$sum_total_this_week = 0;
$sum_total_this_month = 0;
$sum_total_this_year = 0;

foreach ($summary_data as $row) {
    $sum_total_all_time += $row['total_all_time'];
    $sum_total_this_week += $row['total_this_week'];
    $sum_total_this_month += $row['total_this_month'];
    $sum_total_this_year += $row['total_this_year'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSLog: รายงานภารกิจ (<?php echo $thai_month_names[(int)$current_month] . ' ' . $current_year_be; ?>)</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { font-family: sans-serif; background-color: var(--bg-color); color: var(--text-color); margin: 0; padding-bottom: 20px; transition: background-color 0.3s, color 0.3s; }
        .header { background-color: var(--header-bg); color: var(--header-text); padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); margin-bottom: 20px; transition: background-color 0.3s, color 0.3s; }
        .header h2 { margin: 0; font-size: 1.5em; }
        .report-container { max-width: 95%; margin: auto; padding: 10px; background: var(--card-bg); border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); transition: background-color 0.3s; }
        h3 { color: #007bff; border-bottom: 2px solid #007bff; padding-bottom: 5px; margin-top: 20px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 0.9em; }
        th, td { padding: 10px; text-align: left; border: 1px solid var(--border-color); }
        th { background-color: var(--bg-color); color: var(--text-color); font-weight: bold; text-align: center; }
        td:nth-child(1) { font-weight: bold; }
        .total-row { background-color: rgba(255, 243, 205, 0.2); font-weight: bold; }
        .text-center { text-align: center; }
        .back-button { display: block; width: 100px; padding: 8px; margin: 10px auto; text-align: center; background-color: var(--btn-back-bg); color: white; border-radius: 4px; text-decoration: none; }

        /* KPI Summary */
        .kpi-summary { display: flex; justify-content: space-around; flex-wrap: wrap; margin-bottom: 20px; padding: 15px; background: rgba(0, 123, 255, 0.1); border-radius: 6px; }
        .kpi-item { text-align: center; margin: 5px 10px; flex-basis: 30%; min-width: 120px; }
        .kpi-item .value { font-size: 1.6em; font-weight: bold; margin-bottom: 2px; }
        .kpi-item .label { font-size: 0.8em; color: var(--secondary-text); }
        .rate-good { color: #28a745; }
        .rate-average { color: #ffc107; }
        .rate-bad { color: #dc3545; }

        /* Date Filter Form */
        .filter-form { text-align: center; margin-bottom: 20px; padding: 10px; background: rgba(0,0,0,0.05); border-radius: 6px; }
        .filter-form label { font-weight: bold; margin-right: 10px; }
        .filter-form select, .filter-form button { padding: 8px 12px; border-radius: 4px; border: 1px solid var(--input-border); margin-right: 5px; background: var(--input-bg); color: var(--text-color); }
        .filter-form button { background-color: #007bff; color: white; cursor: pointer; transition: background-color 0.3s; }
        .filter-form button:hover { background-color: #0056b3; }

        /* Dark Mode Specific Overrides */
        .dark-mode h3 { color: #4dabff; border-bottom-color: #4dabff; }
        .dark-mode .total-row { background-color: rgba(255, 193, 7, 0.1); }
        .dark-mode .back-button { color: #e0e0e0; }

        /* Responsive Table */
        @media screen and (max-width: 768px) {
            .report-container { padding: 0; }
            table, thead, tbody, th, td, tr { display: block; }
            thead tr { position: absolute; top: -9999px; left: -9999px; }
            tr { border: 1px solid #ccc; margin-bottom: 15px; border-radius: 6px; }
            td { border: none; border-bottom: 1px solid #eee; position: relative; padding-left: 50%; text-align: right; }
            td:before { 
                position: absolute; top: 6px; left: 6px; width: 45%; padding-right: 10px; white-space: nowrap; text-align: left; font-weight: bold; color: #555;
            }
            
            /* ตารางหลัก */
            td:nth-of-type(1):before { content: "👤 ผู้รับผิดชอบ"; }
            td:nth-of-type(2):before { content: "รวมภารกิจ"; }
            td:nth-of-type(3):before { content: "✅ เสร็จสิ้น"; }
            td:nth-of-type(4):before { content: "⏳ กำลังดำเนินการ"; }
            td:nth-of-type(5):before { content: "❌ ยกเลิก"; }

            /* สำหรับตารางสรุปรายปี/สัปดาห์/เดือน */
            .summary-table td:nth-of-type(1):before { content: "👤 ผู้รับผิดชอบ"; }
            .summary-table td:nth-of-type(2):before { content: "รวมทั้งหมด"; }
            .summary-table td:nth-of-type(3):before { content: "รวมสัปดาห์นี้"; }
            .summary-table td:nth-of-type(4):before { content: "รวมเดือนนี้"; }
            .summary-table td:nth-of-type(5):before { content: "รวมปีปัจจุบัน"; }
            
            .kpi-summary { flex-direction: column; }
            .kpi-item { flex-basis: 100%; margin: 5px 0; }
        }
    </style>
</head>
<body>
    <div class="accessibility-controls">
        <button id="btn-decrease-font" class="font-btn" title="ลดขนาดตัวอักษร">A-</button>
        <button id="btn-reset-font" class="font-btn" title="ขนาดตัวอักษรปกติ">A</button>
        <button id="btn-increase-font" class="font-btn" title="เพิ่มขนาดตัวอักษร">A+</button>
        <button class="theme-toggle" title="สลับโหมด"></button>
    </div>
    <div class="header">
        <h2>📊 สรุปรายงานภารกิจ (MSLog)</h2>
    </div>

    <div class="report-container">
        
        <form action="mission_report.php" method="GET" class="filter-form">
            <label for="month">เลือกเดือน:</label>
            <select name="month" id="month">
                <?php for ($m = 1; $m <= 12; $m++): 
                    $month_padded = str_pad($m, 2, '0', STR_PAD_LEFT);
                ?>
                    <option value="<?php echo $month_padded; ?>" <?php echo ((int)$current_month === $m) ? 'selected' : ''; ?>>
                        <?php echo $thai_month_names[$m]; ?>
                    </option>
                <?php endfor; ?>
            </select>
            
            <label for="year">ปี (พ.ศ.):</label>
            <select name="year" id="year">
                <?php 
                $start_year = $default_year - 2; // ย้อนหลัง 2 ปี
                $end_year = $default_year + 1; // อนาคต 1 ปี
                for ($y = $start_year; $y <= $end_year; $y++): 
                ?>
                    <option value="<?php echo $y; ?>" <?php echo ($current_year === $y) ? 'selected' : ''; ?>>
                        <?php echo $y + 543; ?>
                    </option>
                <?php endfor; ?>
            </select>
            <button type="submit">ดูรายงาน</button>
        </form>

        <h3>ภาพรวมประสิทธิภาพ (<?php echo $report_title; ?>)</h3>
        <div class="kpi-summary">
            <div class="kpi-item">
                <div class="value rate-good"><?php echo number_format($success_rate, 2); ?>%</div>
                <div class="label">อัตราสำเร็จ (Success Rate)</div>
            </div>
            <div class="kpi-item">
                <div class="value rate-bad"><?php echo number_format($cancellation_rate, 2); ?>%</div>
                <div class="label">อัตรายกเลิก (Cancellation)</div>
            </div>
            <div class="kpi-item">
                <div class="value" style="color: #007bff;"><?php echo number_format($avg_per_person, 2); ?></div>
                <div class="label">เฉลี่ยต่อคน (ต่อเดือน)</div>
            </div>
        </div>
        
        <hr>

        <h3>จำนวนภารกิจแยกตามสถานะรวมในเดือนนี้</h3>
        <?php if ($grand_total > 0): ?>
            <div style="width: 90%; max-width: 600px; margin: 20px auto;">
                <canvas id="missionStatusChart"></canvas>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #888;">ไม่พบข้อมูลภารกิจสำหรับแสดงในกราฟ</p>
        <?php endif; ?>

        <hr>

        <h3>สถิติภารกิจรวมรายบุคคลในเดือนนี้</h3>
        <?php if ($num_people > 0): ?>
            <div style="width: 90%; max-width: 600px; margin: 20px auto; height: <?php echo max(250, $num_people * 50); ?>px;">
                <canvas id="personChart"></canvas>
            </div>
        <?php else: ?>
            <p style="text-align: center; color: #888;">ไม่พบข้อมูลเจ้าหน้าที่สำหรับแสดงสถิติ</p>
        <?php endif; ?>
        
        <hr>

        <h3>สรุปภารกิจตามสถานะรายบุคคล (ในเดือน <?php echo $thai_month_names[(int)$current_month] . ' ' . $current_year_be; ?>)</h3>
        
        <?php if (!empty($report_data)): ?>
        <table>
            <thead>
                <tr>
                    <th>👤 ผู้รับผิดชอบ</th>
                    <th class="text-center">รวมภารกิจ</th>
                    <th class="text-center">✅ เสร็จสิ้น</th>
                    <th class="text-center">⏳ กำลังดำเนินการ</th>
                    <th class="text-center">❌ ยกเลิก</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_data as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['responsible_person']); ?></td>
                    <td class="text-center"><?php echo number_format($row['total_missions']); ?></td>
                    <td class="text-center" style="color: #28a745;"><?php echo number_format($row['completed_missions']); ?></td>
                    <td class="text-center" style="color: #ffc107;"><?php echo number_format($row['in_progress_missions']); ?></td>
                    <td class="text-center" style="color: #dc3545;"><?php echo number_format($row['cancelled_missions']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>รวมทั้งหมดในเดือนนี้</td>
                    <td class="text-center"><?php echo number_format($grand_total); ?></td>
                    <td class="text-center"><?php echo number_format($grand_completed); ?></td>
                    <td class="text-center"><?php echo number_format($grand_in_progress); ?></td>
                    <td class="text-center"><?php echo number_format($grand_cancelled); ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
            <p style="text-align: center; color: #888;">ไม่พบข้อมูลภารกิจในเดือนที่เลือก</p>
        <?php endif; ?>
        
        <hr>

        <h3>สรุปภารกิจตามช่วงเวลา (เทียบกับภาพรวม)</h3>
        <p style="text-align: center; font-size: 0.9em; color: #6c757d;">(รวมทั้งหมด / สัปดาห์ปัจจุบัน / เดือนที่เลือก / ปีปัจจุบัน)</p>
        
        <?php if (!empty($summary_data)): ?>
        <table class="summary-table">
            <thead>
                <tr>
                    <th>👤 ผู้รับผิดชอบ</th>
                    <th class="text-center">รวมทั้งหมด (ตลอดชีพ)</th>
                    <th class="text-center"><?php echo htmlspecialchars($week_title); ?></th>
                    <th class="text-center">รวมเดือนนี้ (<?php echo $thai_month_names[(int)$current_month]; ?>)</th>
                    <th class="text-center">รวมปีปัจจุบัน (พ.ศ. <?php echo $current_year_be; ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary_data as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['responsible_person']); ?></td>
                    <td class="text-center"><?php echo number_format($row['total_all_time']); ?></td>
                    <td class="text-center" style="color: #007bff;"><?php echo number_format($row['total_this_week']); ?></td>
                    <td class="text-center" style="color: #28a745;"><?php echo number_format($row['total_this_month']); ?></td> 
                    <td class="text-center"><?php echo number_format($row['total_this_year']); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>**ยอดรวมทั้งหมด**</td>
                    <td class="text-center"><?php echo number_format($sum_total_all_time); ?></td>
                    <td class="text-center"><?php echo number_format($sum_total_this_week); ?></td>
                    <td class="text-center"><?php echo number_format($sum_total_this_month); ?></td>
                    <td class="text-center"><?php echo number_format($sum_total_this_year); ?></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
            <p style="text-align: center; color: #888;">ไม่พบข้อมูลภารกิจในระบบ</p>
        <?php endif; ?>

        <a href="mission_list.php" class="back-button">ย้อนกลับ</a>
    </div>

    <?php if ($grand_total > 0): ?>
    <script src="../assets/js/theme-toggle.js"></script>
    <script src="../assets/js/font-toggle.js"></script>
    <script>
        // ปรับแต่ง Chart.js สำหรับโหมดมืด
        function updateChartTheme() {
            const isDark = document.documentElement.classList.contains('dark-mode');
            const textColor = isDark ? '#e0e0e0' : '#666';
            const gridColor = isDark ? '#444' : '#eee';

            Chart.defaults.color = textColor;
            Chart.defaults.scale.grid.color = gridColor;

            if (window.statusChart) window.statusChart.update();
            if (window.perChart) window.perChart.update();
        }

        // ------------------------------------
        // กราฟที่ 1: สรุปสถานะรวม (Vertical Bar Chart)
        // ------------------------------------
        const completed = <?php echo $grand_completed; ?>;
        const inProgress = <?php echo $grand_in_progress; ?>;
        const cancelled = <?php echo $grand_cancelled; ?>;
        const total = completed + inProgress + cancelled;
        
        if (total > 0) {
            const statusData = {
                labels: ['✅ เสร็จสิ้น', '⏳ กำลังดำเนินการ', '❌ ยกเลิก'],
                datasets: [{
                    label: 'จำนวนภารกิจ',
                    data: [completed, inProgress, cancelled],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                }]
            };

            const statusConfig = {
                type: 'bar',
                data: statusData,
                options: {
                    responsive: true,
                    aspectRatio: 2, 
                    scales: {
                        y: { 
                            beginAtZero: true,
                            title: { display: true, text: 'จำนวนภารกิจ' }
                        }
                    },
                    plugins: { legend: { display: false } }
                }
            };

            window.statusChart = new Chart(
                document.getElementById('missionStatusChart'),
                statusConfig
            );
        }

        // ------------------------------------
        // กราฟที่ 2: สถิติรายบุคคล (Horizontal Bar Chart)
        // **รวม 'เจ้าหน้าที่ทุกคน' เข้าไปด้วย ถ้ามีในข้อมูล**
        // ------------------------------------
        const personLabels = <?php echo json_encode($person_labels); ?>;
        const personTotals = <?php echo json_encode($person_totals); ?>;
        
        if (personLabels.length > 0) {
            const personData = {
                labels: personLabels,
                datasets: [{
                    label: 'ภารกิจรวม (รายการ)',
                    data: personTotals,
                    // กำหนดสีให้แตกต่างกันเล็กน้อย (ถ้า 'เจ้าหน้าที่ทุกคน' อยู่ในรายการ)
                    backgroundColor: function(context) {
                        return '#007bff'; // สีน้ำเงินมาตรฐาน
                    }, 
                }]
            };

            const personConfig = {
                type: 'bar',
                data: personData,
                options: {
                    indexAxis: 'y', // ทำให้เป็นกราฟแท่งแนวนอน
                    responsive: true,
                    maintainAspectRatio: false, 
                    scales: {
                        x: { // แกน X (จำนวนภารกิจ)
                            beginAtZero: true,
                            title: { display: true, text: 'จำนวนภารกิจรวมในเดือน' }
                        },
                        y: { // แกน Y (ชื่อคน)
                            // ไม่ต้องตั้งค่า title
                        }
                    },
                    plugins: { 
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return 'ภารกิจรวม: ' + context.parsed.x.toLocaleString() + ' รายการ';
                                }
                            }
                        }
                    }
                }
            };

            window.perChart = new Chart(
                document.getElementById('personChart'),
                personConfig
            );
        }

        // เรียกใช้งานครั้งแรกและเมื่อมีการเปลี่ยนโหมด
        updateChartTheme();
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.attributeName === 'class') {
                    updateChartTheme();
                }
            });
        });
        observer.observe(document.documentElement, { attributes: true });
    </script>
    <?php endif; ?>

</body>
</html>
<?php
// ปิดการเชื่อมต่อ
$mysqli->close();
?>