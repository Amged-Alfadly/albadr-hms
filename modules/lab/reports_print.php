<?php
require '../../config/database.php';
require '../../includes/header.php'; // For session and branding

if ($_SESSION['role'] !== 'Lab' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

$period = $_GET['period'] ?? 'month';
$start_date = date('Y-m-01 05:00:00'); 
if ($period === 'today') {
    $start_date = $medical_day_start;
}

// Aggregating Stats
$total = $pdo->prepare("SELECT COUNT(*) FROM Medical_Tests WHERE request_date >= ?");
$total->execute([$start_date]);
$total_count = $total->fetchColumn();

$completed = $pdo->prepare("SELECT COUNT(*) FROM Medical_Tests WHERE status = 'Completed' AND result_date >= ?");
$completed->execute([$start_date]);
$completed_count = $completed->fetchColumn();

$top_svc = $pdo->prepare("SELECT s.service_name, COUNT(t.test_id) as count 
                         FROM Medical_Tests t 
                         JOIN Lab_Services s ON t.service_id = s.service_id 
                         WHERE t.request_date >= ? 
                         GROUP BY t.service_id 
                         ORDER BY count DESC LIMIT 10");
$top_svc->execute([$start_date]);
$top_services = $top_svc->fetchAll();

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقرير أداء المختبر - مستشفى البدر</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap');
        
        :root {
            --primary: #E67E22;
            --secondary: #2C3E50;
            --border: #edf2f7;
            --bg-light: #f8fafc;
        }

        @page {
            size: A4;
            margin: 20mm;
        }

        body { 
            font-family: 'Cairo', sans-serif; 
            margin: 0; 
            padding: 0; 
            color: #2D3436; 
            background: #fff;
            line-height: 1.6;
            -webkit-print-color-adjust: exact;
        }

        .report-wrapper {
            max-width: 210mm;
            margin: 0 auto;
            position: relative;
        }

        /* Watermark */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 8rem;
            color: rgba(230, 126, 34, 0.03);
            white-space: nowrap;
            pointer-events: none;
            z-index: -1;
            font-weight: 800;
        }

        .header-box {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 4px solid var(--primary);
            padding-bottom: 25px;
            margin-bottom: 35px;
        }

        .header-logo img {
            height: 90px;
        }

        .header-title {
            text-align: left;
        }

        .header-title h1 {
            margin: 0;
            color: var(--primary);
            font-size: 1.8rem;
            font-weight: 800;
        }

        .header-title p {
            margin: 5px 0 0;
            color: var(--secondary);
            font-size: 1rem;
            opacity: 0.8;
            font-weight: 600;
        }

        .meta-container {
            background: var(--bg-light);
            padding: 15px 25px;
            border-radius: 12px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 35px;
            border: 1px solid var(--border);
        }

        .meta-item {
            font-size: 0.85rem;
        }

        .meta-item span {
            display: block;
            color: #718096;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .meta-item strong {
            color: var(--secondary);
            font-size: 1rem;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            border-right: 5px solid var(--primary);
            padding-right: 15px;
            margin: 40px 0 20px;
            color: var(--secondary);
            font-size: 1.2rem;
            font-weight: 800;
        }

        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(3, 1fr); 
            gap: 20px; 
            margin-bottom: 40px; 
        }

        .stat-card { 
            background: white;
            border: 1px solid var(--border); 
            padding: 25px 20px; 
            border-radius: 15px; 
            text-align: center;
            transition: all 0.3s;
        }

        .stat-card h3 { 
            margin: 0 0 10px; 
            color: #718096; 
            font-size: 0.9rem; 
            font-weight: 700;
            text-transform: uppercase;
        }

        .stat-card .value { 
            font-size: 2.2rem; 
            font-weight: 800; 
            color: var(--secondary); 
        }

        .stat-card.highlight {
            border-color: var(--primary);
            background: #fffaf5;
        }

        .stat-card.highlight .value {
            color: var(--primary);
        }

        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0;
            margin-top: 10px;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }

        th { 
            background: #f1f5f9; 
            padding: 15px; 
            text-align: right; 
            color: var(--secondary); 
            font-weight: 800;
            font-size: 0.9rem;
            border-bottom: 2px solid var(--border);
        }

        td { 
            padding: 14px 15px; 
            border-bottom: 1px solid var(--border); 
            text-align: right;
            font-size: 0.95rem;
        }

        tr:last-child td { border-bottom: none; }

        .progress-bar {
            width: 140px;
            height: 8px;
            background: #edf2f7;
            border-radius: 10px;
            overflow: hidden;
            display: inline-block;
            vertical-align: middle;
            margin-left: 10px;
        }

        .progress-inner {
            height: 100%;
            background: var(--primary);
            border-radius: 10px;
        }

        .percentage-text {
            font-weight: 700;
            color: var(--secondary);
            font-size: 0.85rem;
        }

        .signature-area {
            margin-top: 60px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 100px;
            padding: 0 40px;
        }

        .sig-box {
            text-align: center;
            border-top: 1px dashed #cbd5e0;
            padding-top: 15px;
        }

        .sig-box p {
            margin: 0;
            font-weight: 700;
            color: var(--secondary);
        }

        .footer { 
            margin-top: 80px; 
            padding: 20px 0;
            display: flex; 
            justify-content: space-between; 
            border-top: 1px solid var(--border); 
            font-size: 0.8rem; 
            color: #a0aec0; 
            font-weight: 600;
        }

        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .stat-card { border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <div class="no-print" style="position: fixed; top: 20px; left: 20px; z-index: 100; display: flex; gap: 10px;">
        <button onclick="window.print()" style="padding: 12px 25px; background: #E67E22; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; box-shadow: 0 4px 12px rgba(230, 126, 34, 0.3);">طباعة التقرير</button>
        <button onclick="window.close()" style="padding: 12px 25px; background: #2C3E50; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: bold;">إغلاق</button>
    </div>

    <div class="report-wrapper">
        <div class="watermark">مستشفى البدر</div>

        <div class="header-box">
            <div class="header-logo">
                <img src="../../assets/img/logo.png" alt="Logo">
            </div>
            <div class="header-title">
                <h1>تقرير أداء العمليات (المختبر)</h1>
                <p>مستشفى البدر الدولي - تعز</p>
            </div>
        </div>

        <div class="meta-container">
            <div class="meta-item">
                <span>فترة التقرير</span>
                <strong><?php echo ($period === 'month') ? 'سجل الشهر الحالي' : 'الوردية اليومية (5 فجراً)'; ?></strong>
            </div>
            <div class="meta-item">
                <span>تاريخ الاستخراج</span>
                <strong><?php echo date('d/m/Y - H:i'); ?></strong>
            </div>
            <div class="meta-item">
                <span>المسؤول المصدر</span>
                <strong><?php echo $_SESSION['username']; ?></strong>
            </div>
        </div>

        <div class="section-title">مؤشرات الأداء الرئيسية (KPIs)</div>
        <div class="stats-grid">
            <div class="stat-card">
                <h3>إجمالي الطلبات</h3>
                <div class="value"><?php echo $total_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>الفحوصات المنجزة</h3>
                <div class="value"><?php echo $completed_count; ?></div>
            </div>
            <div class="stat-card highlight">
                <h3>كفاءة الإنجاز</h3>
                <div class="value"><?php echo ($total_count > 0) ? round(($completed_count / $total_count) * 100) : 0; ?>%</div>
            </div>
        </div>

        <div class="section-title">تحليل الخدمات (الأكثر طلباً)</div>
        <table>
            <thead>
                <tr>
                    <th style="width: 40px;">#</th>
                    <th>نوع الفحص / الخدمة المخبرية</th>
                    <th style="text-align: center; width: 100px;">العدد</th>
                    <th style="text-align: center; width: 220px;">النسبة من الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($top_services)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 40px; color: #999;">لا توجد بيانات مسجلة لهذه الفترة.</td></tr>
                <?php else: ?>
                    <?php $i = 1; foreach($top_services as $svc): 
                        $p = ($total_count > 0) ? round(($svc['count'] / $total_count) * 100) : 0;
                    ?>
                    <tr>
                        <td style="text-align: center; font-weight: bold; color: #a0aec0;"><?php echo $i++; ?></td>
                        <td style="font-weight: 700; color: var(--secondary);"><?php echo $svc['service_name']; ?></td>
                        <td style="text-align: center; font-weight: 800;"><?php echo $svc['count']; ?></td>
                        <td style="text-align: center;">
                            <div class="progress-bar">
                                <div class="progress-inner" style="width: <?php echo $p; ?>%;"></div>
                            </div>
                            <span class="percentage-text"><?php echo $p; ?>%</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="signature-area">
            <div class="sig-box">
                <p>قسـم تقنية المعلومات</p>
                <div style="font-size: 0.7rem; color: #a0aec0; margin-top: 5px;">توقيع واعتماد النظام</div>
            </div>
            <div class="sig-box">
                <p>إدارة المختبرات</p>
                <div style="font-size: 0.7rem; color: #a0aec0; margin-top: 5px;">توقيع المسؤول المختص</div>
            </div>
        </div>

        <div class="footer">
            <div>نظام إدارة مستشفى البدر المتكامل - الإصدار الرقمي الموحد</div>
            <div dir="ltr">Printed on: <?php echo date('Y-m-d H:i:s'); ?></div>
        </div>
    </div>

</body>
</html>
