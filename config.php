<?php
require 'db.php';

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


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canAccessConfig) {
        die('Unauthorized: Missing n-cue-config');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'save_colors') {
        $db->exec("DELETE FROM type_colors");
        $stmt = $db->prepare("INSERT INTO type_colors (item_type, bg_color, border_color, text_color) VALUES (?, ?, ?, ?)");
        foreach ($_POST['types'] as $type => $colors) {
            $stmt->execute([$type, $colors['bg'], $colors['border'], $colors['text']]);
        }
        header("Location: config.php?saved=1"); exit;
    }

    if ($action === 'save_capacities') {
        $db->prepare("UPDATE service_capacities SET max_specials = ? WHERE service_type = 'Sunday AM'")->execute([$_POST['sun_am']]);
        $db->prepare("UPDATE service_capacities SET max_specials = ? WHERE service_type = 'Sunday PM'")->execute([$_POST['sun_pm']]);
        $db->prepare("UPDATE service_capacities SET max_specials = ? WHERE service_type = 'Wednesday PM'")->execute([$_POST['wed_pm']]);
        header("Location: config.php?saved=1"); exit;
    }
}

// Fetch Colors
$defaultTypes = ['Hymn', 'Prelude', 'Choir Special', 'Special Music', 'Welcome', 'Prayer', 'Message', 'Announcements', 'Introduction', 'Dismissal', 'Baptism', 'Birthdays', 'Custom'];
$dbTypes = $db->query("SELECT DISTINCT item_type FROM service_items WHERE item_type != 'Section Break'")->fetchAll(PDO::FETCH_COLUMN);
$allTypes = array_unique(array_merge($defaultTypes, $dbTypes));
sort($allTypes);

$savedColors = [];
foreach($db->query("SELECT * FROM type_colors") as $row) { $savedColors[$row['item_type']] = $row; }

function getFallbackColors($type) {
    $music = ['Hymn', 'Prelude', 'Choir Special', 'Special Music'];
    $speaking = ['Welcome', 'Prayer', 'Message', 'Announcements', 'Introduction', 'Dismissal'];
    if (in_array($type, $music)) return ['bg' => '#eff6ff', 'border' => '#3b82f6', 'text' => '#1e40af'];
    if (in_array($type, $speaking)) return ['bg' => '#f0fdf4', 'border' => '#22c55e', 'text' => '#166534'];
    return ['bg' => '#f8fafc', 'border' => '#64748b', 'text' => '#475569'];
}

