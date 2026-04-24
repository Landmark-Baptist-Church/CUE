<?php
require 'db.php';

// --- BULLETPROOF SCHEMA SETUP ---
$db->exec("CREATE TABLE IF NOT EXISTS people (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT, last_name TEXT, is_pianist INTEGER DEFAULT 0)");
try { $db->exec("ALTER TABLE people ADD COLUMN first_name TEXT"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE people ADD COLUMN last_name TEXT"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE people ADD COLUMN email TEXT"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE people ADD COLUMN phone TEXT"); } catch (Exception $e) {}

$db->exec("CREATE TABLE IF NOT EXISTS groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
try { $db->exec("ALTER TABLE groups ADD COLUMN name TEXT"); } catch (Exception $e) {}
try { $db->exec("ALTER TABLE groups ADD COLUMN group_type TEXT"); } catch (Exception $e) {}

$db->exec("CREATE TABLE IF NOT EXISTS group_members (group_id INTEGER, person_id INTEGER, PRIMARY KEY(group_id, person_id))");
try { $db->exec("ALTER TABLE group_members ADD COLUMN is_pianist INTEGER DEFAULT 0"); } catch (Exception $e) {}

// New Email List Schema
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


$currentGroupId = $_GET['group_id'] ?? null;
$currentListId = $_GET['list_id'] ?? null;

// --- POST & AJAX ACTIONS (WITH SECURITY GUARDS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    // --- Email Blast Guard ---
    if ($action === 'send_blast_email' && !$canAccessEmails) {
        die('Unauthorized: Missing n-cue-emails');
    }

    // --- Groups Permissions Guard ---
    $groupActions = ['add_person', 'delete_person', 'edit_person_contact', 'add_group', 'delete_group', 'toggle_pianist', 'toggle_membership'];
    if (in_array($action, $groupActions) && !$canEditGroups) {
        if ($isAjax || in_array($action, ['toggle_pianist', 'toggle_membership', 'edit_person_contact'])) {
            die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-groups']));
        } else {
            die('Unauthorized: You do not have permission to modify groups or people.');
        }
    }

    // --- Email Lists Permissions Guard ---
    $listActions = ['add_email_list', 'delete_email_list', 'toggle_list_membership'];
    if (in_array($action, $listActions) && !$canEditEmailLists) {
        if ($isAjax || $action === 'toggle_list_membership') {
            die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-emaillists']));
        } else {
            die('Unauthorized: You do not have permission to modify email lists.');
        }
    }

    // -- EMAIL BLAST SENDER --
    if ($action === 'send_blast_email' && $canAccessEmails) {
        $targetType = $_POST['target_type'];
        $targetId = (int)$_POST['target_id'];
        $subject = trim($_POST['subject'] ?? 'Message from Cue');
        $customMessage = trim($_POST['custom_message'] ?? '');

        // Fetch recipients
        $emails = [];
        if ($targetType === 'group') {
            $stmt = $db->prepare("SELECT p.email FROM people p JOIN group_members gm ON p.id = gm.person_id WHERE gm.group_id = ? AND p.email IS NOT NULL AND p.email != ''");
            $stmt->execute([$targetId]);
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($targetType === 'list') {
            $stmt = $db->prepare("SELECT p.email FROM people p JOIN email_list_members elm ON p.id = elm.person_id WHERE elm.list_id = ? AND p.email IS NOT NULL AND p.email != ''");
            $stmt->execute([$targetId]);
            $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if (!empty($emails)) {
            // Construct HTML Email Wrapper
            $body = "<html><body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #334155; background-color: #f8fafc; padding: 20px;'>";
            $body .= "<div style='max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);'>";
            $body .= "<h2 style='color: #4f46e5; margin-top: 0; margin-bottom: 20px;'>Message from " . htmlspecialchars($authUser) . "</h2>";

            if ($customMessage) {
                $body .= "<div style='font-size: 15px; line-height: 1.6; color: #0f172a; margin-bottom: 30px;'>" . nl2br(htmlspecialchars($customMessage)) . "</div>";
            }

            $body .= "<p style='margin-top: 40px; font-size: 11px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 15px;'>This email was sent via Cue. Reply to this email to contact the sender directly.</p>";
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

                $boundary = md5(time());
                $headers = "From: CUE <$from>\r\n";
                $headers .= "Reply-To: $authUser <$authEmail>\r\n";
                $headers .= "Subject: $subject\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";

                $msg = "--$boundary\r\n";
                $msg .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
                $msg .= $body . "\r\n\r\n";

                // Handle File Attachment
                if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] == UPLOAD_ERR_OK) {
                    $fileName = $_FILES['attachment']['name'];
                    $fileType = $_FILES['attachment']['type'];
                    $fileData = chunk_split(base64_encode(file_get_contents($_FILES['attachment']['tmp_name'])));

                    $msg .= "--$boundary\r\n";
                    $msg .= "Content-Type: $fileType; name=\"$fileName\"\r\n";
                    $msg .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n";
                    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
                    $msg .= $fileData . "\r\n\r\n";
                }

                $msg .= "--$boundary--\r\n";

                smtp_cmd($socket, $headers . $msg . "\r\n.");
                smtp_cmd($socket, "QUIT");
                fclose($socket);
            }
        }
        $redirect = "groups.php?" . ($targetType === 'group' ? "group_id=$targetId" : "list_id=$targetId") . "&emailed=1";
        header("Location: " . $redirect); exit;
    }

    // -- PEOPLE ACTIONS --
    if ($action === 'add_person') {
        $stmt = $db->prepare("INSERT INTO people (first_name, last_name, email, phone) VALUES (?, ?, ?, ?)");
        $stmt->execute([trim($_POST['first_name'] ?? ''), trim($_POST['last_name'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? '')]);
        header("Location: groups.php" . ($currentGroupId ? "?group_id=$currentGroupId" : ($currentListId ? "?list_id=$currentListId" : ""))); exit;
    }

    if ($action === 'delete_person') {
        $db->prepare("DELETE FROM people WHERE id = ?")->execute([$_POST['person_id']]);
        $db->prepare("DELETE FROM group_members WHERE person_id = ?")->execute([$_POST['person_id']]);
        $db->prepare("DELETE FROM email_list_members WHERE person_id = ?")->execute([$_POST['person_id']]);
        header("Location: groups.php" . ($currentGroupId ? "?group_id=$currentGroupId" : ($currentListId ? "?list_id=$currentListId" : ""))); exit;
    }

    if ($action === 'edit_person_contact') {
        $stmt = $db->prepare("UPDATE people SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?");
        $stmt->execute([trim($_POST['first_name'] ?? ''), trim($_POST['last_name'] ?? ''), trim($_POST['email'] ?? ''), trim($_POST['phone'] ?? ''), $_POST['person_id']]);
        echo json_encode(['status' => 'success']); exit;
    }

    // -- GROUP ACTIONS --
    if ($action === 'add_group') {
        $stmt = $db->prepare("INSERT INTO groups (name, group_type) VALUES (?, ?)");
        $stmt->execute([trim($_POST['group_name'] ?? ''), $_POST['group_type'] ?? '']);
        header("Location: groups.php?group_id=" . $db->lastInsertId()); exit;
    }

    if ($action === 'delete_group') {
        $db->prepare("DELETE FROM groups WHERE id = ?")->execute([$_POST['group_id']]);
        $db->prepare("DELETE FROM group_members WHERE group_id = ?")->execute([$_POST['group_id']]);
        header("Location: groups.php"); exit;
    }

    if ($action === 'toggle_pianist') {
        $stmt = $db->prepare("UPDATE group_members SET is_pianist = ? WHERE group_id = ? AND person_id = ?");
        $stmt->execute([$_POST['is_pianist'], $_POST['group_id'], $_POST['person_id']]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'toggle_membership') {
        if (($_POST['is_member'] ?? '0') == '1') {
            $stmt = $db->prepare("INSERT OR IGNORE INTO group_members (group_id, person_id, is_pianist) VALUES (?, ?, 0)");
            $stmt->execute([$_POST['group_id'], $_POST['person_id']]);
        } else {
            $stmt = $db->prepare("DELETE FROM group_members WHERE group_id = ? AND person_id = ?");
            $stmt->execute([$_POST['group_id'], $_POST['person_id']]);
        }
        echo json_encode(['status' => 'success']); exit;
    }

    // -- EMAIL LIST ACTIONS --
    if ($action === 'add_email_list') {
        $stmt = $db->prepare("INSERT INTO email_lists (name) VALUES (?)");
        $stmt->execute([trim($_POST['list_name'] ?? '')]);
        header("Location: groups.php?list_id=" . $db->lastInsertId()); exit;
    }

    if ($action === 'delete_email_list') {
        $db->prepare("DELETE FROM email_lists WHERE id = ?")->execute([$_POST['list_id']]);
        $db->prepare("DELETE FROM email_list_members WHERE list_id = ?")->execute([$_POST['list_id']]);
        header("Location: groups.php"); exit;
    }

    if ($action === 'toggle_list_membership') {
        if (($_POST['is_member'] ?? '0') == '1') {
            $stmt = $db->prepare("INSERT OR IGNORE INTO email_list_members (list_id, person_id) VALUES (?, ?)");
            $stmt->execute([$_POST['list_id'], $_POST['person_id']]);
        } else {
            $stmt = $db->prepare("DELETE FROM email_list_members WHERE list_id = ? AND person_id = ?");
            $stmt->execute([$_POST['list_id'], $_POST['person_id']]);
        }
        echo json_encode(['status' => 'success']); exit;
    }
}

// --- SAFE DATA FETCHING ---
$people = [];
$groups = [];
$emailLists = [];
try { $people = $db->query("SELECT * FROM people ORDER BY last_name ASC, first_name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
try { $groups = $db->query("SELECT * FROM groups ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}
try { $emailLists = $db->query("SELECT * FROM email_lists ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

// -- Active Group Setup --
$activeGroup = null;
$groupMembersData = [];
if ($currentGroupId) {
    try {
        $stmt = $db->prepare("SELECT * FROM groups WHERE id = ?");
        $stmt->execute([$currentGroupId]);
        $activeGroup = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeGroup) {
            $stmt = $db->prepare("SELECT person_id, is_pianist FROM group_members WHERE group_id = ?");
            $stmt->execute([$currentGroupId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $groupMembersData[$row['person_id']] = $row['is_pianist'];
            }
        } else {
            $currentGroupId = null;
        }
    } catch(Exception $e) {
        $activeGroup = null;
        $currentGroupId = null;
    }
}

// -- Active Email List Setup --
$activeList = null;
$listMembersData = [];
if ($currentListId) {
    try {
        $stmt = $db->prepare("SELECT * FROM email_lists WHERE id = ?");
        $stmt->execute([$currentListId]);
        $activeList = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($activeList) {
            $stmt = $db->prepare("SELECT person_id FROM email_list_members WHERE list_id = ?");
            $stmt->execute([$currentListId]);
            $listMembersData = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $currentListId = null;
        }
    } catch(Exception $e) {
        $activeList = null;
        $currentListId = null;
    }
}

// Pre-sort people for Column 3 (Groups)
$groupRosterMembers = [];
$groupRosterNonMembers = [];
foreach ($people as $p) {
    if (isset($groupMembersData[$p['id']])) {
        $p['is_group_pianist'] = $groupMembersData[$p['id']];
        $groupRosterMembers[] = $p;
    } else {
        $groupRosterNonMembers[] = $p;
    }
}
$sortedGroupPeople = array_merge($groupRosterMembers, $groupRosterNonMembers);

// Pre-sort people for Column 3 (Email Lists)
$listRosterMembers = [];
$listRosterNonMembers = [];
foreach ($people as $p) {
    if (in_array($p['id'], $listMembersData)) {
        $listRosterMembers[] = $p;
    } else {
        $listRosterNonMembers[] = $p;
    }
}
$sortedListPeople = array_merge($listRosterMembers, $listRosterNonMembers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cue - Groups & Roster</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        ::-webkit-scrollbar { width: 5px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .custom-checkbox:checked { background-color: #4f46e5; border-color: #4f46e5; }
        .custom-checkbox:disabled { cursor: not-allowed; opacity: 0.5; }
    </style>
</head>
<body class="bg-slate-50 h-screen flex flex-col font-sans overflow-hidden">

    <div class="fixed bottom-6 right-6 bg-emerald-600 text-white px-5 py-2.5 rounded-lg shadow-2xl text-sm font-bold transition-opacity duration-300 opacity-0 pointer-events-none z-50 flex items-center gap-2" id="save-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
        Saved
    </div>

    <?php if (isset($_GET['emailed'])): ?>
    <div class="fixed bottom-6 right-6 bg-indigo-600 text-white px-6 py-3 rounded-lg shadow-2xl font-bold transition-opacity duration-300 z-50 flex items-center gap-2" id="email-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
        Email Sent Successfully!
    </div>
    <script>setTimeout(() => { document.getElementById('email-toast').style.opacity = '0'; }, 3000);</script>
    <?php endif; ?>

    <?php if ($canAccessEmails): ?>
    <div id="email-blast-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden flex flex-col">
            <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-sm flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 text-indigo-500" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                    Compose Email Blast
                </h3>
                <button onclick="closeBlastModal()" class="text-slate-400 hover:text-slate-700 text-xl leading-none">&times;</button>
            </div>

            <form method="POST" enctype="multipart/form-data" class="p-6 flex flex-col gap-4">
                <input type="hidden" name="action" value="send_blast_email">
                <input type="hidden" name="target_type" id="blast-target-type" value="">
                <input type="hidden" name="target_id" id="blast-target-id" value="">

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">To</label>
                    <div id="blast-target-name" class="w-full text-sm p-2.5 bg-slate-100 border border-slate-200 rounded text-indigo-600 font-black cursor-not-allowed">
                        </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Subject <span class="text-red-500">*</span></label>
                    <input type="text" name="subject" required placeholder="Subject Line..." class="w-full text-sm p-2.5 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-white">
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Message <span class="text-red-500">*</span></label>
                    <textarea name="custom_message" rows="5" required placeholder="Type your message here..." class="w-full text-sm p-2.5 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition resize-none"></textarea>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Attachment (Optional)</label>
                    <input type="file" name="attachment" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded file:border-0 file:text-xs file:font-bold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 transition cursor-pointer">
                </div>

                <div class="pt-2 flex justify-end gap-3 border-t border-slate-100 mt-2">
                    <button type="button" onclick="closeBlastModal()" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-800 transition">Cancel</button>
                    <button type="submit" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-black uppercase tracking-widest transition shadow-sm">Send Email</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>


    <div id="edit-person-modal" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-[100] hidden items-center justify-center p-4">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden flex flex-col">
            <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h3 class="font-black text-slate-800 uppercase tracking-widest text-sm">Edit Person</h3>
                <button onclick="closeEditModal()" class="text-slate-400 hover:text-slate-700 text-xl leading-none">&times;</button>
            </div>
            <div class="p-6 flex flex-col gap-4">
                <input type="hidden" id="edit-person-id">

                <div class="flex gap-4">
                    <div class="flex-1">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">First Name <span class="text-red-500">*</span></label>
                        <input type="text" id="edit-person-first" class="w-full text-sm p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition">
                    </div>
                    <div class="flex-1">
                        <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Last Name <span class="text-red-500">*</span></label>
                        <input type="text" id="edit-person-last" class="w-full text-sm p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Email Address</label>
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 absolute left-3 top-2.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                        <input type="email" id="edit-person-email" class="w-full text-sm p-2 pl-9 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition">
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Phone Number</label>
                    <div class="relative">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 absolute left-3 top-2.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" /></svg>
                        <input type="text" id="edit-person-phone" class="w-full text-sm p-2 pl-9 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition">
                    </div>
                </div>
            </div>
            <div class="p-4 border-t border-slate-200 bg-slate-50 flex justify-end gap-3">
                <button onclick="closeEditModal()" class="px-4 py-2 text-xs font-bold text-slate-500 hover:text-slate-800 transition">Cancel</button>
                <button onclick="saveEditPerson()" class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-black uppercase tracking-widest transition shadow-sm">Save Changes</button>
            </div>
        </div>
    </div>

    <header class="bg-slate-900 text-white p-4 shadow-md z-50 shrink-0">
        <div class="max-w-[1800px] mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-black text-indigo-400 tracking-tighter">CUE</h1>

            <div class="flex items-center">
                <nav class="space-x-8 text-sm font-bold uppercase tracking-widest text-slate-400 flex items-center">
                    <a href="index.php" class="hover:text-white">Builder</a>
                    <?php if($canAccessSchedule): ?><a href="schedule.php" class="hover:text-white">Schedule</a><?php endif; ?>
                    <?php if($canAccessHymns): ?><a href="hymns.php" class="hover:text-white">Hymns</a><?php endif; ?>
                    <a href="groups.php" class="text-white border-b-2 border-indigo-500 pb-1">Groups</a>
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

    <div class="flex-1 grid grid-cols-12 gap-6 max-w-[1800px] mx-auto w-full p-6 min-h-0 relative">

        <?php if (!$canEditGroups && !$canEditEmailLists): ?>
            <div class="absolute top-2 right-8 text-xs font-black uppercase tracking-widest text-slate-400 pointer-events-none z-10">Read Only Mode</div>
        <?php endif; ?>

        <div class="col-span-4 flex flex-col bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden h-full">
            <div class="p-4 border-b border-slate-200 bg-slate-50 shrink-0">
                <h2 class="text-xs font-black text-slate-800 uppercase tracking-widest mb-3">Master Roster</h2>

                <?php if ($canEditGroups): ?>
                    <form method="POST" class="flex flex-col gap-2 mb-3 bg-white p-2 rounded border border-slate-200 shadow-sm">
                        <input type="hidden" name="action" value="add_person">
                        <div class="flex gap-2">
                            <input type="text" name="first_name" placeholder="First" required class="w-1/2 text-xs p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-slate-50">
                            <input type="text" name="last_name" placeholder="Last" required class="w-1/2 text-xs p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-slate-50">
                        </div>
                        <div class="flex gap-2">
                            <input type="email" name="email" placeholder="Email (Optional)" class="flex-1 text-xs p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-slate-50">
                            <input type="text" name="phone" placeholder="Phone (Optional)" class="flex-1 text-xs p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-slate-50">
                            <button type="submit" class="bg-indigo-600 text-white px-3 rounded font-bold hover:bg-indigo-700 transition" title="Add Person">+</button>
                        </div>
                    </form>
                <?php endif; ?>

                <input type="text" onkeyup="filterList(this, 'people-list')" placeholder="Search people..." class="w-full text-xs p-2 bg-white border border-slate-200 rounded shadow-inner text-slate-700 focus:outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
            </div>

            <div id="people-list" class="flex-1 overflow-y-auto p-1.5">
                <?php if (empty($people)): ?>
                    <div class="p-8 text-center text-slate-400 text-xs font-bold italic">No people added yet.</div>
                <?php endif; ?>

                <?php foreach($people as $p): ?>
                    <div class="flex items-center justify-between py-1.5 px-3 hover:bg-slate-50 rounded group transition border-b border-slate-100 last:border-0 search-item">
                        <div class="font-bold text-slate-700 text-xs flex-1 flex items-center justify-between pr-3 min-w-0">
                            <div class="truncate search-text">
                                <?= htmlspecialchars(($p['last_name'] ?? '') . ', ' . ($p['first_name'] ?? '')) ?>
                            </div>

                            <div class="flex items-center gap-1.5 shrink-0 ml-2">
                                <?php if (!empty($p['email'])): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-indigo-400" viewBox="0 0 20 20" fill="currentColor" title="<?= htmlspecialchars($p['email']) ?>"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                                <?php endif; ?>
                                <?php if (!empty($p['phone'])): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-emerald-500" viewBox="0 0 20 20" fill="currentColor" title="<?= htmlspecialchars($p['phone']) ?>"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" /></svg>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($canEditGroups): ?>
                            <div class="opacity-0 group-hover:opacity-100 transition flex items-center shrink-0">
                                <button type="button" onclick="editContact(<?= (int)$p['id'] ?>, '<?= htmlspecialchars(addslashes($p['first_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($p['last_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($p['email'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($p['phone'] ?? '')) ?>')" class="text-slate-400 hover:text-indigo-600 font-bold px-2 text-[10px] uppercase tracking-widest" title="Edit Person">Edit</button>
                                <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes((string)($p['first_name'] ?? ''))) ?>? This removes them from all groups and lists.');" class="inline">
                                    <input type="hidden" name="action" value="delete_person">
                                    <input type="hidden" name="person_id" value="<?= (int)$p['id'] ?>">
                                    <button type="submit" class="text-slate-300 hover:text-red-500 font-bold px-2 text-lg leading-none" title="Delete Person">&times;</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-span-4 flex flex-col bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden h-full">
            <div class="flex border-b border-slate-200 bg-slate-50 shrink-0">
                <button onclick="switchTab('groups')" id="tab-groups" class="flex-1 py-3 text-[10px] font-black uppercase tracking-widest <?= $currentListId ? 'text-slate-400 border-b-2 border-transparent hover:bg-slate-100' : 'text-indigo-600 border-b-2 border-indigo-600 bg-white' ?>">Groups & Ensembles</button>
                <button onclick="switchTab('lists')" id="tab-lists" class="flex-1 py-3 text-[10px] font-black uppercase tracking-widest <?= $currentListId ? 'text-indigo-600 border-b-2 border-indigo-600 bg-white' : 'text-slate-400 border-b-2 border-transparent hover:bg-slate-100' ?>">Email Lists</button>
            </div>

            <div id="content-groups" class="flex-1 flex flex-col min-h-0 <?= $currentListId ? 'hidden' : '' ?>">
                <div class="p-4 border-b border-slate-200 bg-white shrink-0">
                    <?php if ($canEditGroups): ?>
                        <form method="POST" class="flex gap-2 mb-3">
                            <input type="hidden" name="action" value="add_group">
                            <input type="text" name="group_name" placeholder="New Group Name..." required class="flex-1 text-xs p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-slate-50">
                            <select name="group_type" class="w-24 text-xs p-2 border border-slate-300 rounded bg-slate-50 text-slate-600 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                                <option selected value="">None</option>
                                <option>Soloist</option>
                                <option>Duet</option>
                                <option>Trio</option>
                                <option>Quartet</option>
                                <option>Ensemble</option>
                                <option>Choir</option>
                            </select>
                            <button type="submit" class="bg-indigo-600 text-white px-3 rounded font-bold hover:bg-indigo-700 transition" title="Add Group">+</button>
                        </form>
                    <?php endif; ?>
                    <input type="text" onkeyup="filterList(this, 'groups-list')" placeholder="Search groups..." class="w-full text-xs p-2 bg-slate-50 border border-slate-200 rounded shadow-inner text-slate-700 focus:outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                </div>

                <div id="groups-list" class="flex-1 overflow-y-auto p-1.5 bg-slate-50/30">
                    <?php if (empty($groups)): ?>
                        <div class="p-8 text-center text-slate-400 text-xs font-bold italic">No groups created yet.</div>
                    <?php endif; ?>

                    <?php foreach($groups as $g): $isActive = ($currentGroupId == $g['id']); ?>
                        <div class="flex items-center justify-between p-1 group/row search-item">
                            <a href="?group_id=<?= (int)$g['id'] ?>" class="flex-1 px-3 py-2 rounded text-xs transition font-bold border border-transparent flex justify-between items-center <?= $isActive ? 'bg-indigo-50 text-indigo-700 border-indigo-200 shadow-sm' : 'hover:bg-slate-50 text-slate-600' ?>">
                                <span class="search-text"><?= htmlspecialchars($g['name'] ?? '') ?></span>
                                <?php if (!empty($g['group_type'])): ?>
                                    <span class="text-[9px] uppercase tracking-wider px-1.5 py-0.5 rounded opacity-70 bg-slate-200 text-slate-600 search-text"><?= htmlspecialchars($g['group_type']) ?></span>
                                <?php endif; ?>
                            </a>

                            <?php if ($canEditGroups): ?>
                                <form method="POST" onsubmit="return confirm('Delete the group <?= htmlspecialchars(addslashes((string)($g['name'] ?? ''))) ?>?');" class="ml-1 opacity-0 group-hover/row:opacity-100 transition">
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                                    <button type="submit" class="text-slate-300 hover:text-red-500 font-bold text-lg px-2 leading-none">&times;</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="content-lists" class="flex-1 flex flex-col min-h-0 <?= $currentListId ? '' : 'hidden' ?>">
                <div class="p-4 border-b border-slate-200 bg-white shrink-0">
                    <?php if ($canEditEmailLists): ?>
                        <form method="POST" class="flex gap-2 mb-3">
                            <input type="hidden" name="action" value="add_email_list">
                            <input type="text" name="list_name" placeholder="New Distribution List Name..." required class="flex-1 text-xs p-2 border border-slate-300 rounded focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition bg-slate-50">
                            <button type="submit" class="bg-indigo-600 text-white px-3 rounded font-bold hover:bg-indigo-700 transition" title="Add List">+</button>
                        </form>
                    <?php endif; ?>
                    <input type="text" onkeyup="filterList(this, 'emails-list')" placeholder="Search lists..." class="w-full text-xs p-2 bg-slate-50 border border-slate-200 rounded shadow-inner text-slate-700 focus:outline-none focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400">
                </div>

                <div id="emails-list" class="flex-1 overflow-y-auto p-1.5 bg-slate-50/30">
                    <?php if (empty($emailLists)): ?>
                        <div class="p-8 text-center text-slate-400 text-xs font-bold italic">No email lists created yet.</div>
                    <?php endif; ?>

                    <?php foreach($emailLists as $l): $isActive = ($currentListId == $l['id']); ?>
                        <div class="flex items-center justify-between p-1 group/row search-item">
                            <a href="?list_id=<?= (int)$l['id'] ?>" class="flex-1 px-3 py-2 rounded text-xs transition font-bold border border-transparent flex justify-between items-center <?= $isActive ? 'bg-indigo-50 text-indigo-700 border-indigo-200 shadow-sm' : 'hover:bg-slate-50 text-slate-600' ?>">
                                <span class="search-text"><?= htmlspecialchars($l['name'] ?? '') ?></span>
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 opacity-50" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                            </a>

                            <?php if ($canEditEmailLists): ?>
                                <form method="POST" onsubmit="return confirm('Delete the email list <?= htmlspecialchars(addslashes((string)($l['name'] ?? ''))) ?>?');" class="ml-1 opacity-0 group-hover/row:opacity-100 transition">
                                    <input type="hidden" name="action" value="delete_email_list">
                                    <input type="hidden" name="list_id" value="<?= (int)$l['id'] ?>">
                                    <button type="submit" class="text-slate-300 hover:text-red-500 font-bold text-lg px-2 leading-none">&times;</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-span-4 flex flex-col bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden h-full relative">

            <?php if ($activeGroup): ?>
                <div class="p-4 border-b border-slate-200 bg-indigo-600 shrink-0">
                    <h2 class="text-[10px] font-black text-indigo-200 uppercase tracking-widest mb-0.5">Managing Members For Group</h2>

                    <div class="text-lg font-black text-white mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <?= htmlspecialchars($activeGroup['name'] ?? '') ?>
                            <?php if (!empty($activeGroup['group_type'])): ?>
                                <span class="text-[9px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-indigo-500 text-indigo-100"><?= htmlspecialchars($activeGroup['group_type']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($canAccessEmails): ?>
                            <button onclick="openBlastModal('group', <?= (int)$activeGroup['id'] ?>, '<?= htmlspecialchars(addslashes($activeGroup['name'])) ?>')" class="bg-indigo-500 hover:bg-indigo-400 text-white px-3 py-1.5 rounded text-[10px] font-black uppercase tracking-widest flex items-center gap-1 transition shadow-sm" title="Email this group">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                                Email
                            </button>
                        <?php endif; ?>
                    </div>

                    <input type="text" onkeyup="filterList(this, 'members-list')" placeholder="Search roster..." class="w-full text-xs p-2 bg-indigo-700 border-none rounded shadow-inner text-white placeholder-indigo-300 focus:outline-none focus:ring-1 focus:ring-white">
                </div>

                <div id="members-list" class="flex-1 overflow-y-auto p-1.5">
                    <?php if (empty($sortedGroupPeople)): ?>
                        <div class="p-8 text-center text-slate-400 text-xs font-bold italic">Add people to the roster first.</div>
                    <?php endif; ?>

                    <?php foreach($sortedGroupPeople as $p):
                        $isMember = isset($groupMembersData[$p['id']]);
                        $isPianist = $p['is_group_pianist'] ?? 0;
                        $lockAttr = $canEditGroups ? '' : 'disabled';
                        $cursorClass = $canEditGroups ? 'cursor-pointer' : 'cursor-default';
                    ?>
                        <div class="flex items-center justify-between py-1.5 px-3 hover:bg-slate-50 rounded transition border-b border-slate-100 last:border-0 search-item <?= $isMember ? 'bg-indigo-50/50 hover:bg-indigo-50' : '' ?>">

                            <label class="flex items-center gap-3 <?= $cursorClass ?> flex-1 group/check">
                                <input type="checkbox" <?= $lockAttr ?> onchange="toggleMembership(<?= (int)$activeGroup['id'] ?>, <?= (int)$p['id'] ?>, this.checked)" <?= $isMember ? 'checked' : '' ?> class="custom-checkbox w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500 <?= $cursorClass ?>">
                                <span class="font-bold text-xs search-text <?= $isMember ? 'text-indigo-900' : 'text-slate-600' ?> <?= $canEditGroups ? 'group-hover/check:text-slate-800' : '' ?> transition">
                                    <?= htmlspecialchars(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?>
                                </span>
                            </label>

                            <div class="pianist-toggle ml-4 <?= $isMember ? '' : 'hidden' ?>">
                                <label class="flex items-center gap-1.5 <?= $cursorClass ?> group/label">
                                    <input type="checkbox" <?= $lockAttr ?> onchange="togglePianist(<?= (int)$activeGroup['id'] ?>, <?= (int)$p['id'] ?>, this.checked)" <?= $isPianist ? 'checked' : '' ?> class="custom-checkbox w-3.5 h-3.5 text-indigo-500 border-slate-300 rounded focus:ring-indigo-500 <?= $cursorClass ?>">
                                    <span class="text-[9px] font-black uppercase tracking-widest <?= $isPianist ? 'text-indigo-600' : 'text-slate-400' ?> <?= $canEditGroups ? 'group-hover/label:text-slate-600' : '' ?> transition">Pianist</span>
                                </label>
                            </div>

                        </div>
                    <?php endforeach; ?>
                </div>

            <?php elseif ($activeList): ?>
                <div class="p-4 border-b border-slate-200 bg-slate-800 shrink-0">
                    <h2 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Managing Subscribers For List</h2>

                    <div class="text-lg font-black text-white mb-3 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <?= htmlspecialchars($activeList['name'] ?? '') ?>
                        </div>
                        <?php if ($canAccessEmails): ?>
                            <button onclick="openBlastModal('list', <?= (int)$activeList['id'] ?>, '<?= htmlspecialchars(addslashes($activeList['name'])) ?>')" class="bg-slate-600 hover:bg-slate-500 text-white px-3 py-1.5 rounded text-[10px] font-black uppercase tracking-widest flex items-center gap-1 transition shadow-sm" title="Email this list">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" /><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" /></svg>
                                Email
                            </button>
                        <?php endif; ?>
                    </div>

                    <input type="text" onkeyup="filterList(this, 'list-members-list')" placeholder="Search roster..." class="w-full text-xs p-2 bg-slate-700 border-none rounded shadow-inner text-white placeholder-slate-400 focus:outline-none focus:ring-1 focus:ring-white">
                </div>

                <div id="list-members-list" class="flex-1 overflow-y-auto p-1.5">
                    <?php if (empty($sortedListPeople)): ?>
                        <div class="p-8 text-center text-slate-400 text-xs font-bold italic">Add people to the roster first.</div>
                    <?php endif; ?>

                    <?php foreach($sortedListPeople as $p):
                        $isMember = in_array($p['id'], $listMembersData);
                        $lockAttr = $canEditEmailLists ? '' : 'disabled';
                        $cursorClass = $canEditEmailLists ? 'cursor-pointer' : 'cursor-default';
                        $missingEmail = empty($p['email']);
                    ?>
                        <div class="flex items-center justify-between py-1.5 px-3 hover:bg-slate-50 rounded transition border-b border-slate-100 last:border-0 search-item <?= $isMember ? 'bg-slate-100 hover:bg-slate-200' : '' ?>">

                            <label class="flex items-center gap-3 <?= $cursorClass ?> flex-1 group/check <?= $missingEmail && !$isMember ? 'opacity-50' : '' ?>">
                                <input type="checkbox" <?= $lockAttr ?> <?= $missingEmail && !$isMember ? 'disabled title="Cannot add without email address"' : '' ?> onchange="toggleListMembership(<?= (int)$activeList['id'] ?>, <?= (int)$p['id'] ?>, this.checked)" <?= $isMember ? 'checked' : '' ?> class="custom-checkbox w-4 h-4 text-slate-800 border-slate-300 rounded focus:ring-slate-500 <?= $cursorClass ?>">

                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-xs search-text <?= $isMember ? 'text-slate-900' : 'text-slate-600' ?> <?= $canEditEmailLists ? 'group-hover/check:text-slate-800' : '' ?> transition truncate">
                                        <?= htmlspecialchars(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? '')) ?>
                                    </div>
                                    <div class="text-[9px] font-normal <?= $missingEmail ? 'text-red-400 italic' : 'text-slate-400' ?> search-text truncate">
                                        <?= $missingEmail ? 'No Email Found' : htmlspecialchars($p['email']) ?>
                                    </div>
                                </div>
                            </label>

                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="absolute inset-0 flex flex-col items-center justify-center text-slate-300 bg-slate-50/50">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mb-4 opacity-50" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                    <div class="font-black text-xl uppercase tracking-tighter text-slate-400 text-center px-8">Select a Group<br>or Email List</div>
                    <p class="mt-2 text-[10px] font-bold uppercase tracking-widest text-center">To view its members</p>
                </div>
            <?php endif; ?>
        </div>

    </div>

    <script>
        const canEditGroups = <?= $canEditGroups ? 'true' : 'false' ?>;
        const canEditEmailLists = <?= $canEditEmailLists ? 'true' : 'false' ?>;

        function showToast() {
            const toast = document.getElementById('save-toast');
            toast.style.opacity = '1';
            setTimeout(() => toast.style.opacity = '0', 2000);
        }

        // Live Javascript Filtering
        function filterList(input, listId) {
            const filter = input.value.toLowerCase();
            const items = document.getElementById(listId).querySelectorAll('.search-item');

            items.forEach(item => {
                const text = item.querySelector('.search-text').innerText.toLowerCase();
                item.style.display = text.includes(filter) ? '' : 'none';
            });
        }

        // UI Tab Switcher
        function switchTab(tab) {
            if (tab === 'groups') {
                document.getElementById('content-groups').classList.remove('hidden');
                document.getElementById('content-lists').classList.add('hidden');

                document.getElementById('tab-groups').classList.replace('text-slate-400', 'text-indigo-600');
                document.getElementById('tab-groups').classList.replace('border-transparent', 'border-indigo-600');
                document.getElementById('tab-groups').classList.replace('hover:bg-slate-100', 'bg-white');

                document.getElementById('tab-lists').classList.replace('text-indigo-600', 'text-slate-400');
                document.getElementById('tab-lists').classList.replace('border-indigo-600', 'border-transparent');
                document.getElementById('tab-lists').classList.replace('bg-white', 'hover:bg-slate-100');
            } else {
                document.getElementById('content-lists').classList.remove('hidden');
                document.getElementById('content-groups').classList.add('hidden');

                document.getElementById('tab-lists').classList.replace('text-slate-400', 'text-indigo-600');
                document.getElementById('tab-lists').classList.replace('border-transparent', 'border-indigo-600');
                document.getElementById('tab-lists').classList.replace('hover:bg-slate-100', 'bg-white');

                document.getElementById('tab-groups').classList.replace('text-indigo-600', 'text-slate-400');
                document.getElementById('tab-groups').classList.replace('border-indigo-600', 'border-transparent');
                document.getElementById('tab-groups').classList.replace('bg-white', 'hover:bg-slate-100');
            }
        }

        // Custom UI Modal for Editing Contacts
        function editContact(id, first, last, email, phone) {
            if (!canEditGroups) return;
            document.getElementById('edit-person-id').value = id;
            document.getElementById('edit-person-first').value = first;
            document.getElementById('edit-person-last').value = last;
            document.getElementById('edit-person-email').value = email;
            document.getElementById('edit-person-phone').value = phone;

            const modal = document.getElementById('edit-person-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.getElementById('edit-person-first').focus();
        }

        function closeEditModal() {
            const modal = document.getElementById('edit-person-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        async function saveEditPerson() {
            if (!canEditGroups) return;

            const id = document.getElementById('edit-person-id').value;
            const first = document.getElementById('edit-person-first').value.trim();
            const last = document.getElementById('edit-person-last').value.trim();
            const email = document.getElementById('edit-person-email').value.trim();
            const phone = document.getElementById('edit-person-phone').value.trim();

            if (!first || !last) {
                alert("First and Last name are required.");
                return;
            }

            let fd = new FormData();
            fd.append('action', 'edit_person_contact');
            fd.append('person_id', id);
            fd.append('first_name', first);
            fd.append('last_name', last);
            fd.append('email', email);
            fd.append('phone', phone);

            await fetch('groups.php', { method: 'POST', body: fd });
            window.location.reload();
        }

        // Handle Enter key inside the modal
        document.getElementById('edit-person-modal').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                saveEditPerson();
            }
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });

        // Email Blast Modal functions
        <?php if ($canAccessEmails): ?>
            function openBlastModal(type, id, name) {
                document.getElementById('blast-target-type').value = type;
                document.getElementById('blast-target-id').value = id;
                document.getElementById('blast-target-name').innerText = name;

                const modal = document.getElementById('email-blast-modal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closeBlastModal() {
                const modal = document.getElementById('email-blast-modal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            document.getElementById('email-blast-modal').addEventListener('click', function(e) {
                if (e.target === this) closeBlastModal();
            });
            document.getElementById('email-blast-modal').addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeBlastModal();
            });
        <?php endif; ?>

        // --- GROUP AJAX LOGIC ---
        async function togglePianist(groupId, personId, isPianist) {
            if (!canEditGroups) return;

            let formData = new FormData();
            formData.append('action', 'toggle_pianist');
            formData.append('group_id', groupId);
            formData.append('person_id', personId);
            formData.append('is_pianist', isPianist ? 1 : 0);

            // Optimistic UI Update
            const checkbox = document.querySelector(`input[onchange="togglePianist(${groupId}, ${personId}, this.checked)"]`);
            const labelSpan = checkbox.nextElementSibling;
            if (isPianist) {
                labelSpan.classList.replace('text-slate-400', 'text-indigo-600');
                labelSpan.classList.remove('group-hover/label:text-slate-600');
            } else {
                labelSpan.classList.replace('text-indigo-600', 'text-slate-400');
                labelSpan.classList.add('group-hover/label:text-slate-600');
            }

            await fetch('groups.php', { method: 'POST', body: formData });
            showToast();
        }

        async function toggleMembership(groupId, personId, isMember) {
            if (!canEditGroups) return;

            let formData = new FormData();
            formData.append('action', 'toggle_membership');
            formData.append('group_id', groupId);
            formData.append('person_id', personId);
            formData.append('is_member', isMember ? 1 : 0);

            // Optimistic UI Update for membership
            const checkbox = document.querySelector(`input[onchange="toggleMembership(${groupId}, ${personId}, this.checked)"]`);
            const rowDiv = checkbox.closest('.search-item');
            const nameSpan = checkbox.nextElementSibling;
            const pianistToggle = rowDiv.querySelector('.pianist-toggle');

            if (isMember) {
                rowDiv.classList.add('bg-indigo-50/50', 'hover:bg-indigo-50');
                nameSpan.classList.replace('text-slate-600', 'text-indigo-900');
                pianistToggle.classList.remove('hidden');
            } else {
                rowDiv.classList.remove('bg-indigo-50/50', 'hover:bg-indigo-50');
                nameSpan.classList.replace('text-indigo-900', 'text-slate-600');
                pianistToggle.classList.add('hidden');

                // Automatically uncheck pianist if they are removed from the group
                const pianistCheck = pianistToggle.querySelector('input[type="checkbox"]');
                if (pianistCheck.checked) {
                    pianistCheck.checked = false;
                    const labelSpan = pianistCheck.nextElementSibling;
                    labelSpan.classList.replace('text-indigo-600', 'text-slate-400');
                    labelSpan.classList.add('group-hover/label:text-slate-600');
                }
            }

            await fetch('groups.php', { method: 'POST', body: formData });
            showToast();
        }

        // --- EMAIL LIST AJAX LOGIC ---
        async function toggleListMembership(listId, personId, isMember) {
            if (!canEditEmailLists) return;

            let formData = new FormData();
            formData.append('action', 'toggle_list_membership');
            formData.append('list_id', listId);
            formData.append('person_id', personId);
            formData.append('is_member', isMember ? 1 : 0);

            // Optimistic UI Update for email lists
            const checkbox = document.querySelector(`input[onchange="toggleListMembership(${listId}, ${personId}, this.checked)"]`);
            const rowDiv = checkbox.closest('.search-item');
            const infoDivContainer = checkbox.nextElementSibling;
            const nameSpan = infoDivContainer.querySelector('.font-bold');

            if (isMember) {
                rowDiv.classList.add('bg-slate-100', 'hover:bg-slate-200');
                nameSpan.classList.replace('text-slate-600', 'text-slate-900');
            } else {
                rowDiv.classList.remove('bg-slate-100', 'hover:bg-slate-200');
                nameSpan.classList.replace('text-slate-900', 'text-slate-600');
            }

            await fetch('groups.php', { method: 'POST', body: formData });
            showToast();
        }
    </script>
</body>
</html>