<?php
require '../../config/database.php';
require '../../includes/header.php';

if ($_SESSION['role'] !== 'Lab' && $_SESSION['role'] !== 'Admin') { die("غير مصرح"); }

$message = "";

// Handle Result Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Single Test Save
    if (isset($_POST['save_single_test'])) {
        $tid = $_POST['test_id'];
        $val = $_POST['result_val'];
        $stmt = $pdo->prepare("UPDATE Medical_Tests SET result = ?, status = 'Completed', result_date = NOW() WHERE test_id = ?");
        if($stmt->execute([$val, $tid])) {
             $message = "تم حفظ نتيجة الفحص بنجاح.";
        }
    }
    // Batch Save
    elseif (isset($_POST['save_results'])) {
        if(isset($_POST['results']) && is_array($_POST['results'])) {
            $stmt = $pdo->prepare("UPDATE Medical_Tests SET result = ?, status = 'Completed', result_date = NOW() WHERE test_id = ?");
            $count = 0;
            foreach($_POST['results'] as $tid => $val) {
                if(trim($val) !== '') {
                    $stmt->execute([$val, $tid]);
                    $count++;
                }
            }
            $message = "تم حفظ النتائج لـ $count فحوصات بنجاح.";
        }
    }
}

$today_start = $medical_day_start;

$remaining_count = $pdo->query("SELECT COUNT(DISTINCT patient_id) FROM Medical_Tests WHERE status = 'Requested'")->fetchColumn();
$completed_today = $pdo->query("SELECT COUNT(DISTINCT patient_id) FROM Medical_Tests WHERE status = 'Completed' AND result_date >= '$today_start'")->fetchColumn();

// 1. Get IDs of patients who have PENDING tests
$pending_patient_ids = $pdo->query("SELECT DISTINCT patient_id FROM Medical_Tests WHERE status = 'Requested'")->fetchAll(PDO::FETCH_COLUMN);

$patients_queue = [];
if (!empty($pending_patient_ids)) {
    $placeholders = str_repeat('?,', count($pending_patient_ids) - 1) . '?';
    // 2. Fetch ALL tests (Requested AND Completed) for these patients to calculate progress
    $sql = "SELECT t.test_id, t.status, t.result, t.request_date, p.name as p_name, p.patient_id, s.service_name, d.name as d_name 
            FROM Medical_Tests t 
            JOIN Patients p ON t.patient_id = p.patient_id 
            JOIN Lab_Services s ON t.service_id = s.service_id 
            JOIN Doctors d ON t.doctor_id = d.doctor_id 
            WHERE t.patient_id IN ($placeholders)
            ORDER BY t.request_date ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($pending_patient_ids);
    $raw_tests = $stmt->fetchAll();

    foreach($raw_tests as $r) {
        if(!isset($patients_queue[$r['patient_id']])) {
            $patients_queue[$r['patient_id']] = [
                'name' => $r['p_name'],
                'id' => $r['patient_id'],
                'doctor' => $r['d_name'],
                'arrival' => date('H:i', strtotime($r['request_date'])),
                'tests' => [],
                'completed_count' => 0,
                'total_count' => 0
            ];
        }
        $patients_queue[$r['patient_id']]['tests'][] = [
            'id' => $r['test_id'], 
            'service' => $r['service_name'], 
            'status' => $r['status'],
            'result' => $r['result']
        ];
        $patients_queue[$r['patient_id']]['total_count']++;
        if($r['status'] === 'Completed') {
            $patients_queue[$r['patient_id']]['completed_count']++;
        }
    }
}