// Fetch Capacities
$capacities = [];
foreach($db->query("SELECT * FROM service_capacities") as $row) { $capacities[$row['service_type']] = $row['max_specials']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Cue - Configuration</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
        input[type="color"]::-webkit-color-swatch { border: 1px solid #cbd5e1; border-radius: 4px; }
        input[type="color"]:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col font-sans">

    <?php if (isset($_GET['saved'])): ?>
    <div class="fixed bottom-6 right-6 bg-emerald-600 text-white px-6 py-3 rounded-lg shadow-2xl font-bold transition-opacity duration-300 opacity-0 z-50 flex items-center gap-2" id="save-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
        Settings Saved Successfully
    </div>
    <script>setTimeout(() => { document.getElementById('save-toast').style.opacity = '1'; setTimeout(() => document.getElementById('save-toast').style.opacity = '0', 2500); }, 100);</script>
    <?php endif; ?>

    <header class="bg-slate-900 text-white p-4 shadow-md z-50 shrink-0">
        <div class="max-w-[1800px] mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-black text-indigo-400 tracking-tighter">CUE</h1>

            <div class="flex items-center">
                <nav class="space-x-8 text-sm font-bold uppercase tracking-widest text-slate-400 flex items-center">
                    <a href="index.php" class="hover:text-white">Builder</a>
                    <?php if($canAccessSchedule): ?><a href="schedule.php" class="hover:text-white">Schedule</a><?php endif; ?>
                    <?php if($canAccessHymns): ?><a href="hymns.php" class="hover:text-white">Hymns</a><?php endif; ?>
                    <?php if($canAccessGroups): ?><a href="groups.php" class="hover:text-white">Groups</a><?php endif; ?>
                    <?php if($canAccessPreludes): ?><a href="preludes.php" class="hover:text-white">Preludes</a><?php endif; ?>
                    <a href="config.php" class="text-white border-b-2 border-indigo-500 pb-1">Config</a>
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

    <div class="flex-1 grid grid-cols-12 gap-8 max-w-[1800px] mx-auto w-full p-8 relative">

        <?php if (!$canAccessConfig): ?>
            <div class="absolute top-4 right-8 text-xs font-black uppercase tracking-widest text-slate-400 pointer-events-none z-10">Read Only Mode</div>
        <?php endif; ?>

        <div class="col-span-4 flex flex-col gap-6">
            <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-6 relative overflow-hidden">
                <h2 class="text-xl font-black text-slate-900 leading-none mb-1">Service Capacities</h2>
                <p class="text-slate-500 font-bold uppercase tracking-widest text-[10px] mb-6">Manage Schedule Limits</p>

                <form method="POST">
                    <input type="hidden" name="action" value="save_capacities">
                    <div class="space-y-4 mb-6">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Sunday AM Specials</label>
                            <input type="number" <?= $canAccessConfig ? '' : 'disabled' ?> name="sun_am" value="<?= $capacities['Sunday AM'] ?? 2 ?>" min="0" class="w-full text-sm p-2 border border-slate-300 rounded focus:border-indigo-500 outline-none disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Sunday PM Specials</label>
                            <input type="number" <?= $canAccessConfig ? '' : 'disabled' ?> name="sun_pm" value="<?= $capacities['Sunday PM'] ?? 1 ?>" min="0" class="w-full text-sm p-2 border border-slate-300 rounded focus:border-indigo-500 outline-none disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-1">Wednesday PM Specials</label>
                            <input type="number" <?= $canAccessConfig ? '' : 'disabled' ?> name="wed_pm" value="<?= $capacities['Wednesday PM'] ?? 1 ?>" min="0" class="w-full text-sm p-2 border border-slate-300 rounded focus:border-indigo-500 outline-none disabled:bg-slate-50 disabled:text-slate-400">
                        </div>
                    </div>
                    <?php if ($canAccessConfig): ?>
                        <button type="submit" class="w-full bg-indigo-600 text-white py-2.5 rounded font-black uppercase tracking-widest text-xs hover:bg-indigo-700 transition">Save Capacities</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="col-span-8 bg-white border border-slate-200 rounded-xl shadow-sm p-6 relative overflow-hidden">
            <h2 class="text-xl font-black text-slate-900 leading-none mb-1">Print Styling</h2>
            <p class="text-slate-500 font-bold uppercase tracking-widest text-[10px] mb-6">Configure Category Colors for Cue Cards</p>

            <form method="POST">
                <input type="hidden" name="action" value="save_colors">
                <div class="grid grid-cols-2 gap-x-8 gap-y-3">
                    <?php foreach ($allTypes as $type):
                        $fallback = getFallbackColors($type);
                        $bg = $savedColors[$type]['bg_color'] ?? $fallback['bg'];
                        $border = $savedColors[$type]['border_color'] ?? $fallback['border'];
                        $text = $savedColors[$type]['text_color'] ?? $fallback['text'];
                    ?>
                        <div class="flex items-center justify-between p-2 border border-slate-100 rounded <?= $canAccessConfig ? 'hover:bg-slate-50' : '' ?>">
                            <span class="font-bold text-slate-600 text-xs w-32 uppercase tracking-tight"><?= htmlspecialchars($type) ?></span>
                            <div class="flex items-center gap-3">
                                <label class="flex flex-col items-center">
                                    <span class="text-[8px] uppercase font-black text-slate-400 mb-0.5">Text</span>
                                    <input type="color" <?= $canAccessConfig ? '' : 'disabled' ?> name="types[<?= htmlspecialchars($type) ?>][text]" value="<?= htmlspecialchars($text) ?>" class="w-6 h-6 <?= $canAccessConfig ? 'cursor-pointer' : '' ?> bg-transparent">
                                </label>
                                <label class="flex flex-col items-center">
                                    <span class="text-[8px] uppercase font-black text-slate-400 mb-0.5">Border</span>
                                    <input type="color" <?= $canAccessConfig ? '' : 'disabled' ?> name="types[<?= htmlspecialchars($type) ?>][border]" value="<?= htmlspecialchars($border) ?>" class="w-6 h-6 <?= $canAccessConfig ? 'cursor-pointer' : '' ?> bg-transparent">
                                </label>
                                <label class="flex flex-col items-center">
                                    <span class="text-[8px] uppercase font-black text-slate-400 mb-0.5">BG</span>
                                    <input type="color" <?= $canAccessConfig ? '' : 'disabled' ?> name="types[<?= htmlspecialchars($type) ?>][bg]" value="<?= htmlspecialchars($bg) ?>" class="w-6 h-6 <?= $canAccessConfig ? 'cursor-pointer' : '' ?> bg-transparent">
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($canAccessConfig): ?>
                    <div class="mt-8 pt-6 border-t border-slate-200 text-right">
                        <button type="submit" class="bg-slate-800 text-white px-8 py-3 rounded font-black uppercase tracking-widest text-xs hover:bg-black transition">Save Colors</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</body>
</html>