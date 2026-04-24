<?php
require 'db.php';

// Safe Schema Updates for new features
try { $db->exec("ALTER TABLE service_items ADD COLUMN text_color_override TEXT DEFAULT '#000000'"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE template_items ADD COLUMN text_color_override TEXT DEFAULT '#000000'"); } catch (Exception $e) {}
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


$currentServiceId = $_GET['service_id'] ?? null;
$svc = null;
if ($currentServiceId) {
    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$currentServiceId]);
    $svc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$svc) { $currentServiceId = null; }
}

// --- BACKEND SECURITY GUARDS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Guard: Template Actions
    if (in_array($action, ['save_template', 'delete_template']) && !$canSaveTemplates) {
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-savetemplates']));
    }

    // Guard: Cue Card Edit Actions
    $editActions = ['create_service', 'delete_service', 'log_hymn_dates', 'add_item', 'add_item_at_index', 'add_suggested', 'update_item_ajax', 'update_order', 'delete_item'];
    if (in_array($action, $editActions) && !$canEditCueCards) {
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-cuecards']));
    }

    // Guard: Email Actions
    if ($action === 'send_email' && !$canAccessEmails) {
        die('Unauthorized: Missing n-cue-emails');
    }

    // -- EMAIL SENDER ENGINE --
    if ($action === 'send_email' && $canAccessEmails) {
        $listId = (int)$_POST['list_id'];
        $customMessage = trim($_POST['custom_message'] ?? '');
        $svcId = (int)$_POST['service_id'];

        $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
        $stmt->execute([$svcId]);
        $service = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch recipients
        $stmt = $db->prepare("
            SELECT p.email
            FROM people p
            JOIN email_list_members elm ON p.id = elm.person_id
            WHERE elm.list_id = ? AND p.email IS NOT NULL AND p.email != ''
        ");
        $stmt->execute([$listId]);
        $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($emails) && $service) {
            $subject = "Service Schedule: " . $service['service_type'] . " - " . date('M j, Y', strtotime($service['service_date']));

            // Construct HTML Email
            $body = "<html><body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #334155; background-color: #f8fafc; padding: 20px;'>";
            $body .= "<div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);'>";
            $body .= "<h2 style='color: #4f46e5; margin-top: 0; margin-bottom: 5px;'>Cue Service Update</h2>";
            $body .= "<p style='font-size: 16px; font-weight: bold; color: #0f172a; margin-top: 0; margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px;'>" . htmlspecialchars($service['service_type']) . " &bull; " . date('l, M j, Y', strtotime($service['service_date'])) . "</p>";

            if ($customMessage) {
                $body .= "<div style='background: #f1f5f9; padding: 15px; border-left: 4px solid #4f46e5; margin-bottom: 25px; border-radius: 0 4px 4px 0; font-size: 14px;'>" . nl2br(htmlspecialchars($customMessage)) . "</div>";
            }

            // Build the Cue Card HTML Snapshot
            $stmt = $db->prepare("SELECT * FROM service_items WHERE service_id = ? ORDER BY sort_order ASC");
            $stmt->execute([$svcId]);
            $emailItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($emailItems)) {
                $body .= "<table style='width: 100%; border-collapse: collapse; margin-bottom: 30px; font-size: 14px; text-align: left;'>";
                foreach ($emailItems as $item) {
                    if ($item['item_type'] === 'Section Break') {
                        $body .= "<tr><td colspan='3' style='border-bottom: 2px solid #0f172a; padding: 4px 0;'></td></tr>";
                        continue;
                    }

                    $label = htmlspecialchars($item['label'] ?: $item['item_type']);
                    $middle = htmlspecialchars($item['supplemental_info'] ?? '');
                    $main = htmlspecialchars($item['main_text'] ?? '');

                    // Quick resolutions for specific types
                    if ($item['item_type'] === 'Hymn' && $item['hymn_id']) {
                        $h = $db->prepare("SELECT Name, NVB FROM hymns WHERE ID = ?");
                        $h->execute([$item['hymn_id']]);
                        $hData = $h->fetch(PDO::FETCH_ASSOC);
                        if ($hData) {
                            $main = htmlspecialchars($item['main_text'] ?: ($hData['Name'] . " (#" . $hData['NVB'] . ")"));
                        }
                    } elseif ($item['item_type'] === 'Special Music' && $item['group_id']) {
                        $g = $db->prepare("SELECT name FROM groups WHERE id = ?");
                        $g->execute([$item['group_id']]);
                        $main = htmlspecialchars($g->fetchColumn() ?: '');
                    } elseif ($item['item_type'] === 'Prelude' && $item['prelude_set_id']) {
                        $s = $db->prepare("SELECT name FROM prelude_sets WHERE id = ?");
                        $s->execute([$item['prelude_set_id']]);
                        $main = htmlspecialchars($s->fetchColumn() ?: '');
                    }

                    $textColor = (!empty($item['text_color_override']) && $item['text_color_override'] !== '#000000') ? $item['text_color_override'] : '#0f172a';

                    $body .= "<tr>";
                    $body .= "<td style='padding: 10px 4px; border-bottom: 1px solid #f1f5f9; font-weight: bold; width: 25%; text-transform: uppercase; font-size: 11px; color: #64748b; vertical-align: top;'>{$label}</td>";
                    $body .= "<td style='padding: 10px 4px; border-bottom: 1px solid #f1f5f9; width: 25%; color: #475569; font-size: 13px; vertical-align: top;'>{$middle}</td>";
                    $body .= "<td style='padding: 10px 4px; border-bottom: 1px solid #f1f5f9; font-weight: bold; width: 50%; color: {$textColor}; vertical-align: top;'>{$main}</td>";
                    $body .= "</tr>";
                }
                $body .= "</table>";
            }

            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $domain = $_SERVER['HTTP_HOST'] ?? 'qr.corp.lbcgj.com';
            $printUrl = $protocol . "://" . $domain . "/print.php?service_id=" . $svcId;

            $body .= "<div style='text-align: center; margin: 30px 0;'>";
            $body .= "<a href='{$printUrl}' style='display: inline-block; background-color: #4f46e5; color: #ffffff; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; letter-spacing: 0.5px;'>View Live Cue Card</a>";
            $body .= "</div>";

            $body .= "<p style='margin-top: 40px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 15px;'>This email was sent by <strong>{$authUser}</strong> via Cue. Reply to this email to contact them directly.</p>";
            $body .= "</div></body></html>";

            // Native SMTP Socket Implementation (Bypasses local mail() issues)
            $smtpHost = '127.0.0.1';
            $smtpPort = 25;
            $from = 'noreply@corp.lbcgj.com';

            function smtp_cmd($socket, $cmd) {
                if ($cmd) fwrite($socket, $cmd . "\r\n");
                $res = '';
                while ($str = fgets($socket, 515)) {
                    $res .= $str;
                    if (substr($str, 3, 1) == ' ') break;
                }
                return $res;
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
        header("Location: index.php?service_id=" . $svcId . "&emailed=1"); exit;
    }

    // -- Action Processing --
    if ($action === 'create_service') {
        $type = $_POST['service_type'];
        if ($type === 'Custom' && !empty($_POST['custom_service_type'])) {
            $type = $_POST['custom_service_type'];
        }
        $stmt = $db->prepare("INSERT INTO services (service_date, service_time, service_type) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['service_date'], $_POST['service_time'], $type]);
        $newSvcId = $db->lastInsertId();

        if (!empty($_POST['template_id'])) {
            $tItems = $db->prepare("SELECT * FROM template_items WHERE template_id = ? ORDER BY sort_order ASC");
            $tItems->execute([$_POST['template_id']]);
            foreach($tItems->fetchAll() as $ti) {
                $textColor = $ti['text_color_override'] ?? '#000000';
                $db->prepare("INSERT INTO service_items (service_id, item_type, label, sort_order, text_color_override) VALUES (?, ?, ?, ?, ?)")
                   ->execute([$newSvcId, $ti['item_type'], $ti['label'], $ti['sort_order'], $textColor]);
            }
        }
        header("Location: index.php?service_id=" . $newSvcId); exit;
    }

    if ($action === 'save_template') {
        $stmt = $db->prepare("INSERT INTO templates (name) VALUES (?)");
        $stmt->execute([$_POST['template_name']]);
        $newTemplateId = $db->lastInsertId();

        $items = $db->prepare("SELECT item_type, label, sort_order, text_color_override FROM service_items WHERE service_id = ?");
        $items->execute([$_POST['service_id']]);
        foreach($items->fetchAll() as $item) {
            $textColor = $item['text_color_override'] ?? '#000000';
            $db->prepare("INSERT INTO template_items (template_id, item_type, label, sort_order, text_color_override) VALUES (?, ?, ?, ?, ?)")
               ->execute([$newTemplateId, $item['item_type'], $item['label'], $item['sort_order'], $textColor]);
        }
        header("Location: index.php?service_id=" . $_POST['service_id']); exit;
    }

    if ($action === 'delete_template') {
        $db->prepare("DELETE FROM templates WHERE id = ?")->execute([$_POST['template_id']]);
        $db->prepare("DELETE FROM template_items WHERE template_id = ?")->execute([$_POST['template_id']]);
        header("Location: index.php"); exit;
    }

    if ($action === 'delete_service') {
        $db->prepare("DELETE FROM services WHERE id = ?")->execute([$_POST['service_id']]);
        header("Location: index.php"); exit;
    }

    if ($action === 'log_hymn_dates') {
        $svcId = $_POST['service_id'];
        $stmt = $db->prepare("SELECT service_date FROM services WHERE id = ?");
        $stmt->execute([$svcId]);
        $serviceDate = $stmt->fetchColumn();

        if ($serviceDate) {
            $stmt = $db->prepare("SELECT hymn_id FROM service_items WHERE service_id = ? AND hymn_id IS NOT NULL AND hymn_id != '' AND item_type != 'Prelude'");
            $stmt->execute([$svcId]);
            $directHymns = array_unique($stmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($directHymns)) {
                $in = str_repeat('?,', count($directHymns) - 1) . '?';
                $db->prepare("UPDATE hymns SET Date_of_Most_Recent_Use = ? WHERE ID IN ($in)")->execute(array_merge([$serviceDate], $directHymns));
            }

            $stmt = $db->prepare("SELECT prelude_set_id FROM service_items WHERE service_id = ? AND prelude_set_id IS NOT NULL AND prelude_set_id != ''");
            $stmt->execute([$svcId]);
            $preludeSetsIds = array_unique($stmt->fetchAll(PDO::FETCH_COLUMN));

            if (!empty($preludeSetsIds)) {
                $in = str_repeat('?,', count($preludeSetsIds) - 1) . '?';
                $db->prepare("UPDATE prelude_sets SET Date_of_Most_Recent_Use = ? WHERE id IN ($in)")->execute(array_merge([$serviceDate], $preludeSetsIds));
            }
        }
        header("Location: index.php?service_id=" . $svcId . "&logged=true"); exit;
    }

    if ($action === 'add_item' && !empty($_POST['service_id'])) {
        $stmt = $db->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM service_items WHERE service_id = ?");
        $stmt->execute([$_POST['service_id']]);

        $db->prepare("INSERT INTO service_items (service_id, item_type, sort_order, label, hymn_id, group_id, main_text) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([
               $_POST['service_id'],
               $_POST['type'],
               $stmt->fetchColumn(),
               $_POST['label'] ?? null,
               empty($_POST['hymn_id']) ? null : $_POST['hymn_id'],
               empty($_POST['group_id']) ? null : $_POST['group_id'],
               $_POST['main_text'] ?? null
           ]);
    }

    if ($action === 'add_item_at_index' && !empty($_POST['service_id'])) {
        $svcId = $_POST['service_id'];
        $index = (int)$_POST['index'];
        $type = $_POST['type'];

        $stmt = $db->prepare("SELECT id FROM service_items WHERE service_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$svcId]);
        $currentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        array_splice($currentIds, $index, 0, 'NEW');

        foreach($currentIds as $i => $id) {
            if ($id === 'NEW') {
                $db->prepare("INSERT INTO service_items (service_id, item_type, sort_order, label, hymn_id, group_id, main_text) VALUES (?, ?, ?, ?, ?, ?, ?)")
                   ->execute([
                       $svcId,
                       $type,
                       $i,
                       $_POST['label'] ?? null,
                       empty($_POST['hymn_id']) ? null : $_POST['hymn_id'],
                       empty($_POST['group_id']) ? null : $_POST['group_id'],
                       $_POST['main_text'] ?? null
                   ]);
            } else {
                $db->prepare("UPDATE service_items SET sort_order = ? WHERE id = ?")->execute([$i, $id]);
            }
        }
    }

    if ($action === 'update_item_ajax') {
        $stmt = $db->prepare("UPDATE service_items SET label=?, supplemental_info=?, main_text=?, hymn_id=?, group_id=?, prelude_set_id=?, text_color_override=? WHERE id=?");
        $stmt->execute([
            $_POST['label'] ?? null,
            $_POST['supplemental_info'] ?? null,
            $_POST['main_text'] ?? null,
            empty($_POST['hymn_id']) ? null : $_POST['hymn_id'],
            empty($_POST['group_id']) ? null : $_POST['group_id'],
            empty($_POST['prelude_set_id']) ? null : $_POST['prelude_set_id'],
            $_POST['text_color_override'] ?? '#000000',
            $_POST['item_id']
        ]);
        echo json_encode(['status' => 'saved']); exit;
    }

    if ($action === 'update_order') {
        foreach ($_POST['ids'] as $index => $id) {
            $db->prepare("UPDATE service_items SET sort_order = ? WHERE id = ?")->execute([$index, $id]);
        }
        echo json_encode(['status' => 'saved']); exit;
    }

    if ($action === 'delete_item') {
        $db->prepare("DELETE FROM service_items WHERE id = ?")->execute([$_POST['item_id']]);
    }
}

// --- DATA FETCHING ---
$activeServices = $db->query("SELECT * FROM services WHERE service_date >= date('now', '-30 days') ORDER BY service_date DESC, service_time DESC")->fetchAll(PDO::FETCH_ASSOC);
$archivedServices = $db->query("SELECT * FROM services WHERE service_date < date('now', '-30 days') ORDER BY service_date DESC, service_time DESC")->fetchAll(PDO::FETCH_ASSOC);
$groups = $db->query("SELECT id, name FROM groups ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$templates = $db->query("SELECT * FROM templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$emailLists = [];
try { $emailLists = $db->query("SELECT id, name FROM email_lists ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
$preludeSets = [];
try { $preludeSets = $db->query("SELECT id, name FROM prelude_sets ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

$items = [];
$existingGroups = [];
$existingTexts = [];
$existingHymns = [];
if ($currentServiceId && $svc) {
    $stmt = $db->prepare("SELECT * FROM service_items WHERE service_id = ? ORDER BY sort_order ASC");
    $stmt->execute([$currentServiceId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $i) {
        if ($i['group_id']) $existingGroups[] = $i['group_id'];
        if ($i['main_text']) $existingTexts[] = $i['main_text'];
        if ($i['hymn_id']) $existingHymns[] = $i['hymn_id'];
    }
}

$suggestions = [];
if ($svc && $canEditCueCards) {
    $dateStr = $svc['service_date'];
    $type = $svc['service_type'];

    // 1. Scheduled Special Music
    $typeSql = $type === 'Custom' ? "" : " AND service_type = ?";
    $params = $type === 'Custom' ? [$dateStr] : [$dateStr, $type];

    $specialsQ = $db->prepare("SELECT * FROM scheduled_specials WHERE service_date = ? $typeSql");
    $specialsQ->execute($params);
    foreach($specialsQ->fetchAll(PDO::FETCH_ASSOC) as $sp) {
        if ($sp['group_id'] && in_array($sp['group_id'], $existingGroups)) continue;
        if (!$sp['group_id'] && $sp['main_text'] && in_array($sp['main_text'], $existingTexts)) continue;

        $display = "Special Music";
        if ($sp['group_id']) {
            $gName = $db->prepare("SELECT name FROM groups WHERE id = ?"); $gName->execute([$sp['group_id']]);
            $display = $gName->fetchColumn();
        } elseif ($sp['main_text']) {
            $display = $sp['main_text'];
        }

        $suggestions[] = [
            'type' => 'Special Music',
            'group_id' => $sp['group_id'],
            'main_text' => $sp['main_text'],
            'label' => '',
            'display' => $display,
            'color' => 'emerald'
        ];
    }

    // 2. Choir Opener
    $openerKey = "opener_{$dateStr}_{$type}";
    $openerQ = $db->prepare("SELECT text_value FROM choir_schedule WHERE id_key = ?");
    $openerQ->execute([$openerKey]);
    $opener = $openerQ->fetchColumn();
    if ($opener && !in_array($opener, $existingTexts)) {
        $suggestions[] = [
            'type' => 'Choir Special',
            'label' => 'Opener',
            'main_text' => $opener,
            'display' => $opener,
            'color' => 'red'
        ];
    }

    // 3. Choir Special
    $specialKey = "special_{$dateStr}_{$type}";
    $specialQ = $db->prepare("SELECT text_value FROM choir_schedule WHERE id_key = ?");
    $specialQ->execute([$specialKey]);
    $specialText = $specialQ->fetchColumn();
    if ($specialText && !in_array($specialText, $existingTexts)) {
        $suggestions[] = [
            'type' => 'Choir Special',
            'label' => '',
            'main_text' => $specialText,
            'display' => $specialText,
            'color' => 'purple'
        ];
    }

    // 4. Chorus of the Month
    $monthKey = date('Y-m', strtotime($dateStr));
    $chorusQ = $db->prepare("SELECT * FROM monthly_settings WHERE month_year = ?");
    $chorusQ->execute([$monthKey]);
    if ($c = $chorusQ->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($c['chorus_hymn_id']) && !in_array($c['chorus_hymn_id'], $existingHymns)) {
            $hymnName = $db->prepare("SELECT Name FROM hymns WHERE ID = ?"); $hymnName->execute([$c['chorus_hymn_id']]);
            $suggestions[] = [
                'type' => 'Hymn',
                'label' => 'Chorus',
                'hymn_id' => $c['chorus_hymn_id'],
                'display' => $hymnName->fetchColumn(),
                'color' => 'amber'
            ];
        }
    }
}

$library = [
    'Music' => ['Hymn', 'Prelude', 'Choir Special', 'Special Music'],
    'Speaking' => ['Welcome', 'Prayer', 'Message', 'Announcements', 'Introduction'],
    'Other' => ['Baptism', 'Birthdays', 'Dismissal', 'Custom', 'Section Break']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cue - Service Builder</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        .handle, .library-item, .suggestion-item { cursor: <?= $canEditCueCards ? 'grab' : 'default' ?>; }
        .ghost { opacity: 0.3; background: #e0e7ff; border: 2px dashed #818cf8; border-radius: 0.75rem; }
        input:-webkit-autofill { -webkit-box-shadow: 0 0 0px 1000px transparent inset; }
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: none; border-radius: 3px; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col font-sans">

    <div class="fixed bottom-6 right-6 bg-emerald-600 text-white px-6 py-3 rounded-lg shadow-2xl font-bold transition-opacity duration-300 opacity-0 pointer-events-none z-50 flex items-center gap-2" id="save-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
        All changes saved
    </div>

    <?php if (isset($_GET['emailed'])): ?>
    <div class="fixed bottom-6 right-6 bg-indigo-600 text-white px-6 py-3 rounded-lg shadow-2xl font-bold transition-opacity duration-300 z-50 flex items-center gap-2" id="email-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
        Email Sent Successfully!
    </div>
    <script>setTimeout(() => { document.getElementById('email-toast').style.opacity = '0'; }, 3000);</script>
    <?php endif; ?>

    <?php if ($canAccessEmails && $currentServiceId && $svc): ?>
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
                <input type="hidden" name="service_id" value="<?= $currentServiceId ?>">

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

    <header class="bg-slate-900 text-white p-4 shadow-md sticky top-0 z-50">
        <div class="max-w-[1800px] mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-black text-indigo-400 tracking-tighter">CUE</h1>

            <div class="flex items-center">
                <nav class="space-x-8 text-sm font-bold uppercase tracking-widest text-slate-400 flex items-center">
                    <a href="index.php" class="text-white border-b-2 border-indigo-500 pb-1">Builder</a>
                    <?php if($canAccessSchedule): ?><a href="schedule.php" class="hover:text-white">Schedule</a><?php endif; ?>
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

    <div class="flex-1 grid grid-cols-12 gap-0 max-w-[1800px] mx-auto w-full">

        <aside class="col-span-2 border-r border-slate-200 p-4 bg-white overflow-y-auto min-h-screen flex flex-col">

            <?php if ($canEditCueCards): ?>
                <h2 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">New Service</h2>
                <form method="POST" class="space-y-2 mb-8 bg-slate-50 p-3 rounded-lg border border-slate-200">
                    <input type="hidden" name="action" value="create_service">

                    <?php if (!empty($templates)): ?>
                    <div class="flex items-center gap-1">
                        <select name="template_id" id="template_select" class="w-full text-xs p-2 border rounded border-slate-300 font-bold text-indigo-700 bg-indigo-50">
                            <option value="">-- Blank Service --</option>
                            <?php foreach($templates as $t): ?>
                                <option value="<?= $t['id'] ?>">From Template: <?= htmlspecialchars($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($canSaveTemplates): ?>
                            <button type="button" onclick="deleteTemplate()" class="text-slate-400 hover:text-red-500 px-2 font-bold transition text-lg" title="Delete selected template">&times;</button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <input type="date" name="service_date" required class="w-full text-xs p-2 border rounded border-slate-300 mt-2">
                    <input type="time" name="service_time" class="w-full text-xs p-2 border rounded border-slate-300">

                    <select name="service_type" class="w-full text-xs p-2 border rounded border-slate-300" onchange="document.getElementById('customTypeContainer').style.display = this.value === 'Custom' ? 'block' : 'none'">
                        <option value="Sunday AM">Sunday AM</option>
                        <option value="Sunday PM">Sunday PM</option>
                        <option value="Wednesday PM">Wednesday PM</option>
                        <option value="Custom">Custom</option>
                    </select>
                    <div id="customTypeContainer" style="display: none;">
                        <input type="text" name="custom_service_type" placeholder="e.g., Missions Conference PM" class="w-full text-xs p-2 border rounded border-slate-300">
                    </div>

                    <button type="submit" class="w-full bg-slate-800 text-white py-2 rounded text-xs font-bold hover:bg-black transition">Create Service</button>
                </form>
            <?php else: ?>
                <div class="mb-8 p-3 bg-slate-50 border border-slate-200 rounded-lg text-center">
                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400">Read Only Mode</span>
                </div>
            <?php endif; ?>

            <?php if ($canEditCueCards && $svc && !empty($suggestions)): ?>
                <h2 class="text-xs font-black text-amber-500 uppercase mb-4 tracking-widest flex items-center gap-1">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M11 3a1 1 0 10-2 0v1a1 1 0 102 0V3zM15.657 5.757a1 1 0 00-1.414-1.414l-.707.707a1 1 0 001.414 1.414l.707-.707zM18 10a1 1 0 01-1 1h-1a1 1 0 110-2h1a1 1 0 011 1zM5.05 6.464A1 1 0 106.464 5.05l-.707-.707a1 1 0 00-1.414 1.414l.707.707zM5 10a1 1 0 01-1 1H3a1 1 0 110-2h1a1 1 0 011 1zM8 16v-1h4v1a2 2 0 11-4 0zM12 14c.015-.34.208-.646.477-.859a4 4 0 10-4.954 0c.27.213.462.519.476.859h4.002z" /></svg>
                    Suggested
                </h2>
                <div id="suggestion-items" class="space-y-1.5 mb-8">
                    <?php foreach($suggestions as $sug): ?>
                        <div data-type="<?= htmlspecialchars($sug['type']) ?>"
                             data-label="<?= htmlspecialchars($sug['label']) ?>"
                             data-hymn_id="<?= htmlspecialchars($sug['hymn_id'] ?? '') ?>"
                             data-group_id="<?= htmlspecialchars($sug['group_id'] ?? '') ?>"
                             data-main_text="<?= htmlspecialchars($sug['main_text'] ?? '') ?>"
                             onclick="clickToAddItem(this)"
                             class="suggestion-item w-full text-left bg-<?= $sug['color'] ?>-50 hover:bg-<?= $sug['color'] ?>-100 border border-<?= $sug['color'] ?>-200 p-2.5 rounded-lg text-xs transition group flex justify-between items-center cursor-pointer">

                            <div class="overflow-hidden flex-1">
                                <div class="font-black uppercase text-[9px] text-<?= $sug['color'] ?>-500 mb-0.5"><?= htmlspecialchars($sug['label'] ?: $sug['type']) ?></div>
                                <div class="font-bold text-slate-700 truncate"><?= htmlspecialchars($sug['display']) ?></div>
                            </div>
                            <span class="text-<?= $sug['color'] ?>-300 group-hover:text-<?= $sug['color'] ?>-500 text-[10px] font-black shrink-0">+</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h2 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">Active Services</h2>
            <div class="space-y-1 mb-8">
                <?php foreach($activeServices as $s): ?>
                    <a href="?service_id=<?= $s['id'] ?>" class="block p-3 rounded-lg text-sm <?= $s['id'] == $currentServiceId ? 'bg-indigo-600 text-white shadow-md font-bold' : 'hover:bg-slate-100 text-slate-600' ?>">
                        <div class="opacity-70 text-[10px] uppercase"><?= htmlspecialchars($s['service_type']) ?></div>
                        <?= date('M j, Y', strtotime($s['service_date'])) ?> <?= htmlspecialchars($s['service_time'] ?? '') ?>
                    </a>
                <?php endforeach; ?>
                <?php if(empty($activeServices)): ?><div class="text-xs text-slate-400 italic">No recent services.</div><?php endif; ?>
            </div>

            <?php if(!empty($archivedServices)): ?>
            <details class="group mt-auto">
                <summary class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest cursor-pointer hover:text-slate-600">Archived Services (<?= count($archivedServices) ?>)</summary>
                <div class="space-y-1 mt-2">
                    <?php foreach($archivedServices as $s): ?>
                        <a href="?service_id=<?= $s['id'] ?>" class="block p-2 rounded-lg text-xs <?= $s['id'] == $currentServiceId ? 'bg-slate-800 text-white font-bold' : 'hover:bg-slate-100 text-slate-500' ?>">
                            <?= date('M j, Y', strtotime($s['service_date'])) ?> - <?= htmlspecialchars($s['service_type']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </aside>

        <main class="<?= ($currentServiceId && $canEditCueCards) ? 'col-span-8' : 'col-span-10' ?> p-8 bg-slate-50 min-h-screen">
            <?php if ($currentServiceId && $svc): ?>
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h2 class="text-4xl font-black text-slate-900 leading-none mb-2"><?= htmlspecialchars($svc['service_type']) ?></h2>
                        <p class="text-slate-500 font-bold uppercase tracking-tighter"><?= date('l, F j, Y', strtotime($svc['service_date'])) ?> <?= $svc['service_time'] ? 'at ' . htmlspecialchars($svc['service_time']) : '' ?></p>
                    </div>
                    <div class="flex items-center gap-3">

                        <?php if ($canSaveTemplates): ?>
                            <form method="POST" class="flex items-center" onsubmit="let n = prompt('Enter a name for this template:'); if(n){ this.template_name.value = n; return true; } return false;">
                                <input type="hidden" name="action" value="save_template">
                                <input type="hidden" name="service_id" value="<?= $currentServiceId ?>">
                                <input type="hidden" name="template_name" value="">
                                <button type="submit" class="bg-white border-2 border-slate-200 text-slate-500 hover:bg-slate-50 hover:text-indigo-600 px-4 py-2.5 rounded-full font-bold transition text-xs flex items-center justify-center shadow-sm" title="Save layout as template">
                                    Save Template
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canEditCueCards): ?>
                            <form method="POST" onsubmit="return confirm('Log all hymns in this service as used on <?= date('M j, Y', strtotime($svc['service_date'])) ?>?');">
                                <input type="hidden" name="action" value="log_hymn_dates">
                                <input type="hidden" name="service_id" value="<?= $currentServiceId ?>">
                                <button type="submit" class="bg-white border-2 border-emerald-200 text-emerald-600 hover:bg-emerald-50 hover:border-emerald-500 px-4 py-2.5 rounded-full font-bold transition text-xs flex items-center justify-center shadow-sm" title="Log Hymn Usage">
                                    Log Hymns
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canAccessEmails): ?>
                            <button type="button" onclick="openEmailModal()" class="bg-indigo-100 border border-indigo-200 text-indigo-700 px-4 py-2.5 rounded-full font-bold transition text-xs flex items-center justify-center shadow-sm hover:bg-indigo-200" title="Send via Email">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 mr-1" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                                Email
                            </button>
                        <?php endif; ?>

                        <a href="print.php?service_id=<?= $currentServiceId ?>" target="_blank" class="bg-indigo-600 text-white px-6 py-2.5 rounded-full font-black text-sm shadow-lg hover:bg-indigo-700 transition tracking-wide ml-1">PRINT CUE CARDS</a>

                        <?php if ($canEditCueCards): ?>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to permanently delete this service?');">
                                <input type="hidden" name="action" value="delete_service">
                                <input type="hidden" name="service_id" value="<?= $currentServiceId ?>">
                                <button type="submit" class="bg-white border-2 border-red-200 text-red-500 hover:bg-red-50 hover:border-red-500 px-4 py-2.5 rounded-full font-bold transition flex items-center justify-center shadow-sm" title="Delete Service">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" /></svg>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="builder-items" class="space-y-3 min-h-[150px] pb-24">
                    <?php if(empty($items)): ?>
                        <div class="p-12 text-center text-slate-400 border-2 border-dashed border-slate-300 rounded-xl font-bold uppercase tracking-widest pointer-events-none">
                            <?= $canEditCueCards ? 'No items yet. Drag something from the library.' : 'This service is currently empty.' ?>
                        </div>
                    <?php endif; ?>

                    <?php
                    $lockAttr = $canEditCueCards ? '' : 'disabled readonly';

                    foreach($items as $item):
                        $type = $item['item_type'];
                        $isPersonType = in_array($type, ['Welcome', 'Prayer', 'Message', 'Announcements', 'Introduction']);
                    ?>
                        <div data-id="<?= $item['id'] ?>" class="bg-white border border-slate-200 p-4 rounded-xl shadow-sm flex items-center gap-4 group">

                            <?php if ($canEditCueCards): ?>
                                <div class="handle text-slate-300 hover:text-slate-500">⠿</div>
                            <?php endif; ?>

                            <div class="flex-1">
                                <form class="item-form grid grid-cols-12 gap-3 items-center" onsubmit="event.preventDefault();">
                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                    <input type="hidden" name="service_id" value="<?= $currentServiceId ?>">

                                    <div class="col-span-2 bg-slate-50 border border-slate-200 rounded shadow-inner focus-within:border-indigo-400 focus-within:ring-1 focus-within:ring-indigo-400 transition-all overflow-hidden">
                                        <div class="text-[8px] font-black text-indigo-500 uppercase tracking-widest px-2 pt-1.5 opacity-80"><?= $type ?></div>
                                        <input type="text" name="label" <?= $lockAttr ?> value="<?= htmlspecialchars($item['label'] ?? '') ?>" placeholder="Label Override" class="w-full text-xs px-2 pb-1.5 pt-0.5 bg-transparent border-none focus:outline-none focus:ring-0 text-slate-800 placeholder-slate-300">
                                    </div>

                                    <?php if($type === 'Hymn'):
                                        $verses = $item['supplemental_info'];
                                        $oos = $item['main_text'];
                                        if ($item['hymn_id']) {
                                            $h = $db->prepare("SELECT Name, OOS, Verses_to_Sing FROM hymns WHERE ID = ?");
                                            $h->execute([$item['hymn_id']]);
                                            $hData = $h->fetch(PDO::FETCH_ASSOC);
                                            if ($hData) {
                                                if (empty($verses) && $verses !== '0') $verses = $hData['Verses_to_Sing'] ?? '';
                                                if (empty($oos)) $oos = $hData['OOS'] ?? '';
                                            }
                                        }
                                    ?>
                                        <div class="col-span-7 relative">
                                            <input type="text" name="main_text" <?= $lockAttr ?> value="<?= htmlspecialchars($oos ?? '') ?>" placeholder="Search Hymn or Edit OOS Text..." onkeyup="searchHymns(this)" class="w-full text-sm p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 disabled:bg-slate-50">
                                            <input type="hidden" name="hymn_id" value="<?= htmlspecialchars($item['hymn_id'] ?? '') ?>">
                                            <div class="hymn-results absolute z-10 bg-white border shadow-xl w-full hidden max-h-60 overflow-y-auto rounded-b-lg"></div>
                                        </div>
                                        <div class="col-span-2">
                                            <input type="text" name="supplemental_info" <?= $lockAttr ?> value="<?= htmlspecialchars($verses ?? '') ?>" placeholder="Verses" class="hymn-verses w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>

                                    <?php elseif($type === 'Special Music'): ?>
                                        <div class="col-span-5">
                                            <input type="text" name="supplemental_info" <?= $lockAttr ?> value="<?= htmlspecialchars($item['supplemental_info'] ?? '') ?>" placeholder="Song Title" class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>
                                        <div class="col-span-4">
                                            <select name="group_id" <?= $lockAttr ?> class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                                <option value="">-- Select Group --</option>
                                                <?php foreach($groups as $g): ?>
                                                    <option value="<?= $g['id'] ?>" <?= ($item['group_id'] ?? '') == $g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                    <?php elseif($type === 'Prelude'): ?>
                                        <div class="col-span-9">
                                            <select name="prelude_set_id" <?= $lockAttr ?> class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                                <option value="">-- Select Prelude Set --</option>
                                                <?php foreach($preludeSets as $s): ?>
                                                    <option value="<?= $s['id'] ?>" <?= ($item['prelude_set_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                    <?php elseif($type === 'Choir Special'): ?>
                                        <div class="col-span-9">
                                            <input type="text" name="main_text" <?= $lockAttr ?> value="<?= htmlspecialchars($item['main_text'] ?? '') ?>" placeholder="Song Title" class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>

                                    <?php elseif($isPersonType): ?>
                                        <div class="col-span-9">
                                            <input type="text" name="main_text" <?= $lockAttr ?> value="<?= htmlspecialchars($item['main_text'] ?? '') ?>" placeholder="Person Speaking/Praying" class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>

                                    <?php elseif($type === 'Dismissal'): ?>
                                        <div class="col-span-9">
                                            <input type="text" name="supplemental_info" <?= $lockAttr ?> value="<?= htmlspecialchars($item['supplemental_info'] ?? '') ?>" placeholder="Target Time (e.g. 12:00 PM)" class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>

                                    <?php elseif(in_array($type, ['Baptism', 'Birthdays', 'Section Break'])): ?>
                                        <div class="col-span-9 flex items-center text-xs text-slate-400 italic">
                                            No additional fields required.
                                        </div>

                                    <?php elseif($type === 'Custom'): ?>
                                        <div class="col-span-4">
                                            <input type="text" name="supplemental_info" <?= $lockAttr ?> value="<?= htmlspecialchars($item['supplemental_info'] ?? '') ?>" placeholder="Middle Column text" class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>
                                        <div class="col-span-5">
                                            <input type="text" name="main_text" <?= $lockAttr ?> value="<?= htmlspecialchars($item['main_text'] ?? '') ?>" placeholder="Right Column text" class="w-full text-sm p-2 border border-slate-300 rounded disabled:bg-slate-50">
                                        </div>
                                    <?php endif; ?>

                                    <div class="col-span-1 flex items-center justify-end gap-1">
                                        <input type="color" name="text_color_override" <?= $lockAttr ?> value="<?= htmlspecialchars($item['text_color_override'] ?? '#000000') ?>" class="w-5 h-5 p-0 border-0 rounded cursor-pointer shrink-0 bg-transparent disabled:opacity-50" title="Override Text Color">
                                        <?php if ($canEditCueCards): ?>
                                            <button type="button" onclick="deleteItem(<?= $item['id'] ?>)" class="text-slate-300 hover:text-red-500 font-bold text-xl px-1.5">&times;</button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="h-full flex flex-col items-center justify-center text-slate-300">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-24 w-24 mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                    <div class="font-black text-3xl uppercase tracking-tighter">Select a service</div>
                    <p class="mt-2 text-sm font-bold">Use the sidebar on the left to begin.</p>
                </div>
            <?php endif; ?>
        </main>

        <?php if ($canEditCueCards): ?>
            <aside class="col-span-2 border-l border-slate-200 p-4 bg-white overflow-y-auto">
                <div id="library-items">
                    <?php foreach($library as $category => $types): ?>
                        <h2 class="text-[10px] font-black text-indigo-400 uppercase mb-3 tracking-widest mt-6 first:mt-0 border-b pb-1"><?= $category ?></h2>
                        <div class="library-category grid grid-cols-1 gap-1.5 mb-4">
                            <?php foreach($types as $t): ?>
                                <div data-type="<?= $t ?>" onclick="clickToAddItem(this)" class="library-item w-full text-left bg-slate-50 hover:bg-indigo-50 border border-slate-200 p-2.5 rounded-lg text-xs font-bold uppercase tracking-tight text-slate-700 hover:text-indigo-700 transition flex justify-between items-center group cursor-pointer">
                                    <?= $t ?>
                                    <span class="text-slate-300 group-hover:text-indigo-400 text-[10px]">+</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </aside>
        <?php endif; ?>

        <?php if ($canEditCueCards): ?>
            <form id="deleteForm" method="POST" class="hidden">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="service_id" value="<?= $currentServiceId ?>">
                <input type="hidden" name="item_id" id="deleteItemId">
            </form>
        <?php endif; ?>

        <?php if ($canSaveTemplates): ?>
            <form id="deleteTemplateForm" method="POST" class="hidden">
                <input type="hidden" name="action" value="delete_template">
                <input type="hidden" name="template_id" id="deleteTemplateId">
            </form>
        <?php endif; ?>
    </div>

    <script>
        <?php if (isset($_GET['logged'])): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const toast = document.getElementById('save-toast');
            toast.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg> Hymn dates updated!';
            toast.classList.replace('bg-emerald-600', 'bg-indigo-600');
            toast.style.opacity = '1';
            setTimeout(() => { toast.style.opacity = '0'; }, 3000);
            window.history.replaceState({}, document.title, "index.php?service_id=<?= $currentServiceId ?>");
        });
        <?php endif; ?>

        function showSaveToast() {
            const toast = document.getElementById('save-toast');
            toast.style.opacity = '1';
            setTimeout(() => toast.style.opacity = '0', 2000);
        }

        <?php if ($canAccessEmails && $currentServiceId): ?>
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

        <?php if ($canEditCueCards): ?>
            let builderSortable = null;
            let saveTimeout = null;

            function bindItemEvents() {
                document.querySelectorAll('.item-form').forEach(form => {
                    form.addEventListener('input', function(e) {
                        clearTimeout(saveTimeout);
                        saveTimeout = setTimeout(() => {
                            let formData = new FormData(this);
                            formData.append('action', 'update_item_ajax');
                            fetch('index.php?service_id=<?= $currentServiceId ?? '' ?>', { method: 'POST', body: formData }).then(showSaveToast);
                        }, 500);
                    });
                    form.addEventListener('change', function(e) {
                        if (e.target.tagName === 'SELECT' || e.target.tagName === 'INPUT' && e.target.type === 'color') {
                            let formData = new FormData(this);
                            formData.append('action', 'update_item_ajax');
                            fetch('index.php?service_id=<?= $currentServiceId ?? '' ?>', { method: 'POST', body: formData }).then(showSaveToast);
                        }
                    });
                });
            }

            async function applyDOMResponse(resp, scrollToBottom = false) {
                const html = await resp.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Update Builder Items
                const newBuilder = doc.getElementById('builder-items');
                if (newBuilder) {
                    const oldBuilder = document.getElementById('builder-items');
                    oldBuilder.parentNode.replaceChild(newBuilder, oldBuilder);
                }

                // Update Suggestions dynamically
                const newSuggestions = doc.getElementById('suggestion-items');
                const oldSuggestions = document.getElementById('suggestion-items');
                if (newSuggestions && oldSuggestions) {
                    oldSuggestions.parentNode.replaceChild(newSuggestions, oldSuggestions);
                } else if (!newSuggestions && oldSuggestions) {
                    oldSuggestions.previousElementSibling.remove(); // Remove Header
                    oldSuggestions.remove();
                } else if (newSuggestions && !oldSuggestions) {
                     window.location.reload();
                     return;
                }

                initBuilder();
                if (scrollToBottom) {
                    setTimeout(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }), 50);
                }
            }

            function createSkeletonLoader() {
                const skeleton = document.createElement('div');
                skeleton.className = 'bg-white border border-slate-200 p-4 rounded-xl shadow-sm flex items-center gap-4 animate-pulse h-[72px] mb-3';
                skeleton.innerHTML = '<div class="text-slate-200">⠿</div><div class="flex-1 grid grid-cols-12 gap-3"><div class="col-span-2 bg-slate-100 rounded h-9"></div><div class="col-span-7 bg-slate-50 rounded h-9"></div><div class="col-span-2 bg-slate-50 rounded h-9"></div><div class="col-span-1 bg-slate-100 rounded-full h-6 w-6 ml-auto"></div></div>';
                return skeleton;
            }

            function initBuilder() {
                bindItemEvents();
                const builderEl = document.getElementById('builder-items');
                if (builderEl) {
                    if (builderSortable) builderSortable.destroy();
                    builderSortable = Sortable.create(builderEl, {
                        group: 'builder', handle: '.handle', animation: 150, ghostClass: 'ghost',
                        onAdd: async function (evt) {
                            const itemType = evt.item.dataset.type;
                            const label = evt.item.dataset.label || '';
                            const hymnId = evt.item.dataset.hymn_id || '';
                            const groupId = evt.item.dataset.group_id || '';
                            const mainText = evt.item.dataset.main_text || '';
                            const newIndex = evt.newIndex;

                            evt.item.parentNode.removeChild(evt.item);

                            const skeleton = createSkeletonLoader();
                            const container = document.getElementById('builder-items');
                            if (newIndex >= container.children.length) container.appendChild(skeleton);
                            else container.insertBefore(skeleton, container.children[newIndex]);

                            let formData = new FormData();
                            formData.append('action', 'add_item_at_index');
                            formData.append('service_id', '<?= $currentServiceId ?>');
                            formData.append('type', itemType);
                            formData.append('index', newIndex);
                            if (label) formData.append('label', label);
                            if (hymnId) formData.append('hymn_id', hymnId);
                            if (groupId) formData.append('group_id', groupId);
                            if (mainText) formData.append('main_text', mainText);

                            const resp = await fetch('index.php?service_id=<?= $currentServiceId ?? '' ?>', { method: 'POST', body: formData });
                            await applyDOMResponse(resp, false);
                        },
                        onEnd: function(evt) {
                            if (evt.from !== evt.to) return;
                            let formData = new FormData();
                            formData.append('action', 'update_order');
                            document.querySelectorAll('#builder-items > div.group').forEach(div => {
                                if(div.dataset.id) formData.append('ids[]', div.dataset.id);
                            });
                            fetch('index.php?service_id=<?= $currentServiceId ?? '' ?>', { method: 'POST', body: formData }).then(showSaveToast);
                        }
                    });
                }

                // Re-init Sortable on suggestions
                const sugEl = document.getElementById('suggestion-items');
                if (sugEl) {
                    Sortable.create(sugEl, { group: { name: 'builder', pull: 'clone', put: false }, sort: false, animation: 150 });
                }
            }

            document.addEventListener('DOMContentLoaded', initBuilder);

            async function clickToAddItem(el) {
                const container = document.getElementById('builder-items');
                if (container) {
                    container.appendChild(createSkeletonLoader());
                    setTimeout(() => window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' }), 50);
                }

                let formData = new FormData();
                formData.append('action', 'add_item');
                formData.append('service_id', '<?= $currentServiceId ?>');
                formData.append('type', el.dataset.type);
                if (el.dataset.label) formData.append('label', el.dataset.label);
                if (el.dataset.hymn_id) formData.append('hymn_id', el.dataset.hymn_id);
                if (el.dataset.group_id) formData.append('group_id', el.dataset.group_id);
                if (el.dataset.main_text) formData.append('main_text', el.dataset.main_text);

                const resp = await fetch('index.php?service_id=<?= $currentServiceId ?? '' ?>', { method: 'POST', body: formData });
                await applyDOMResponse(resp, true);
            }

            async function deleteItem(id) {
                let formData = new FormData();
                formData.append('action', 'delete_item');
                formData.append('service_id', '<?= $currentServiceId ?>');
                formData.append('item_id', id);
                const div = document.querySelector(`div[data-id="${id}"]`);
                if (div) div.style.opacity = '0.3';
                const resp = await fetch('index.php?service_id=<?= $currentServiceId ?? '' ?>', { method: 'POST', body: formData });
                await applyDOMResponse(resp, false);
            }

            function deleteTemplate() {
                const select = document.getElementById('template_select');
                if (select.value) {
                    if (confirm('Are you sure you want to delete this template?')) {
                        const f = document.createElement('form');
                        f.method = 'POST'; f.action = 'index.php';
                        const a = document.createElement('input'); a.name = 'action'; a.value = 'delete_template';
                        const t = document.createElement('input'); t.name = 'template_id'; t.value = select.value;
                        f.appendChild(a); f.appendChild(t); document.body.appendChild(f);
                        f.submit();
                    }
                }
            }

            async function searchHymns(input) {
                const query = input.value;
                const container = input.parentElement.querySelector('.hymn-results');
                if (query.length < 1) { container.classList.add('hidden'); return; }
                const resp = await fetch(`hymn_search.php?q=${query}`);
                const results = await resp.json();
                container.innerHTML = '';
                container.classList.remove('hidden');
                results.forEach(h => {
                    const div = document.createElement('div');
                    div.className = 'p-3 hover:bg-indigo-50 border-b flex justify-between items-center group transition';
                    let dateHtml = '<span class="text-[9px] uppercase tracking-wider text-slate-400 font-bold">Never Used</span>';
                    if (h.Date_of_Most_Recent_Use) {
                        const daysAgo = (new Date() - new Date(h.Date_of_Most_Recent_Use)) / (1000 * 60 * 60 * 24);
                        let c = 'text-slate-400';
                        if (daysAgo <= 30) c = 'text-red-500 font-black';
                        else if (daysAgo <= 60) c = 'text-orange-500 font-bold';
                        else if (daysAgo <= 90) c = 'text-yellow-500 font-bold';
                        dateHtml = `<span class="${c} text-[9px] uppercase tracking-wider">Used: ${h.Date_of_Most_Recent_Use}</span>`;
                    }
                    const pdfBtn = h.pdf_url ? `<a href="${h.pdf_url}" target="_blank" onclick="event.stopPropagation()" class="px-2 py-1.5 bg-white hover:bg-indigo-500 hover:text-white rounded text-[9px] font-black tracking-wider text-indigo-500 transition shadow-sm border border-slate-200 uppercase whitespace-nowrap">View PDF</a>` : '';
                    div.innerHTML = `
                        <div class="flex-1 cursor-pointer pr-2">
                            <strong class="text-sm text-slate-800">${h.Name}</strong> <br>
                            <span class="text-[10px] text-slate-500">NVB:${h.NVB || '-'} | SSH:${h.SSH || '-'} | NVR:${h.NVR || '-'} | MAJ:${h.MAJ || '-'}</span><br>
                            ${dateHtml}
                        </div>
                        <div>${pdfBtn}</div>
                    `;
                    div.querySelector('.cursor-pointer').onclick = () => {
                        const formRow = input.closest('form');
                        formRow.querySelector('input[name="hymn_id"]').value = h.ID;
                        const versesInput = formRow.querySelector('.hymn-verses');
                        if (versesInput) versesInput.value = h.Verses_to_Sing || '';
                        input.value = h.OOS || '';
                        container.classList.add('hidden');
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    };
                    container.appendChild(div);
                });
            }

            document.querySelectorAll('.library-category').forEach(el => {
                Sortable.create(el, { group: { name: 'builder', pull: 'clone', put: false }, sort: false, animation: 150 });
            });
        <?php endif; ?>
    </script>
</body>
</html>