<?php
require 'db.php';

$db->exec("CREATE TABLE IF NOT EXISTS email_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
$db->exec("CREATE TABLE IF NOT EXISTS email_list_members (list_id INTEGER, person_id INTEGER, PRIMARY KEY(list_id, person_id))");

// =========================================================================
// --- MOCK AUTHENTIK HEADERS (DELETE THIS BLOCK FOR PRODUCTION) ---
// =========================================================================
$_SERVER['HTTP_X_AUTHENTIK_USERNAME'] = 'dspillman';
$_SERVER['HTTP_X_AUTHENTIK_EMAIL'] = 'dspillman@lbcgj.com';
$_SERVER['HTTP_X_AUTHENTIK_GROUPS'] = 'n-cue-savetemplates,n-cue-cuecards,n-cue-schedule,n-cue-hymnsedit,n-cue-groups,n-cue-emaillists,n-cue-preludes,n-cue-emails,n-cue-config';
// =========================================================================

// --- RBAC PERMISSIONS ENGINE ---
$authUser = $_SERVER['HTTP_X_AUTHENTIK_USERNAME'] ?? 'Unknown User';
$authEmail = $_SERVER['HTTP_X_AUTHENTIK_EMAIL'] ?? 'no-email@local';
$authGroupsRaw = $_SERVER['HTTP_X_AUTHENTIK_GROUPS'] ?? '';
$authGroups = array_map('trim', explode(',', $authGroupsRaw));

function hasPerm($groupName) {
    global $authGroups;
    return in_array($groupName, $authGroups);
}

// EDIT Permissions (Strictly Enforced Backend & UI Locks)
$canEditCueCards   = hasPerm('n-cue-cuecards');
$canSaveTemplates  = hasPerm('n-cue-savetemplates');
$canEditSchedule   = hasPerm('n-cue-schedule');
$canEditHymns      = hasPerm('n-cue-hymnsedit');
$canEditGroups     = hasPerm('n-cue-groups');
$canEditEmailLists = hasPerm('n-cue-emaillists');
$canEditPreludes   = hasPerm('n-cue-preludes');

// ACCESS Permissions (Navbar Links & Read-Only Views)
$canAccessSchedule = true; // Open to all verified users
$canAccessHymns    = true; // Open to all verified users
$canAccessGroups   = true; // Open to all verified users
$canAccessPreludes = true; // Open to all verified users

// RESTRICTED ACCESS (Hard Locks)
$canAccessConfig   = hasPerm('n-cue-config');
$canAccessEmails   = hasPerm('n-cue-emails');


$currentSchedule = $_GET['schedule'] ?? 'Main';

// --- BACKEND SECURITY GUARDS & POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Guard: Email Actions
    if ($action === 'send_email' && !$canAccessEmails) {
        die('Unauthorized: Missing n-cue-emails');
    }

    // -- EMAIL SENDER ENGINE --
    if ($action === 'send_email' && $canAccessEmails) {
        $listId = (int)$_POST['list_id'];
        $customMessage = trim($_POST['custom_message'] ?? '');
        $schedName = $_POST['schedule_name'];
        $monthKey = $_POST['month_key'];

        // Fetch recipients
        $stmt = $db->prepare("
            SELECT p.email
            FROM people p
            JOIN email_list_members elm ON p.id = elm.person_id
            WHERE elm.list_id = ? AND p.email IS NOT NULL AND p.email != ''
        ");
        $stmt->execute([$listId]);
        $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($emails)) {
            $monthName = date('F Y', strtotime($monthKey . '-01'));
            $subject = "Schedule Update: {$schedName} - {$monthName}";

            // Construct HTML Email Wrapper
            $body = "<html><body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #334155; background-color: #f8fafc; padding: 20px;'>";
            $body .= "<div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);'>";
            $body .= "<h2 style='color: #4f46e5; margin-top: 0; margin-bottom: 5px;'>Cue Schedule Update</h2>";
            $body .= "<p style='font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px;'>{$schedName} Schedule &bull; {$monthName}</p>";

            if ($customMessage) {
                $body .= "<div style='background: #f1f5f9; padding: 15px; border-left: 4px solid #4f46e5; margin-bottom: 25px; border-radius: 0 4px 4px 0; font-size: 14px;'>" . nl2br(htmlspecialchars($customMessage)) . "</div>";
            }

            // --- BUILD DYNAMIC MASTER SCHEDULE DATA ---
            $start = new DateTime($monthKey . '-01');
            $end = new DateTime($monthKey . '-' . $start->format('t'));
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end->modify('+1 day'));

            $servicesMap = [];
            foreach ($period as $dt) {
                $dayOfWeek = $dt->format('w');
                $dateStr = $dt->format('Y-m-d');
                if ($dayOfWeek == 0) {
                    $servicesMap[$dateStr][] = 'Sunday AM';
                    $servicesMap[$dateStr][] = 'Sunday PM';
                } elseif ($dayOfWeek == 3) {
                    $servicesMap[$dateStr][] = 'Wednesday PM';
                }
            }

            // Add custom dates from database
            $stmt = $db->prepare("SELECT DISTINCT service_date, service_type FROM scheduled_specials WHERE schedule_name = ? AND service_date LIKE ?");
            $stmt->execute([$schedName, $monthKey . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $d = $row['service_date'];
                $t = $row['service_type'];
                if (!isset($servicesMap[$d])) $servicesMap[$d] = [];
                if (!in_array($t, $servicesMap[$d])) $servicesMap[$d][] = $t;
            }
            ksort($servicesMap);

            // Fetch Meta data for the entire month
            $metaStmt = $db->prepare("SELECT id_key, text_value FROM choir_schedule WHERE id_key LIKE ?");
            $metaStmt->execute(["%{$monthKey}%"]);
            $serviceMeta = [];
            foreach($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $serviceMeta[$row['id_key']] = $row['text_value'];
            }

            // Fetch Specials for the entire month
            $specStmt = $db->prepare("SELECT s.*, g.name as group_name FROM scheduled_specials s LEFT JOIN groups g ON s.group_id = g.id WHERE s.schedule_name = ? AND s.service_date LIKE ? ORDER BY s.id ASC");
            $specStmt->execute([$schedName, $monthKey . '%']);
            $allSpecials = $specStmt->fetchAll(PDO::FETCH_ASSOC);

            // Render Schedule Table
            $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 14px; text-align: left;'>";
            foreach ($servicesMap as $dateStr => $types) {
                $dateFmt = date('l, M j', strtotime($dateStr));

                foreach ($types as $type) {
                    $serviceSpecials = array_filter($allSpecials, function($s) use ($dateStr, $type) {
                        return $s['service_date'] === $dateStr && $s['service_type'] === $type;
                    });

                    $pia = $serviceMeta["pianist_{$dateStr}_{$type}"] ?? '';
                    $off = $serviceMeta["offertory_{$dateStr}_{$type}"] ?? '';
                    $opn = $serviceMeta["opener_{$dateStr}_{$type}"] ?? '';
                    $spc = $serviceMeta["special_{$dateStr}_{$type}"] ?? '';
                    $hasChoir = in_array($type, ['Sunday AM', 'Sunday PM']) || ($serviceMeta["show_choir_{$dateStr}_{$type}"] ?? '0') === '1';

                    // Skip empty services to keep email clean
                    if (empty($pia) && empty($off) && empty($opn) && empty($spc) && empty($serviceSpecials)) {
                        continue;
                    }

                    $body .= "<tr><td colspan='2' style='background-color: #f8fafc; padding: 10px 12px; font-weight: 900; font-size: 13px; border-bottom: 2px solid #cbd5e1; border-top: 3px solid #fff; color: #0f172a; text-transform: uppercase;'>{$dateFmt} &bull; {$type}</td></tr>";

                    if ($pia) {
                        $body .= "<tr><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9; width: 25%;'><span style='background-color: #dbeafe; color: #1d4ed8; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.05em;'>PIA</span></td><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-weight: 700; color: #334155;'>".htmlspecialchars($pia)."</td></tr>";
                    }
                    if ($off) {
                        $body .= "<tr><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9;'><span style='background-color: #d1fae5; color: #047857; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.05em;'>OFF</span></td><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-weight: 700; color: #334155;'>".htmlspecialchars($off)."</td></tr>";
                    }
                    if ($hasChoir) {
                        if ($opn) {
                            $body .= "<tr><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9;'><span style='background-color: #fee2e2; color: #b91c1c; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.05em;'>OPN</span></td><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-weight: 700; color: #334155;'>".htmlspecialchars($opn)."</td></tr>";
                        }
                        if ($spc) {
                            $body .= "<tr><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9;'><span style='background-color: #f3e8ff; color: #7e22ce; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.05em;'>SPC</span></td><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-weight: 700; color: #334155;'>".htmlspecialchars($spc)."</td></tr>";
                        }
                    }

                    foreach ($serviceSpecials as $sp) {
                        $display = htmlspecialchars($sp['group_name'] ?: $sp['main_text'] ?: 'Special Music');
                        $body .= "<tr><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9;'><span style='background-color: #e0e7ff; color: #4338ca; padding: 3px 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: 0.05em;'>MUSIC</span></td><td style='padding: 8px 12px; border-bottom: 1px solid #f1f5f9; font-weight: 700; color: #334155;'>{$display}</td></tr>";
                    }
                }
            }
            $body .= "</table>";

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $domain = $_SERVER['HTTP_HOST'] ?? 'qr.corp.lbcgj.com';
            $printUrl = $protocol . "://" . $domain . "/print_schedule.php?schedule=" . urlencode($schedName) . "&month=" . urlencode($monthKey);

            $body .= "<div style='text-align: center; margin: 30px 0;'>";
            $body .= "<a href='{$printUrl}' style='display: inline-block; background-color: #4f46e5; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; letter-spacing: 0.5px;'>View Live Monthly Calendar</a>";
            $body .= "</div>";

            $body .= "<p style='margin-top: 40px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 15px;'>This email was sent by <strong>{$authUser}</strong> via Cue. Reply to this email to contact them directly.</p>";
            $body .= "</div></body></html>";

            // Native SMTP Socket Implementation
            $smtpHost = '127.0.0.1';
            $smtpPort = 25;
            $from = 'noreply@corp.lbcgj.com';

            if (!function_exists('smtp_cmd')) {
                function smtp_cmd($socket, $cmd) {
                    if ($cmd) fwrite($socket, $cmd . "\r\n");
                    $res = '';
                    while ($str = fgets($socket, 515)) {
                        $res .= $str;
                        if (substr($str, 3, 1) == ' ') break;
                    }
                    return $res;
                }
            }

            $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 5);
            if ($socket) {
                stream_set_timeout($socket, 5);
                smtp_cmd($socket, null); // Read greeting
                smtp_cmd($socket, "EHLO corp.lbcgj.com");
                smtp_cmd($socket, "MAIL FROM: <$from>");

                foreach ($emails as $email) {
                    smtp_cmd($socket, "RCPT TO: <$email>");
                }

                smtp_cmd($socket, "DATA");

                $headers = "From: CUE <$from>\r\n";
                $headers .= "Reply-To: $authUser <$authEmail>\r\n";
                $headers .= "Subject: $subject\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";

                smtp_cmd($socket, $headers . $body . "\r\n.");
                smtp_cmd($socket, "QUIT");
                fclose($socket);
            }
        }
        header("Location: schedule.php?schedule=" . urlencode($schedName) . "&emailed=1"); exit;
    }


    // Guard: Normal Schedule Edits
    if (!$canEditSchedule) {
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-schedule']));
    }

    if ($action === 'add_schedule') {
        $stmt = $db->prepare("INSERT INTO scheduled_specials (service_date, service_type, item_type, group_id, schedule_name) VALUES (?, ?, 'Special Music', ?, ?)");
        $stmt->execute([$_POST['date'], $_POST['service_type'], $_POST['group_id'], $currentSchedule]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'remove_schedule') {
        $db->prepare("DELETE FROM scheduled_specials WHERE id = ?")->execute([$_POST['id']]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'add_custom_service') {
        $stmt = $db->prepare("INSERT INTO scheduled_specials (service_date, service_type, item_type, group_id, schedule_name) VALUES (?, ?, 'Special Music', ?, ?)");
        $stmt->execute([$_POST['date'], $_POST['custom_title'], $_POST['group_id'], $currentSchedule]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'delete_custom_service') {
        $stmt = $db->prepare("DELETE FROM scheduled_specials WHERE service_date = ? AND service_type = ? AND schedule_name = ?");
        $stmt->execute([$_POST['date'], $_POST['service_type'], $currentSchedule]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'save_meta') {
        $stmt = $db->prepare("INSERT INTO choir_schedule (id_key, text_value) VALUES (?, ?) ON CONFLICT(id_key) DO UPDATE SET text_value = excluded.text_value");
        $stmt->execute([$_POST['id_key'], $_POST['text_value']]);
        echo json_encode(['status' => 'success']); exit;
    }
}

// --- DATA FETCHING ---
$groupsData = $db->query("SELECT id, name FROM groups ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$membersData = $db->query("SELECT group_id, person_id FROM group_members")->fetchAll(PDO::FETCH_ASSOC);
$emailLists = [];
try { $emailLists = $db->query("SELECT id, name FROM email_lists ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

$peopleList = $db->query("SELECT id, first_name, last_name FROM people ORDER BY last_name ASC, first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$peopleData = [];
foreach($peopleList as $p) { $peopleData[$p['id']] = trim($p['first_name'] . ' ' . $p['last_name']); }

$stmt = $db->prepare("SELECT id, service_date, service_type, group_id FROM scheduled_specials WHERE schedule_name = ?");
$stmt->execute([$currentSchedule]);
$scheduleData = $stmt->fetchAll(PDO::FETCH_ASSOC);

$capacities = [];
foreach($db->query("SELECT * FROM service_capacities") as $row) { $capacities[$row['service_type']] = $row['max_specials']; }

$serviceMeta = [];
foreach($db->query("SELECT * FROM choir_schedule") as $row) { $serviceMeta[$row['id_key']] = $row['text_value']; }

$allSchedules = $db->query("SELECT DISTINCT schedule_name FROM scheduled_specials")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('Main', $allSchedules)) array_unshift($allSchedules, 'Main');
if (!in_array('Spanish', $allSchedules)) $allSchedules[] = 'Spanish';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cue - Master Schedule</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>
    <style>
        .fc { font-family: inherit; }
        .fc-theme-standard .fc-scrollgrid { border-color: #cbd5e1; border-radius: 0.5rem; overflow: hidden; }
        .fc-theme-standard th { background-color: #f1f5f9; border-color: #cbd5e1; padding: 0.5rem 0; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #475569; }
        .fc-theme-standard td, .fc-theme-standard th { border-color: #e2e8f0; }
        .fc .fc-toolbar-title { font-weight: 900; color: #0f172a; font-size: 1.5rem; }
        .fc .fc-button-primary { background-color: #4f46e5; border-color: #4f46e5; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.75rem; }
        .fc-daygrid-day-number { color: #334155; font-weight: 800; font-size: 0.875rem; padding: 0.5rem !important; cursor: pointer; transition: color 0.2s; }
        .fc-daygrid-day-number:hover { color: #4f46e5; text-decoration: underline; }

        .fc-daygrid-day-events { display: none !important; }
        .fc-daygrid-day-frame { padding: 4px; display: flex; flex-direction: column; min-height: 140px; }
        .custom-day-content { flex-grow: 1; display: flex; flex-direction: column; gap: 8px; margin-top: 2px; }

        .service-section { display: flex; flex-direction: column; gap: 4px; background: #ffffff; padding: 6px; border-radius: 6px; border: 1px solid #cbd5e1; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }

        .sched-pill {
            background-color: #e0e7ff; color: #312e81; border-radius: 4px;
            padding: 4px 6px; font-size: 0.65rem; font-weight: 800; margin-bottom: 2px;
            text-align: left; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            cursor: pointer; border: 1px solid #c7d2fe; transition: all 0.2s; width: 100%;
        }
        .sched-pill.can-edit:hover { background-color: #fee2e2; color: #991b1b; border-color: #fca5a5; text-decoration: line-through; }
        .sched-pill.read-only { cursor: default; }

        .add-dropdown {
            font-size: 0.65rem; font-weight: 800; padding: 4px; width: 100%;
            border-radius: 4px; border: 1px dashed #64748b;
            background-color: #f8fafc; color: #334155; cursor: pointer; transition: all 0.2s; outline: none;
        }
        .add-dropdown:hover, .add-dropdown:focus { border-color: #4f46e5; color: #4f46e5; background-color: #ffffff; border-style: solid; box-shadow: 0 0 0 1px #4f46e5; }
        .add-dropdown:disabled { background-color: #f1f5f9; border-color: #cbd5e1; color: #94a3b8; cursor: not-allowed; }

        option { background-color: white; color: #0f172a; font-family: inherit; font-weight: 600; }
        .soft-locked-option { color: #dc2626; background-color: #fef2f2; font-family: 'Courier New', Courier, monospace; font-style: italic; }

        .choir-input { width: 100%; font-size: 0.65rem; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px; background: #ffffff; color: #0f172a; outline: none; font-weight: 700; transition: all 0.2s; }
        .choir-input:focus { border-color: #4f46e5; box-shadow: 0 0 0 1px #4f46e5; }
        .choir-input::placeholder { color: #94a3b8; font-weight: 600; }

        /* Read Only Mode for inputs */
        .choir-input:disabled { background: #f8fafc; color: #334155; border-color: #e2e8f0; cursor: default; }

        .role-badge { font-size: 7px; font-weight: 900; padding: 2px 4px; border-radius: 3px; display: flex; align-items: center; justify-content: center; width: 28px; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col font-sans">

    <?php if (isset($_GET['emailed'])): ?>
    <div class="fixed bottom-6 right-6 bg-indigo-600 text-white px-6 py-3 rounded-lg shadow-2xl font-bold transition-opacity duration-300 z-50 flex items-center gap-2" id="email-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
        Email Sent Successfully!
    </div>
    <script>setTimeout(() => { document.getElementById('email-toast').style.opacity = '0'; }, 3000);</script>
    <?php endif; ?>

    <?php if ($canAccessEmails): ?>
    <div id="email-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden flex flex-col">
            <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-sm flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-500" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                    Email Schedule
                </h3>
                <button onclick="closeEmailModal()" class="text-slate-400 hover:text-slate-700 text-xl leading-none">&times;</button>
            </div>

            <form method="POST" class="p-6 flex flex-col gap-5">
                <input type="hidden" name="action" value="send_email">
                <input type="hidden" name="schedule_name" value="<?= htmlspecialchars($currentSchedule) ?>">
                <input type="hidden" name="month_key" id="email_month_key" value="">

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Reply-To Address</label>
                    <div class="w-full text-sm p-2.5 bg-slate-100 border border-slate-200 rounded text-slate-500 font-bold cursor-not-allowed">
                        <?= htmlspecialchars($authEmail) ?>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Select Distribution List <span class="text-red-500">*</span></label>
                    <select name="list_id" required class="w-full text-sm p-2.5 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-white font-bold">
                        <option value="">-- Choose a List --</option>
                        <?php foreach($emailLists as $list): ?>
                            <option value="<?= $list['id'] ?>"><?= htmlspecialchars($list['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Optional Message</label>
                    <textarea name="custom_message" rows="3" placeholder="Add any special notes or context here..." class="w-full text-sm p-2.5 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition resize-none"></textarea>
                </div>

                <div class="pt-2 flex justify-end gap-3">
                    <button type="button" onclick="closeEmailModal()" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-800 transition">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-black uppercase tracking-widest transition shadow-sm">Send Now</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <header class="bg-slate-900 text-white p-4 shadow-md sticky top-0 z-50 shrink-0">
        <div class="max-w-[1800px] mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-black text-indigo-400 tracking-tighter">CUE</h1>

            <div class="flex items-center">
                <nav class="space-x-8 text-sm font-bold uppercase tracking-widest text-slate-400 flex items-center">
                    <a href="index.php" class="hover:text-white">Builder</a>
                    <a href="schedule.php" class="text-white border-b-2 border-indigo-500 pb-1">Schedule</a>
                    <?php if($canAccessHymns): ?><a href="hymns.php" class="hover:text-white">Hymns</a><?php endif; ?>
                    <?php if($canAccessGroups): ?><a href="groups.php" class="hover:text-white">Groups</a><?php endif; ?>
                    <?php if($canAccessPreludes): ?><a href="preludes.php" class="hover:text-white">Preludes</a><?php endif; ?>
                    <?php if($canAccessConfig): ?><a href="config.php" class="hover:text-white">Config</a><?php endif; ?>
                </nav>

                <div class="flex items-center gap-3 ml-8 pl-8 border-l border-slate-700">
                    <div class="text-right hidden sm:block">
                        <div class="text-xs font-bold text-white leading-none"><?= htmlspecialchars($authUser) ?></div>
                        <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1"><?= htmlspecialchars($authEmail) ?></div>
                    </div>
                    <div class="w-9 h-9 rounded-full bg-indigo-600 border-2 border-indigo-400 flex items-center justify-center text-white font-black text-sm shadow-sm cursor-default" title="Verified as <?= htmlspecialchars($authUser) ?>">
                        <?= strtoupper(substr($authUser, 0, 1)) ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="flex-1 max-w-[1800px] mx-auto w-full p-8">
        <div class="flex justify-between items-end mb-6">
            <div>
                <h2 class="text-4xl font-black text-slate-900 leading-none mb-2">Master Schedule</h2>
                <div class="flex items-center gap-4 mt-3">
                    <select onchange="window.location.href='?schedule='+this.value" class="bg-indigo-50 text-indigo-700 font-black text-xs uppercase tracking-widest p-2 px-4 rounded-lg border border-indigo-200 outline-none cursor-pointer shadow-sm hover:bg-indigo-100 transition">
                        <?php foreach($allSchedules as $sch): ?>
                            <option value="<?= htmlspecialchars($sch) ?>" <?= $sch === $currentSchedule ? 'selected' : '' ?>><?= htmlspecialchars($sch) ?> Schedule</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm flex items-center gap-3">
                    <div class="text-[10px] font-black uppercase tracking-widest text-slate-400">Alternate<br><span id="current-month-label" class="text-indigo-600">This Month</span></div>
                    <select id="monthly-alternate-select" <?= $canEditSchedule ? '' : 'disabled' ?> class="text-sm font-bold p-2 border border-slate-300 rounded focus:border-indigo-500 outline-none w-48 disabled:bg-slate-50 disabled:text-slate-500" onchange="saveMonthlyAlternate(this.value)">
                        <option value="">-- None --</option>
                        <?php foreach($groupsData as $g): ?>
                            <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($canAccessEmails): ?>
                    <button type="button" onclick="openEmailModal()" class="bg-indigo-100 border border-indigo-200 text-indigo-700 px-4 py-3 rounded-lg font-black uppercase tracking-widest text-[11px] transition shadow-sm hover:bg-indigo-200 flex items-center justify-center" title="Send via Email">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                        Email
                    </button>
                <?php endif; ?>

                <a href="#" id="print-btn" target="_blank" class="bg-indigo-600 text-white px-5 py-3 rounded-lg font-black uppercase tracking-widest text-[11px] hover:bg-indigo-700 transition shadow-sm flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" /></svg>
                    Print Month
                </a>
            </div>
        </div>

        <main class="bg-white border border-slate-300 rounded-xl shadow-sm p-6 relative">
            <?php if (!$canEditSchedule): ?>
                <div class="absolute top-2 right-4 text-xs font-black uppercase tracking-widest text-slate-300 pointer-events-none z-10">Read Only Mode</div>
            <?php endif; ?>
            <div id="calendar"></div>
            <?php if ($canEditSchedule): ?>
                <div class="text-[10px] font-bold text-slate-400 mt-3 text-right tracking-widest uppercase">
                    💡 Tip: Click any Date Number to add a Day Marker
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const canEditSchedule = <?= $canEditSchedule ? 'true' : 'false' ?>;
        const groupsData = <?= json_encode($groupsData) ?>;
        const membersData = <?= json_encode($membersData) ?>;
        const peopleData = <?= json_encode($peopleData) ?>;
        const capacities = <?= json_encode($capacities) ?>;
        const serviceMeta = <?= json_encode($serviceMeta) ?>;
        let scheduleData = <?= json_encode($scheduleData) ?>;

        let currentMonthKey = '';

        <?php if ($canAccessEmails): ?>
            function openEmailModal() {
                const modal = document.getElementById('email-modal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
            function closeEmailModal() {
                const modal = document.getElementById('email-modal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
            document.getElementById('email-modal').addEventListener('click', function(e) {
                if (e.target === this) closeEmailModal();
            });
            document.getElementById('email-modal').addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeEmailModal();
            });
        <?php endif; ?>

        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth' },
                firstDay: 0,
                height: 'auto',
                datesSet: function(info) {
                    const midDate = new Date((info.start.getTime() + info.end.getTime()) / 2);
                    currentMonthKey = midDate.getFullYear() + '-' + String(midDate.getMonth() + 1).padStart(2, '0');

                    document.getElementById('current-month-label').innerText = midDate.toLocaleString('default', { month: 'long', year: 'numeric' });
                    document.getElementById('monthly-alternate-select').value = serviceMeta[`alternate_${currentMonthKey}`] || '';
                    document.getElementById('print-btn').href = `print_schedule.php?schedule=<?= urlencode($currentSchedule) ?>&month=${currentMonthKey}`;

                    <?php if ($canAccessEmails): ?>
                        document.getElementById('email_month_key').value = currentMonthKey;
                    <?php endif; ?>
                },
                dayCellDidMount: function(info) {
                    renderCustomCell(info);
                }
            });
            calendar.render();
        });

        async function saveMeta(key, val) {
            if (!canEditSchedule) return;
            let formData = new FormData();
            formData.append('action', 'save_meta');
            formData.append('id_key', key);
            formData.append('text_value', val);
            await fetch('schedule.php?schedule=<?= urlencode($currentSchedule) ?>', { method: 'POST', body: formData });
            serviceMeta[key] = val;
        }

        async function saveMonthlyAlternate(val) {
            await saveMeta(`alternate_${currentMonthKey}`, val);
        }

        async function addDayMarker(dateObj) {
            if (!canEditSchedule) return;
            const offset = dateObj.getTimezoneOffset();
            const dateStr = new Date(dateObj.getTime() - (offset*60*1000)).toISOString().split('T')[0];
            const currentMarker = serviceMeta[`marker_${dateStr}`] || '';
            const marker = prompt(`Enter a Day Marker for ${dateStr} (e.g. "Easter Sunday")\nLeave blank to clear/delete:`, currentMarker);

            if (marker !== null) {
                await saveMeta(`marker_${dateStr}`, marker.trim());
                window.location.reload();
            }
        }

        function renderCustomCell(info) {
            if (info.date.getMonth() !== info.view.currentStart.getMonth()) {
                const numEl = info.el.querySelector('.fc-daygrid-day-number');
                if (numEl) numEl.style.display = 'none';
                info.el.style.background = '#f8fafc';
                return;
            }

            const date = info.date;
            const offset = date.getTimezoneOffset();
            const dateStr = new Date(date.getTime() - (offset*60*1000)).toISOString().split('T')[0];
            const dayOfWeek = date.getDay();

            const numEl = info.el.querySelector('.fc-daygrid-day-number');
            if (numEl) {
                if (canEditSchedule) {
                    numEl.title = "Click to add a Day Marker";
                    numEl.onclick = (e) => {
                        e.preventDefault();
                        addDayMarker(info.date);
                    };
                } else {
                    numEl.style.cursor = 'default';
                    numEl.classList.remove('hover:text-[#4f46e5]', 'hover:underline');
                }
            }

            const customContainer = document.createElement('div');
            customContainer.className = 'custom-day-content';

            const markerText = serviceMeta[`marker_${dateStr}`];
            if (markerText) {
                const markerDiv = document.createElement('div');
                markerDiv.className = 'text-red-600 font-black text-[10px] uppercase tracking-wider text-center leading-tight';
                if (canEditSchedule) {
                    markerDiv.classList.add('cursor-pointer', 'hover:underline');
                    markerDiv.title = "Click to edit or remove";
                    markerDiv.onclick = (e) => { e.stopPropagation(); addDayMarker(date); };
                }
                markerDiv.innerText = markerText;
                customContainer.appendChild(markerDiv);
            }

            let servicesToday = [];
            if (dayOfWeek === 0) servicesToday = ['Sunday AM', 'Sunday PM'];
            if (dayOfWeek === 3) servicesToday = ['Wednesday PM'];

            const customSchedules = scheduleData.filter(s => s.service_date === dateStr && !['Sunday AM', 'Sunday PM', 'Wednesday PM'].includes(s.service_type));
            const uniqueCustomServices = [...new Set(customSchedules.map(s => s.service_type))];
            servicesToday = servicesToday.concat(uniqueCustomServices);

            servicesToday.forEach(serviceType => {
                const section = document.createElement('div');
                section.className = 'service-section';

                const scheduledForThisService = scheduleData.filter(s => s.service_date === dateStr && s.service_type === serviceType);
                const capacity = capacities[serviceType] || 0;

                const openerKey = `opener_${dateStr}_${serviceType}`;
                const specialKey = `special_${dateStr}_${serviceType}`;
                const offKey = `offertory_${dateStr}_${serviceType}`;
                const piaKey = `pianist_${dateStr}_${serviceType}`;
                const showChoirKey = `show_choir_${dateStr}_${serviceType}`;

                const hasChoir = (serviceType === 'Sunday AM' || serviceType === 'Sunday PM' || serviceMeta[showChoirKey] == '1');

                let actionsHtml = '';
                if (canEditSchedule) {
                    if (!hasChoir) {
                        actionsHtml += `<button type="button" class="text-slate-400 hover:text-indigo-600 font-black text-[10px] leading-none ml-2" title="Add Choir Slots" onclick="event.stopPropagation(); saveMeta('${showChoirKey}', '1'); window.location.reload();">+ CHOIR</button>`;
                    }
                    if (!['Sunday AM', 'Sunday PM', 'Wednesday PM'].includes(serviceType)) {
                        actionsHtml += `<button type="button" class="text-slate-400 hover:text-red-600 font-black text-[12px] leading-none ml-2" title="Delete Event" onclick="event.stopPropagation(); deleteCustomService('${dateStr}', '${serviceType.replace(/'/g, "\\'")}');">&times;</button>`;
                    }
                }

                section.innerHTML = `
                    <div class="flex items-baseline mb-1 px-1 justify-between border-b border-slate-200 pb-1">
                        <div class="text-[10px] font-black text-slate-800 uppercase tracking-widest leading-none flex items-center w-full">
                            <span class="flex-1">${serviceType}</span>
                            ${actionsHtml}
                        </div>
                    </div>
                `;

                const inputAttrs = canEditSchedule
                    ? 'onmousedown="event.stopPropagation()" onkeydown="event.stopPropagation()"'
                    : 'disabled';

                const piaDiv = document.createElement('div');
                piaDiv.className = "flex gap-1 mb-1";
                piaDiv.innerHTML = `
                    <span class="role-badge bg-blue-100 text-blue-700">PIA</span>
                    <input type="text" class="choir-input flex-1 !mt-0" placeholder="Pianist..." value="${(serviceMeta[piaKey] || '').replace(/"/g, '&quot;')}" ${canEditSchedule ? `onblur="saveMeta('${piaKey}', this.value)"` : ''} onclick="event.stopPropagation()" ${inputAttrs}>
                `;
                section.appendChild(piaDiv);

                const offDiv = document.createElement('div');
                offDiv.className = "flex gap-1 mb-1";
                offDiv.innerHTML = `
                    <span class="role-badge bg-emerald-100 text-emerald-700">OFF</span>
                    <input type="text" class="choir-input flex-1 !mt-0" placeholder="Offertory..." value="${(serviceMeta[offKey] || '').replace(/"/g, '&quot;')}" ${canEditSchedule ? `onblur="saveMeta('${offKey}', this.value)"` : ''} onclick="event.stopPropagation()" ${inputAttrs}>
                `;
                section.appendChild(offDiv);

                if (hasChoir) {
                    const openerDiv = document.createElement('div');
                    openerDiv.className = "flex gap-1 mb-1";
                    openerDiv.innerHTML = `
                        <span class="role-badge bg-red-100 text-red-700">OPN</span>
                        <input type="text" class="choir-input flex-1 !mt-0" placeholder="Choir Opener..." value="${(serviceMeta[openerKey] || '').replace(/"/g, '&quot;')}" ${canEditSchedule ? `onblur="saveMeta('${openerKey}', this.value)"` : ''} onclick="event.stopPropagation()" ${inputAttrs}>
                    `;
                    section.appendChild(openerDiv);

                    const specialDiv = document.createElement('div');
                    specialDiv.className = "flex gap-1 mb-1";
                    specialDiv.innerHTML = `
                        <span class="role-badge bg-purple-100 text-purple-700">SPC</span>
                        <input type="text" class="choir-input flex-1 !mt-0" placeholder="Choir Special..." value="${(serviceMeta[specialKey] || '').replace(/"/g, '&quot;')}" ${canEditSchedule ? `onblur="saveMeta('${specialKey}', this.value)"` : ''} onclick="event.stopPropagation()" ${inputAttrs}>
                    `;
                    section.appendChild(specialDiv);
                }

                const breakDiv = document.createElement('div');
                breakDiv.className = 'border-t border-slate-100 my-0.5';
                section.appendChild(breakDiv);

                const slotsToShow = canEditSchedule ? Math.max(capacity, scheduledForThisService.length + 1) : scheduledForThisService.length;

                for (let i = 0; i < slotsToShow; i++) {
                    const sched = scheduledForThisService[i];

                    if (sched) {
                        const group = groupsData.find(g => g.id == sched.group_id);
                        const pill = document.createElement(canEditSchedule ? 'button' : 'div');
                        pill.className = canEditSchedule ? 'sched-pill can-edit' : 'sched-pill read-only';
                        pill.innerText = group ? group.name : 'Unknown Group';

                        if (canEditSchedule) {
                            pill.title = "Click to remove";
                            pill.onmousedown = (e) => e.stopPropagation();
                            pill.onclick = (e) => { e.stopPropagation(); removeSchedule(sched.id); };
                        }
                        section.appendChild(pill);
                    } else if (canEditSchedule) {
                        const select = document.createElement('select');
                        select.className = 'add-dropdown';
                        select.innerHTML = `<option value="">+ Assign Special</option>`;

                        select.onmousedown = (e) => e.stopPropagation();
                        select.onclick = (e) => e.stopPropagation();
                        select.onkeydown = (e) => e.stopPropagation();

                        groupsData.forEach(g => {
                            const lockInfo = check14DayLock(g.id, dateStr);
                            const opt = document.createElement('option');
                            opt.value = g.id;

                            if (lockInfo.locked) {
                                opt.innerHTML = `⚠ ${g.name} — ${lockInfo.reason}`;
                                opt.className = 'soft-locked-option';
                            } else {
                                opt.innerHTML = g.name;
                            }
                            select.appendChild(opt);
                        });

                        select.onchange = function() {
                            const val = this.value;
                            if (!val) return;
                            this.disabled = true;
                            saveSchedule(dateStr, serviceType, val);
                        };
                        section.appendChild(select);
                    }
                }
                customContainer.appendChild(section);
            });

            customContainer.appendChild(document.createElement('div')).className = 'flex-1';

            if (canEditSchedule) {
                const addEventBtn = document.createElement('button');
                addEventBtn.className = 'text-[9px] font-black text-slate-400 hover:text-indigo-600 uppercase tracking-widest text-center w-full transition mt-2';
                addEventBtn.innerText = '+ Event';
                addEventBtn.onmousedown = (e) => e.stopPropagation();
                addEventBtn.onclick = (e) => { e.stopPropagation(); addCustomEvent(info.date); };
                customContainer.appendChild(addEventBtn);
            }

            const frame = info.el.querySelector('.fc-daygrid-day-frame');
            if (frame) {
                const existing = frame.querySelector('.custom-day-content');
                if (existing) existing.remove();
                frame.appendChild(customContainer);
            }
        }

        function check14DayLock(groupIdToCheck, targetDateStr) {
            const targetDate = new Date(targetDateStr);
            const monthKey = targetDateStr.substring(0, 7);
            const MS_PER_DAY = 1000 * 60 * 60 * 24;

            const altGroupId = serviceMeta[`alternate_${monthKey}`];
            if (altGroupId && altGroupId == groupIdToCheck) {
                return { locked: true, reason: `Monthly Alternate` };
            }

            const myMemberIds = membersData.filter(m => m.group_id == groupIdToCheck).map(m => m.person_id);

            for (const sched of scheduleData) {
                const schedDate = new Date(sched.service_date);
                const diffDays = Math.abs((targetDate - schedDate) / MS_PER_DAY);

                if (diffDays === 0) {
                    if (sched.group_id == groupIdToCheck) {
                        return { locked: true, reason: `Singing today` };
                    }
                    const schedGroupMembers = membersData.filter(m => m.group_id == sched.group_id).map(m => m.person_id);
                    const sharedMembers = myMemberIds.filter(id => schedGroupMembers.includes(id));
                    if (sharedMembers.length > 0) {
                        const personName = peopleData[sharedMembers[0]] || 'A member';
                        return { locked: true, reason: `${personName} singing today` };
                    }
                }
                else if (diffDays <= 14) {
                    const formattedPastDate = schedDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });

                    if (sched.group_id == groupIdToCheck) {
                        return { locked: true, reason: `Sang ${formattedPastDate}` };
                    }

                    const schedGroupMembers = membersData.filter(m => m.group_id == sched.group_id).map(m => m.person_id);
                    const sharedMembers = myMemberIds.filter(id => schedGroupMembers.includes(id));
                    if (sharedMembers.length > 0) {
                        const personName = peopleData[sharedMembers[0]] || 'A member';
                        return { locked: true, reason: `${personName} sang ${formattedPastDate}` };
                    }
                }
            }
            return { locked: false, reason: '' };
        }

        async function saveSchedule(dateStr, serviceType, groupId) {
            if (!canEditSchedule) return;
            let formData = new FormData();
            formData.append('action', 'add_schedule');
            formData.append('date', dateStr);
            formData.append('service_type', serviceType);
            formData.append('group_id', groupId);
            await fetch('schedule.php?schedule=<?= urlencode($currentSchedule) ?>', { method: 'POST', body: formData });
            window.location.reload();
        }

        async function removeSchedule(id) {
            if (!canEditSchedule) return;
            let formData = new FormData();
            formData.append('action', 'remove_schedule');
            formData.append('id', id);
            await fetch('schedule.php?schedule=<?= urlencode($currentSchedule) ?>', { method: 'POST', body: formData });
            window.location.reload();
        }

        async function addCustomEvent(dateObj) {
            if (!canEditSchedule) return;
            const offset = dateObj.getTimezoneOffset();
            const dateStr = new Date(dateObj.getTime() - (offset*60*1000)).toISOString().split('T')[0];

            const title = prompt(`Enter a title for a special event on ${dateStr} (e.g. "Missions Conference"):`);
            if (!title || title.trim() === '') return;

            let formData = new FormData();
            formData.append('action', 'add_custom_service');
            formData.append('date', dateStr);
            formData.append('custom_title', title.trim());
            formData.append('group_id', 0);
            await fetch('schedule.php?schedule=<?= urlencode($currentSchedule) ?>', { method: 'POST', body: formData });
            window.location.reload();
        }

        async function deleteCustomService(dateStr, serviceType) {
            if (!canEditSchedule) return;
            if (!confirm(`Are you sure you want to delete the event "${serviceType}"?`)) return;
            let formData = new FormData();
            formData.append('action', 'delete_custom_service');
            formData.append('date', dateStr);
            formData.append('service_type', serviceType);
            await fetch('schedule.php?schedule=<?= urlencode($currentSchedule) ?>', { method: 'POST', body: formData });
            window.location.reload();
        }
    </script>
</body>
</html>