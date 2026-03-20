<?php
session_start();
require_once '../config.php';
require_once '../auth.php'; 

// ดึงข้อมูลจาก Session
$user = $_SESSION['user_mslog'];
$username = $user['username'];
$responsible_person = $user['responsible_person'];
$user_role = strtolower($user['role'] ?? $user['level'] ?? '');

// -------------------------------------------------------------------
// --- 0. ฟังก์ชันช่วย: แปลงวันที่ ---
// ... (code unchanged) ...

function getThaiDayName($dateString)
{
    if (!$dateString || $dateString === '0000-00-00') return '-';
    $thaiDays = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];
    $timestamp = strtotime($dateString);
    $dayIndex = date('w', $timestamp);
    return $thaiDays[$dayIndex];
}

function formatThaiDate($date)
{
    if (empty($date) || $date === '0000-00-00') return '';
    $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    $dateParts = explode("-", $date);
    if (count($dateParts) !== 3) return $date;
    $day = (int) $dateParts[2];
    $month = $months[(int) $dateParts[1]];
    $year = (int) $dateParts[0] + 543;
    return "$day $month $year";
}

function formatMissionDateRange($start_date, $end_date)
{
    if (empty($end_date) || $end_date === '0000-00-00' || $start_date === $end_date) {
        return formatThaiDate($start_date);
    }
    $start_parts = explode('-', $start_date);
    $end_parts = explode('-', $end_date);
    if (count($start_parts) !== 3 || count($end_parts) !== 3) {
        $start_formatted = formatThaiDate($start_date);
        $end_formatted = formatThaiDate($end_date);
        return "{$start_formatted} - {$end_formatted}";
    }
    $start_year = $start_parts[0];
    $start_month = $start_parts[1];
    $start_day = (int) $start_parts[2];
    $end_year = $end_parts[0];
    $end_month = $end_parts[1];
    $end_day = (int) $end_parts[2];

    if ($start_year === $end_year && $start_month === $end_month) {
        $thai_day_name = getThaiDayName($start_date);
        $months = ["", "ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
        $thai_month = $months[(int) $start_month];
        $thai_year = (int) $start_year + 543;
        return "{$thai_day_name}ที่ {$start_day}-{$end_day} {$thai_month} {$thai_year}";
    } else {
        $start_formatted = formatThaiDate($start_date);
        $end_formatted = formatThaiDate($end_date);
        return "{$start_formatted} - {$end_formatted}";
    }
}

function formatTime($start_time, $end_time)
{
    $start_formatted = substr(htmlspecialchars($start_time), 0, 5);
    $end_formatted = substr(htmlspecialchars($end_time), 0, 5);
    if (!empty($start_formatted) && !empty($end_formatted) && $start_formatted !== $end_formatted) {
        return "{$start_formatted} - {$end_formatted} น.";
    } elseif (!empty($start_formatted)) {
        return "{$start_formatted} น.";
    } else {
        return '';
    }
}

// -------------------------------------------------------------------
// --- ส่วนที่ 1: ตรรกะการเปลี่ยนสถานะอัตโนมัติ ---
// -------------------------------------------------------------------
$current_datetime = date('Y-m-d H:i:s');
$current_date = date('Y-m-d');
$current_time = date('H:i:s');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$pending_status_db = "กำลังดำเนินการ";

$auto_update_query = "
    UPDATE missions
    SET status = 'เสร็จสิ้น'
    WHERE status IN ('รอดำเนินการ', '$pending_status_db')
      AND (
        (end_date IS NULL AND mission_date < '$current_date')
        OR (end_date IS NOT NULL AND end_date < '$current_date')
        OR (
          (end_date IS NULL AND mission_date = '$current_date' AND end_time IS NOT NULL AND STR_TO_DATE(CONCAT(mission_date, ' ', end_time), '%Y-%m-%d %H:%i:%s') < NOW())
          OR (end_date = '$current_date' AND end_time IS NOT NULL AND STR_TO_DATE(CONCAT(end_date, ' ', end_time), '%Y-%m-%d %H:%i:%s') < NOW())
        )
      )
";
$mysqli->query($auto_update_query);

// -------------------------------------------------------------------
// --- ส่วนที่ 2: การจัดการตัวกรอง ---
// -------------------------------------------------------------------
$filter_mode = isset($_GET['mode']) ? $_GET['mode'] : 'current'; 
$current_filter = isset($_GET['filter']) ? $_GET['filter'] : 'all_dates';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_query = "%" . $search_term . "%";

if ($user_role === 'guest') {
    $filter_mode = 'all'; 
    if (!in_array($current_filter, ['today', 'tomorrow', 'pending'])) {
        $current_filter = 'today';
    }
}

function generate_filter_url($mode, $filter, $search_term = '')
{
    $url = "?mode=" . urlencode($mode) . "&filter=" . urlencode($filter);
    if (!empty($search_term)) {
        $url .= "&search=" . urlencode($search_term);
    }
    return $url;
}

$where_clause = "WHERE 1=1";
$param_types = '';
$param_values = [];

if ($filter_mode === 'current') {
    $where_clause .= " AND status NOT IN ('เสร็จสิ้น', 'ยกเลิก')";
} elseif ($filter_mode === 'mine') {
    if ($user_role !== 'admin' && $user_role !== 'guest') { 
        $where_clause .= " AND (responsible_person = ? OR responsible_person = 'เจ้าหน้าที่ทุกคน')";
        $param_types .= 's';
        $param_values[] = $responsible_person;
    } elseif ($user_role === 'admin') {
        $where_clause .= " AND created_by = ?";
        $param_types .= 's';
        $param_values[] = $username;
    }
}

if ($current_filter == 'today') {
    $where_clause .= " AND (mission_date = '$current_date' OR end_date = '$current_date' OR (mission_date < '$current_date' AND end_date > '$current_date'))";
    if ($filter_mode !== 'all') {
        $where_clause .= " AND status NOT IN ('เสร็จสิ้น', 'ยกเลิก')";
    }
} elseif ($current_filter == 'tomorrow') {
    $where_clause .= " AND (mission_date = '$tomorrow' OR end_date = '$tomorrow' OR (mission_date < '$tomorrow' AND end_date > '$tomorrow'))";
    if ($filter_mode !== 'all') {
        $where_clause .= " AND status NOT IN ('เสร็จสิ้น', 'ยกเลิก')";
    }
} elseif ($current_filter == 'pending') {
    $where_clause = "WHERE (mission_date > '$tomorrow' OR (mission_date = '$tomorrow' AND end_time IS NOT NULL)) AND status NOT IN ('เสร็จสิ้น', 'ยกเลิก')";
    $param_types_temp = '';
    $param_values_temp = [];
    if ($filter_mode === 'mine') {
        if ($user_role !== 'admin' && $user_role !== 'guest') {
            $where_clause .= " AND (responsible_person = ? OR responsible_person = 'เจ้าหน้าที่ทุกคน')";
            $param_types_temp = 's';
            $param_values_temp[] = $responsible_person;
        } elseif ($user_role === 'admin') {
            $where_clause .= " AND created_by = ?";
            $param_types_temp = 's';
            $param_values_temp[] = $username;
        }
    }
    $param_types = $param_types_temp;
    $param_values = $param_values_temp;

} elseif ($current_filter == 'past') {
    $where_clause .= " AND (mission_date < '$current_date' OR (end_date IS NOT NULL AND end_date < '$current_date'))";
}

if (!empty($search_term)) {
    $where_clause .= " AND (subject LIKE ? OR responsible_person LIKE ? OR location LIKE ?)";
    $param_types .= 'sss';
    $param_values[] = $search_query;
    $param_values[] = $search_query;
    $param_values[] = $search_query;
}

// -------------------------------------------------------------------
// --- ส่วนที่ 3: Pagination ---
// -------------------------------------------------------------------
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
if ($page < 1) $page = 1;

$query_params = "mode=" . urlencode($filter_mode) . "&filter=" . urlencode($current_filter);
if (!empty($search_term)) {
    $query_params .= "&search=" . urlencode($search_term);
}

$count_query = "SELECT COUNT(id) AS total FROM missions m " . $where_clause;
$count_stmt = $mysqli->prepare($count_query);
if (!empty($param_types)) {
    $bind_params = [];
    $bind_params[] = &$param_types;
    foreach ($param_values as $key => $value) {
        $bind_params[] = &$param_values[$key];
    }
    if (!empty($param_values)) {
        call_user_func_array([$count_stmt, 'bind_param'], $bind_params);
    }
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_records = $count_result->fetch_assoc()['total'];
$count_stmt->close();

$total_pages = ceil($total_records / $limit);
$page = min($page, $total_pages);
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;
if ($offset < 0) $offset = 0;

// -------------------------------------------------------------------
// --- ส่วนที่ 4: Main Query ---
// -------------------------------------------------------------------
$query = "
    SELECT id, subject, mission_date, end_date, mission_time, end_time, location, responsible_person, status
    FROM missions m 
    " . $where_clause . "
    ORDER BY mission_date ASC, mission_time ASC
    LIMIT ? OFFSET ?";

$stmt = $mysqli->prepare($query);
$main_param_types = $param_types . "ii";
$main_param_values = $param_values;
$main_param_values[] = $limit;
$main_param_values[] = $offset;

if ($stmt === false) { die('MySQL Error: ' . $mysqli->error); }

$bind_params = [];
$bind_params[] = &$main_param_types;
foreach ($main_param_values as $key => $value) {
    $bind_params[] = &$main_param_values[$key];
}
call_user_func_array([$stmt, 'bind_param'], $bind_params);
$stmt->execute();
$result = $stmt->get_result();

// -------------------------------------------------------------------
// --- ส่วนที่ 5: Summary Bar ---
// -------------------------------------------------------------------
$responsible_condition = "";
if ($filter_mode === 'mine') {
    if ($user_role !== 'admin' && $user_role !== 'guest') {
        $responsible_person_esc = $mysqli->real_escape_string($responsible_person);
        $responsible_condition = " AND (responsible_person = '{$responsible_person_esc}' OR responsible_person = 'เจ้าหน้าที่ทุกคน')";
    } elseif ($user_role === 'admin') {
        $username_esc = $mysqli->real_escape_string($username);
        $responsible_condition = " AND created_by = '{$username_esc}'";
    }
}
$status_filter = " AND status NOT IN ('เสร็จสิ้น', 'ยกเลิก')";

$query_today_sum = "SELECT COUNT(id) as count FROM missions WHERE (mission_date = '$current_date' OR end_date = '$current_date' OR (mission_date < '$current_date' AND end_date > '$current_date'))" . $status_filter . $responsible_condition;
$result_today_sum = $mysqli->query($query_today_sum);
$count_today = $result_today_sum->fetch_assoc()['count'];

$query_tomorrow_sum = "SELECT COUNT(id) as count FROM missions WHERE (mission_date = '$tomorrow' OR end_date = '$tomorrow' OR (mission_date < '$tomorrow' AND end_date > '$tomorrow'))" . $status_filter . $responsible_condition;
$result_tomorrow_sum = $mysqli->query($query_tomorrow_sum);
$count_tomorrow = $result_tomorrow_sum->fetch_assoc()['count'];

$query_upcoming = "SELECT COUNT(id) as count FROM missions WHERE mission_date > '$tomorrow'" . $status_filter . $responsible_condition;
$result_upcoming = $mysqli->query($query_upcoming);
$count_upcoming = $result_upcoming->fetch_assoc()['count'];

$message = $_SESSION['message'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['message']);
unset($_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <link rel="manifest" href="/manifest.json">
    <title>MSLog: รายการภารกิจ</title>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black">
    <link rel="apple-touch-icon" href="../assets/images/icon-192x192.png">
    <link rel="stylesheet" href="../assets/css/mission_list_styles.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* สไตล์สำหรับแถบ Install Banner */
        #installBanner {
            display: none; 
            background-color: #28a745;
            color: white;
            padding: 10px;
            text-align: center;
            cursor: pointer;
            font-weight: bold;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            position: relative;
            z-index: 1000;
        }
        #installBanner:hover {
            background-color: #218838;
        }
    </style>
</head>

<body>
    <button class="theme-toggle" title="สลับโหมด"></button>
    <div class="main-wrapper">
        <div class="sticky-area">
            
            <div id="installBanner">📲 กดที่นี่เพื่อติดตั้งแอพ "MSLog" ลงมือถือ</div>

            <div class="header">
                <h2>Mission Log (บันทึกภารกิจ)</h2>
                <div class="user-info">
                    <?php echo htmlspecialchars($responsible_person); ?> |
                    <?php if ($user_role === 'guest'): ?>
                        <a href="../logout.php" style="color: #ffdddd; font-weight: bold;">เข้าสู่ระบบ</a>
                    <?php else: ?>
                        <a href="../logout.php" style="color: #ffdddd;">ออกจากระบบ</a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="summary-bar">
                <div class="summary-item">
                    <span class="summary-label">วันนี้</span>
                    <span class="summary-count" style="color: <?php echo ($count_today > 0 ? '#dc3545' : '#28a745'); ?>"><?php echo $count_today; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">พรุ่งนี้</span>
                    <span class="summary-count" style="color: #ffc107;"><?php echo $count_tomorrow; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">วันถัดไป</span>
                    <span class="summary-count"><?php echo $count_upcoming; ?></span>
                </div>
            </div>

            <div class="filter-container">
                <div class="filter-row filter-row-main">
                    <?php
                    $main_modes = [
                        'current' => 'ภารกิจปัจจุบัน',
                        'all' => 'ทั้งหมด',
                        'mine' => 'ภารกิจของฉัน'
                    ];

                    if ($user_role === 'guest') {
                        $main_modes = ['all' => 'ภารกิจทั้งหมด'];
                    }

                    foreach ($main_modes as $mode_key => $mode_label) {
                        $url = generate_filter_url($mode_key, $current_filter, $search_term);
                        $is_active = ($filter_mode === $mode_key) ? 'active' : '';
                        echo '<a href="' . $url . '" class="filter-btn ' . $is_active . '">' . $mode_label . '</a>';
                    }
                    ?>
                </div>

                <div class="filter-row">
                    <?php
                    $date_filters = [
                        'past' => 'เมื่อวาน',
                        'today' => 'วันนี้',
                        'tomorrow' => 'พรุ่งนี้',
                        'pending' => 'รออยู่',
                        'all_dates' => 'ทุกวัน'
                    ];

                    if ($user_role === 'guest') {
                        $date_filters = [
                            'today' => 'วันนี้',
                            'tomorrow' => 'พรุ่งนี้',
                            'pending' => 'รออยู่'
                        ];
                    }

                    foreach ($date_filters as $filter_key => $filter_label) {
                        $url = generate_filter_url($filter_mode, $filter_key, $search_term);
                        $is_active = ($current_filter == $filter_key) ? 'active' : '';
                        echo '<a href="' . $url . '" class="filter-btn ' . $is_active . '">' . $filter_label . '</a>';
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="mission-list-scroll">
            <?php
            if ($total_pages > 1) include 'mission_pagination_template.php';
            ?>

            <div class="mission-list">
                <?php if ($result->num_rows > 0): ?>
                    <?php while ($row = $result->fetch_assoc()):
                        $mission_date = $row['mission_date'];
                        $end_date = $row['end_date'];
                        $db_status = $row['status'];

                        $start_dt = strtotime($mission_date . ($row['mission_time'] ? ' ' . $row['mission_time'] : ' 00:00:00'));
                        $end_dt = strtotime(($end_date ?? $mission_date) . ($row['end_time'] ? ' ' . $row['end_time'] : ' 23:59:59'));
                        $current_ts = strtotime($current_datetime);

                        if ($db_status === 'เสร็จสิ้น') {
                            $display_status = 'เสร็จสิ้น';
                            $status_class = 'status-เสร็จสิ้น';
                            $border_color = '#28a745'; 
                        } elseif ($db_status === 'ยกเลิก') {
                            $display_status = 'ยกเลิก';
                            $status_class = 'status-ยกเลิก';
                            $border_color = '#dc3545'; 
                        } elseif ($end_dt < $current_ts) {
                            $display_status = 'ดำเนินการเกินกำหนด';
                            $status_class = 'status-เกินกำหนด';
                            $border_color = '#8b0000'; 
                        } elseif ($start_dt <= $current_ts) {
                            $display_status = 'กำลังดำเนินการ';
                            $status_class = 'status-กำลังดำเนินการ';
                            $border_color = '#ffc107'; 
                        } else {
                            $display_status = 'รอดำเนินการ';
                            $status_class = 'status-รอดำเนินการ';
                            $border_color = '#007bff'; 
                        }

                        $thai_day_name = getThaiDayName($row['mission_date']);
                        $formatted_time = formatTime($row['mission_time'], $row['end_time']);
                        $formatted_date_range = formatMissionDateRange($mission_date, $end_date);
                        ?>
                        <div class="mission-card" style="border-left-color: <?php echo $border_color; ?>;"
                            onclick="window.location.href='mission_view.php?id=<?php echo $row['id']; ?>&<?php echo htmlspecialchars($query_params); ?>&page=<?php echo $page; ?>'">

                            <div class="card-row">
                                <strong>📅</strong>
                                <span class="day-time-display">
                                    <span class="date-time-info" style="font-weight: bold; color: #333; font-size: 1.0em;">
                                        <?php echo $formatted_date_range; ?>
                                        <?php echo (!empty($formatted_time) ? ' | ' . $formatted_time : ''); ?>
                                    </span>
                                </span>
                                <span class="status-badge <?php echo $status_class; ?>"><?php echo $display_status; ?></span>
                            </div>

                            <div class="card-row">
                                <strong>👤 </strong> <span class="responsible-name"><?php echo htmlspecialchars($row['responsible_person']); ?></span>
                            </div>

                            <div class="card-row">
                                <strong>📝 ภารกิจ:</strong> <span><?php echo htmlspecialchars($row['subject']); ?></span>
                            </div>
                            <div class="card-row">
                                <strong>📍 ที่ไหน:</strong> <span><?php echo htmlspecialchars($row['location']); ?></span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="text-align: center; margin-top: 20px; color: #888;">ไม่พบภารกิจที่บันทึกไว้ตามตัวกรองที่เลือก</p>
                <?php endif; ?>
            </div>

            <?php if ($total_pages > 1) include 'mission_pagination_template.php'; ?>

        </div>
    </div>

    <?php if ($user_role !== 'guest'): ?>
    <a href="mission_add.php" class="fab">+</a>
    <?php endif; ?>

    <div class="search-footer-container">
        <form action="mission_list.php" method="GET" class="search-form">
            <input type="hidden" name="mode" value="<?php echo htmlspecialchars($filter_mode); ?>">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($current_filter); ?>">

            <div class="search-input-wrapper">
                <input type="text" name="search" class="search-input" placeholder="ค้นหาใคร ทำอะไร ที่ไหน??" value="<?php echo htmlspecialchars($search_term); ?>">
                <?php if (!empty($search_term)): ?>
                    <a href="<?php echo generate_filter_url($filter_mode, $current_filter); ?>" class="clear-search-btn" title="ล้างการค้นหา">&times;</a>
                <?php endif; ?>
            </div>
            <button type="submit" class="search-btn">🔍 ค้นนนน...</button>
        </form>
    </div>

    <script>
        // 1. ลงทะเบียน Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js')
                    .then(reg => {
                        console.log('SW Registered successfully: ', reg);
                        // 🔑 NEW: ตรวจสอบสถานะการทำงานของ Service Worker
                        if (reg.active) {
                            console.log('SW status: Active');
                        }
                    })
                    .catch(error => {
                        // 🔑 CRITICAL: แสดงข้อผิดพลาดการลงทะเบียน SW
                        console.error('Service Worker Registration failed:', error);
                    });
            });
        }

        // 2. จัดการแถบติดตั้ง (Install Banner)
        let deferredPrompt;
        const installBanner = document.getElementById('installBanner');

        // เมื่อ Browser บอกว่าพร้อมติดตั้งแล้ว
        window.addEventListener('beforeinstallprompt', (e) => {
            // สำคัญ: ป้องกัน Pop-up อัตโนมัติของ Browser
            e.preventDefault();
            deferredPrompt = e;
            // แสดงแถบ Banner ให้ผู้ใช้เห็น ถ้ายังไม่ติดตั้ง
            if (!window.matchMedia('(display-mode: standalone)').matches && !window.navigator.standalone) {
                 installBanner.style.display = 'block'; 
            }
        });

        // เมื่อผู้ใช้กดที่แถบ Banner
        installBanner.addEventListener('click', (e) => {
            installBanner.style.display = 'none';
            // เรียกใช้ Prompt ติดตั้งของ Browser
            if (deferredPrompt) {
                 deferredPrompt.prompt();
                 deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('User accepted the A2HS prompt');
                    }
                    deferredPrompt = null;
                 });
            }
        });

        // 3. ซ่อน Banner ถ้าติดตั้งแล้ว (ตรวจซ้ำอีกครั้ง)
        if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
            installBanner.style.display = 'none';
        }
    </script>
    <script src="../assets/js/theme-toggle.js"></script>
</body>

</html>
<?php
$stmt->close();
?>