?>
            <li><a href="index.php" class="active">المختبر</a></li>
            <li><a href="analytics.php">الإحصائيات الجارية</a></li>
            <li><a href="change_password.php">تغيير كلمة السر</a></li>
        </ul>
        <a href="../auth/logout.php" class="logout-btn">إنهاء العمل</a>
    </div>

    <!-- MAIN INTERFACE -->
    <div class="content" style="height: 100vh; overflow: hidden; display: flex; flex-direction: column; padding: 25px;">
        
        <!-- Stats Bar -->
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
            <div class="card" style="margin:0; border-right: 5px solid #FF8C00; display: flex; align-items: center; padding: 15px; border-radius: 4px;">
                <div style="width: 40px; height: 40px; background: #FFF5E6; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-left: 15px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#FF8C00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">قيد الانتظار</div>
                    <div style="font-size: 1.4rem; font-weight: bold; color: #2c3e50;"><?php echo $remaining_count; ?> مريض</div>
                </div>
            </div>
            <div class="card" style="margin:0; border-right: 5px solid #FF8C00; display: flex; align-items: center; padding: 15px; border-radius: 4px;">
                <div style="width: 40px; height: 40px; background: #fff8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-left: 15px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#FF8C00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">تم الإنجاز اليوم</div>
                    <div style="font-size: 1.4rem; font-weight: bold; color: #2c3e50;"><?php echo $completed_today; ?> مريض</div>
                </div>
            </div>
            <div class="card" style="margin:0; border-right: 5px solid #FF8C00; display: flex; align-items: center; padding: 15px; border-radius: 4px;">
                <div style="width: 40px; height: 40px; background: #fff8f0; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-left: 15px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#FF8C00" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">القسم</div>
                    <div style="font-size: 1.2rem; font-weight: bold; color: #2c3e50;">المختبر المركزي</div>
                </div>
            </div>
            <div class="card" style="margin:0; border-right: 5px solid #2c3e50; display: flex; align-items: center; padding: 15px; border-radius: 4px;">
                <div style="width: 40px; height: 40px; background: #f0f2f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-left: 15px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2c3e50" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                </div>
                <div>
                    <div style="color: #888; font-size: 0.8rem; font-weight: bold; text-transform: uppercase;">الوقت الحالي</div>
                    <div style="font-size: 1.2rem; font-weight: bold; color: #2c3e50;" id="live-clock">--:--:--</div>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 350px 1fr; gap: 20px; flex: 1; overflow: hidden;">
            
            <!-- LEFT: Patient Queue -->
            <div class="card" style="margin: 0; padding: 0; display: flex; flex-direction: column; overflow: hidden; border-top: 4px solid var(--primary);">
                <div style="padding: 15px; background: #fff; border-bottom: 2px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 1.1rem; color: #444;">قائمة العمليات الجارية</h3>
                    <span style="background: #fdf2e9; color: var(--primary); padding: 4px 10px; border-radius: 15px; font-size: 0.8rem; font-weight: bold;">
                        تسلسل زمني
                    </span>
                </div>
                
                <div style="flex: 1; overflow-y: auto;" class="custom-scroll">
                    <?php if(empty($patients_queue)): ?>
                        <div style="text-align: center; padding: 50px 20px; color: #bbb;">
                            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 15px; opacity: 0.5;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            <p>لا توجد طلبات معلقة حالياً</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($patients_queue as $pid => $data): 
                            $percent = round(($data['completed_count'] / $data['total_count']) * 100);
                        ?>
                        <div class="queue-item" onclick="selectPatient(<?php echo htmlspecialchars(json_encode($data)); ?>)" style="padding: 15px; border-bottom: 1px solid #f0f0f0; cursor: pointer; transition: 0.2s; position: relative;">
                            <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 8px;">
                                <div style="width: 45px; height: 45px; background: var(--primary-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--primary); font-weight: bold;">
                                    <?php echo substr($data['name'], 0, 2); ?>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: bold; color: #2c3e50; font-size: 0.95rem;"><?php echo $data['name']; ?></div>
                                    <div style="font-size: 0.8rem; color: #999; margin-top: 3px;">وصول: <?php echo $data['arrival']; ?> | الطبيب: <?php echo $data['doctor']; ?></div>
                                </div>
                                <div style="text-align: right;">
                                    <div style="font-size: 0.8rem; font-weight: bold; color: var(--primary);"><?php echo $percent; ?>%</div>
                                </div>
                            </div>
                            <!-- Progress Bar -->
                            <div style="height: 4px; background: #eee; border-radius: 10px; overflow: hidden;">
                                <div style="width: <?php echo $percent; ?>%; height: 100%; background: var(--primary); transition: 0.5s;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Entry Form -->
            <div id="entry-card" class="card" style="margin: 0; display: flex; flex-direction: column; overflow: hidden; border-top: 4px solid var(--success);">
                <div id="waiting-msg" style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #ccc;">
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 20px; opacity: 0.3;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    <h3 style="font-weight: normal; color: #999;">يرجى اختيار مريض من القائمة للبدء</h3>
                </div>

                <div id="form-content" style="display: none; flex: 1; flex-direction: column; overflow: hidden;">
                    <div style="padding: 20px; background: #fafafa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <h2 style="margin: 0; color: var(--success);" id="p-name-title">---</h2>
                            <div style="font-size: 0.9rem; color: #777;" id="p-meta-info">---</div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <button onclick="clearSelection()" class="btn btn-secondary" style="padding: 8px 15px;">إلغاء</button>
                        </div>
                    </div>

                    <form method="POST" id="main-results-form" style="flex: 1; display: flex; flex-direction: column; overflow: hidden;">
                        <input type="hidden" name="save_results" value="1">
                        
                        <div id="tests-entry-list" style="flex: 1; overflow-y: auto; padding: 25px;" class="custom-scroll">
                            <!-- JS Injected Inputs -->
                        </div>

                        <div style="padding: 20px; background: #fff; border-top: 2px solid #eee; display: flex; gap: 15px;">
                            <button type="submit" class="btn btn-success" style="flex: 1; padding: 15px; font-weight: bold; font-size: 1.1rem; box-shadow: 0 4px 10px rgba(255, 140, 0, 0.2);">
                                💾 حفظ النتائج فقط
                            </button>
                            <button type="button" onclick="saveAndPrint()" class="btn btn-primary" style="flex: 1; padding: 15px; font-weight: bold; font-size: 1.1rem; background: var(--primary); box-shadow: 0 4px 10px rgba(255, 140, 0, 0.2);">
                                🖨️ حفظ وطباعة التقرير
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- PRINT TEMPLATE (Hidden) -->
        <div id="print-template" style="display: none;">
            <div style="width: 210mm; min-height: 297mm; padding: 20mm; font-family: 'Segoe UI', Arial; direction: rtl;" id="report-wrap">
                <div style="display: flex; justify-content: space-between; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 30px;">
                    <div style="text-align: right;">
                        <h1 style="margin: 0; color: #E67E22; font-size: 28px;">مستشفى البدر الدولي</h1>
                        <p style="margin: 0; color: #666;">قسم المختبرات والتشخيص الطبي</p>
                    </div>
                    <div style="text-align: left; font-size: 12px;">
                        <p>التاريخ: <span id="pr-date"></span></p>
                        <p>رقم الملف: <span id="pr-id"></span></p>
                    </div>
                </div>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 8px; margin-bottom: 30px; display: grid; grid-template-columns: 1fr 1fr;">
                    <div><strong>المريض:</strong> <span id="pr-name"></span></div>
                    <div><strong>الطبيب:</strong> <span id="pr-doctor"></span></div>
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #333; color: white;">
                            <th style="padding: 10px; border: 1px solid #333; text-align: right;">الفحص المطلـوب</th>
                            <th style="padding: 10px; border: 1px solid #333; text-align: center;">النتيجـــة</th>
                            <th style="padding: 10px; border: 1px solid #333; text-align: center;">النطاق الطبيعي</th>
                        </tr>
                    </thead>
                    <tbody id="pr-body"></tbody>
                </table>
                <div style="margin-top: 50px; display: flex; justify-content: space-between; align-items: flex-end;">
                    <div style="font-size: 11px; color: #888;">نظام مستشفى البدر المتكامل © <?php echo date('Y'); ?></div>
                    <div style="text-align: center; border-top: 1px solid #ccc; width: 150px; padding-top: 5px;">توقيع فني المختبر</div>
                </div>
            </div>
        </div>

        <script>
        let selectedPatientData = null;

        function selectPatient(data) {
            selectedPatientData = data;
            document.getElementById('waiting-msg').style.display = 'none';
            document.getElementById('form-content').style.display = 'flex';
            document.getElementById('p-name-title').innerText = data.name;
            document.getElementById('p-meta-info').innerText = 'ID: ' + data.id + ' | وصول: ' + data.arrival + ' | الطبيب: ' + data.doctor;
            
            const list = document.getElementById('tests-entry-list');
            list.innerHTML = data.tests.map((t, index) => {
                const isDone = t.status === 'Completed';
                return `
                <div class="test-input-group" style="margin-bottom: 20px; background: white; padding: 15px; border-radius: 10px; border: 1px solid ${isDone ? '#e8f5e9' : '#eee'}; background: ${isDone ? '#f9fff9' : '#fff'}; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <label style="font-weight: bold; color: #2c3e50;">
                            ${t.service} ${isDone ? '<span style="color:#27ae60; font-size:0.7rem;">(تم الرفع)</span>' : ''}
                        </label>
                        ${!isDone ? `
                        <button type="button" onclick="saveSingleTest(${t.id}, this)" class="btn" style="padding: 2px 10px; font-size: 0.75rem; background: #eee; color: #666; border: 1px solid #ddd;">
                            حفظ منفرداً
                        </button>` : ''}
                    </div>
                    <input type="text" name="results[${t.id}]" 
                           class="lab-focus-input"
                           value="${t.result || ''}"
                           placeholder="أدخل النتيجة هنا..." 
                           style="width: 100%; padding: 12px; border: 2px solid ${isDone ? '#c8e6c9' : '#edeff2'}; border-radius: 8px; font-size: 1.1rem; outline: none; transition: 0.2s;"
                           onfocus="this.style.borderColor='#FF8C00'; this.style.background='#fffcf5'"
                           onblur="this.style.borderColor='${isDone ? '#c8e6c9' : '#edeff2'}'; this.style.background='${isDone ? '#f9fff9' : '#fff'}'}"
                           ${index === 0 && !isDone ? 'autofocus' : ''}>
                </div>
            `}).join('');

            // Focus first input
            setTimeout(() => {
                const firstInput = list.querySelector('input');
                if(firstInput) firstInput.focus();
            }, 100);
        }

        function clearSelection() {
            document.getElementById('waiting-msg').style.display = 'flex';
            document.getElementById('form-content').style.display = 'none';
            selectedPatientData = null;
        }

        function saveSingleTest(tid, btn) {
            const input = btn.closest('.test-input-group').querySelector('input');
            const val = input.value;
            if(!val.trim()) { alert('يرجى إدخال نتيجة الفحص'); return; }

            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="save_single_test" value="1">
                <input type="hidden" name="test_id" value="${tid}">
                <input type="hidden" name="result_val" value="${val}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        async function saveAndPrint() {
            const form = document.getElementById('main-results-form');
            const formData = new FormData(form);
            
            // Validate all inputs have values
            let allFilled = true;
            for(let pair of formData.entries()) {
                if(pair[0].startsWith('results') && pair[1].trim() === '') {
                    allFilled = false;
                    break;
                }
            }
            
            if(!allFilled) {
                alert('يرجى تعبئة كافة الحقول قبل الطباعة.');
                return;
            }

            // Populate Print Template
            document.getElementById('pr-date').innerText = new Date().toLocaleDateString('ar-YE');
            document.getElementById('pr-id').innerText = selectedPatientData.id;
            document.getElementById('pr-name').innerText = selectedPatientData.name;
            document.getElementById('pr-doctor').innerText = selectedPatientData.doctor;
            
            let tbody = '';
            selectedPatientData.tests.forEach(t => {
                const val = formData.get(`results[${t.id}]`);
                tbody += `<tr>
                    <td style="padding: 10px; border: 1px solid #eee;">${t.service}</td>
                    <td style="padding: 10px; border: 1px solid #eee; text-align: center; font-weight: bold;">${val}</td>
                    <td style="padding: 10px; border: 1px solid #eee; text-align: center; color: #999;">Normal</td>
                </tr>`;
            });
            document.getElementById('pr-body').innerHTML = tbody;

            // Submit Form via Fetch/Ajax to save results without full page reload if possible (or just submit normally)
            // To keep it simple but functional, let's submit normally but open print window
            
            const printContent = document.getElementById('report-wrap').outerHTML;
            const printWindow = window.open('', '', 'height=800,width=1000');
            printWindow.document.write('<html><head><title>تقرير المختبر</title></head><body>' + printContent + '</body></html>');
            printWindow.document.close();
            
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
                // Finally, submit the form to the server to save data and refresh queue
                form.submit();
            }, 500);
        }

        // Live Clock
        setInterval(() => {
            const now = new Date();
            document.getElementById('live-clock').innerText = now.toLocaleTimeString('ar-YE');
        }, 1000);

        // Auto-Refresh every 60 seconds (Only if modal is not open)
        setInterval(() => {
            const modal = document.getElementById('resultModal');
            if (modal && modal.style.display !== 'flex') {
                location.reload();
            }
        }, 60000);
        </script>

        <style>
            .queue-item { transition: all 0.2s ease; }
            .queue-item:hover { 
                background: #fff8f0; 
                border-right: 5px solid var(--primary); 
                transform: translateX(-5px);
                box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            }
            .queue-item.active { background: #fff8f0; border-right: 5px solid var(--primary); box-shadow: inset 0 0 10px rgba(0,0,0,0.05); }
            
            .custom-scroll::-webkit-scrollbar { width: 8px; }
            .custom-scroll::-webkit-scrollbar-track { background: #f1f1f1; }
            .custom-scroll::-webkit-scrollbar-thumb { background: #ccc; border-radius: 10px; }
            .custom-scroll::-webkit-scrollbar-thumb:hover { background: var(--primary); }

            @media print {
                body * { visibility: hidden; }
                #report-wrap, #report-wrap * { visibility: visible; }
                #report-wrap { position: absolute; left: 0; top: 0; width: 100%; }
            }
        </style>
    </div>
</body>
</html>

