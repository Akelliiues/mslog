<?php
// ตรวจสอบว่าไฟล์นี้ถูก include จาก mission_list.php และมีตัวแปรที่จำเป็นครบถ้วนหรือไม่
if (!isset($total_pages) || !isset($page) || !isset($query_params)) {
    // ป้องกันการเข้าถึงไฟล์นี้โดยตรง
    return;
}

if ($total_pages <= 1) {
    return;
}

// กำหนดจำนวนลิงก์ที่ต้องการแสดงรอบหน้าปัจจุบัน
$max_links = 5; 

// คำนวณขอบเขตของหน้าที่จะแสดง
$start_page = max(1, $page - floor($max_links / 2));
$end_page = min($total_pages, $start_page + $max_links - 1);

// ปรับ start_page อีกครั้ง ถ้า end_page ถูกดันไปชนขอบเขตสูงสุด
if ($end_page - $start_page + 1 < $max_links) {
    $start_page = max(1, $end_page - $max_links + 1);
}
?>

<div class="pagination-container">
    <ul class="pagination">
        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <a class="page-link" href="mission_list.php?<?php echo htmlspecialchars($query_params); ?>&page=1" aria-label="First">
                «
            </a>
        </li>

        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <a class="page-link" href="mission_list.php?<?php echo htmlspecialchars($query_params); ?>&page=<?php echo $page - 1; ?>" aria-label="Previous">
                ‹
            </a>
        </li>

        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
            <li class="page-item <?php echo ($i == $page) ? 'active' : ''; ?>">
                <a class="page-link" href="mission_list.php?<?php echo htmlspecialchars($query_params); ?>&page=<?php echo $i; ?>">
                    <?php echo $i; ?>
                </a>
            </li>
        <?php endfor; ?>

        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
            <a class="page-link" href="mission_list.php?<?php echo htmlspecialchars($query_params); ?>&page=<?php echo $page + 1; ?>" aria-label="Next">
                ›
            </a>
        </li>

        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
            <a class="page-link" href="mission_list.php?<?php echo htmlspecialchars($query_params); ?>&page=<?php echo $total_pages; ?>" aria-label="Last">
                »
            </a>
        </li>
    </ul>
</div>