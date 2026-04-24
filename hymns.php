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


// --- AJAX ENDPOINT FOR INLINE EDITING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_hymn') {

    // Guard: Prevent unauthorized edits
    if (!$canEditHymns) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-hymnsedit']);
        exit;
    }

    $id = $_POST['id'];
    $field = $_POST['field'];
    $value = $_POST['value'];

    // STRICT Security: Whitelist allowed columns to prevent SQL injection
    $allowed_columns = [
        'Name', 'NVB', 'SSH', 'NVR', 'MAJ', 'Key', 'First_Line', 'OOS', 'Verses_to_Sing',
        'Date_of_Most_Recent_Use', 'Service_Index'
    ];

    if (in_array($field, $allowed_columns)) {
        $stmt = $db->prepare("UPDATE hymns SET `$field` = ? WHERE ID = ?");
        $stmt->execute([$value, $id]);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid column']);
    }
    exit;
}

// Fetch the entire master list once.
$hymns = $db->query("SELECT * FROM hymns")->fetchAll(PDO::FETCH_ASSOC);

// Define the exact topic columns based on your database schema
$topics = [
    'Blood', 'Christmas', 'Cross', 'Easter', 'Grace', 'Heaven', 'Holy_Spirit', 'Invitation',
    'Missions', 'Patriotic', 'Prayer', 'Salvation', 'Second_Coming', 'Bible', 'Calvary',
    'Great_Hymns', 'Service', 'Thanksgiving', 'Assurance', 'Christian_Warfare', 'Comfort_Guidance',
    'Consecration', 'Consolation', 'Faith_Trust', 'Joy_Singing', 'Love', 'Praise', 'Resurrection',
    'Soul_Winning_Service', 'Testimony'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cue - Hymn Master Tracker</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom Scrollbar for the massive table */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* Spreadsheet focus states */
        [contenteditable]:focus {
            outline: 2px solid #6366f1;
            background-color: #e0e7ff;
            border-radius: 2px;
            padding: 2px 4px;
            margin: -2px -4px;
            cursor: text;
        }
        .sortable-header { cursor: pointer; user-select: none; font-family: monospace; }
        .sortable-header:hover { background-color: #e2e8f0; color: #1e40af; }
    </style>
</head>
<body class="bg-slate-50 h-screen flex flex-col font-sans overflow-hidden">

    <div class="fixed bottom-6 right-6 bg-emerald-600 text-white px-6 py-3 rounded-lg shadow-2xl font-bold transition-opacity duration-300 opacity-0 pointer-events-none z-50 flex items-center gap-2" id="save-toast">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
        Update Saved
    </div>

    <header class="bg-slate-900 text-white p-4 shadow-md z-50 shrink-0">
        <div class="max-w-[1800px] mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-black text-indigo-400 tracking-tighter">CUE</h1>

            <div class="flex items-center">
                <nav class="space-x-8 text-sm font-bold uppercase tracking-widest text-slate-400 flex items-center">
                    <a href="index.php" class="hover:text-white">Builder</a>
                    <?php if($canAccessSchedule): ?><a href="schedule.php" class="hover:text-white">Schedule</a><?php endif; ?>
                    <a href="hymns.php" class="text-white border-b-2 border-indigo-500 pb-1">Hymns</a>
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

    <div class="flex-1 flex flex-col max-w-[1800px] mx-auto w-full p-8 min-h-0">

        <div class="flex justify-between items-end mb-6 shrink-0 gap-8">
            <div class="shrink-0">
                <h2 class="text-4xl font-black text-slate-900 leading-none mb-2">Master Hymn Tracker</h2>
            </div>

            <div class="flex-1 flex gap-3 items-center justify-end">
                <div class="w-48">
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Filter by Schedule</label>
                    <select id="index-filter" class="w-full text-sm font-bold p-2.5 border-2 border-slate-200 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-0 transition bg-white text-slate-700">
                        <option value="">All Services</option>
                        <option value="1A">1A - Sunday AM 1st</option>
                        <option value="1B">1B - Sunday PM 1st</option>
                        <option value="1C">1C - Wed PM 1st</option>
                        <option value="1D">1D - Special Event</option>
                        <option value="2">2 - 2nd Song (Any)</option>
                        <option value="3/4">3/4 - 3rd/4th Song</option>
                    </select>
                </div>

                <div class="w-48">
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Filter by Topic</label>
                    <select id="topic-filter" class="w-full text-sm font-bold p-2.5 border-2 border-slate-200 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-0 transition bg-white text-slate-700">
                        <option value="">All Topics</option>
                        <?php foreach($topics as $topic): ?>
                            <option value="<?= $topic ?>"><?= str_replace('_', ' ', $topic) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="w-64 relative">
                    <label class="block text-[9px] font-black text-slate-400 uppercase tracking-widest mb-1">Search Specific Song</label>
                    <input type="text" id="global-search" placeholder="Search lyrics, titles..." class="w-full text-sm font-bold p-2.5 pl-9 border-2 border-slate-200 rounded-lg shadow-sm focus:border-indigo-500 focus:ring-0 transition bg-white placeholder-slate-400">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 absolute left-3 top-8 text-slate-400" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" /></svg>
                </div>
            </div>
        </div>

        <div class="flex-1 bg-white border border-slate-200 rounded-xl shadow-sm overflow-auto relative min-h-0">
            <?php if (!$canEditHymns): ?>
                <div class="absolute top-2 right-4 text-xs font-black uppercase tracking-widest text-slate-300 pointer-events-none z-20">Read Only Mode</div>
            <?php endif; ?>

            <table class="w-full text-left border-collapse text-sm whitespace-nowrap">
                <thead class="bg-slate-100 sticky top-0 z-10 shadow-sm outline outline-1 outline-slate-200">
                    <tr class="text-[10px] font-black text-slate-500 uppercase tracking-widest">
                        <th class="p-3 sortable-header transition" data-col="ID">ID &uarr;</th>
                        <th class="p-3 sortable-header transition" data-col="Name">Title &#8597;</th>
                        <th class="p-3 sortable-header transition" data-col="Service_Index">Index &#8597;</th>
                        <th class="p-3 sortable-header transition text-center" data-col="NVB">NVB &#8597;</th>
                        <th class="p-3 sortable-header transition text-center" data-col="SSH">SSH &#8597;</th>
                        <th class="p-3 sortable-header transition text-center" data-col="NVR">NVR &#8597;</th>
                        <th class="p-3 sortable-header transition text-center" data-col="MAJ">MAJ &#8597;</th>
                        <th class="p-3 sortable-header transition text-center" data-col="Key">Key &#8597;</th>
                        <th class="p-3 sortable-header transition" data-col="OOS">Order of Service Text &#8597;</th>
                        <th class="p-3 sortable-header transition text-center" data-col="Verses_to_Sing">Verses &#8597;</th>
                        <th class="p-3 sortable-header transition" data-col="First_Line">First Line &#8597;</th>
                        <th class="p-3 sortable-header transition" data-col="Date_of_Most_Recent_Use">Last Used &#8597;</th>
                    </tr>
                </thead>
                <tbody id="table-body" class="divide-y divide-slate-100 text-slate-700">
                    </tbody>
            </table>
        </div>
    </div>

    <script>
        const canEditHymns = <?= $canEditHymns ? 'true' : 'false' ?>;
        let hymnsData = <?= json_encode($hymns) ?>;
        const tbody = document.getElementById('table-body');
        const searchInput = document.getElementById('global-search');
        const topicFilter = document.getElementById('topic-filter');
        const indexFilter = document.getElementById('index-filter');

        // Set initial sort state to ID
        let currentSort = { column: 'ID', direction: 'asc' };

        // Ensure data is sorted by ID on first load
        hymnsData.sort((a, b) => (parseInt(a.ID) || 0) - (parseInt(b.ID) || 0));

        const indexTranslations = {
            '1A': 'Sun AM 1st',
            '1B': 'Sun PM 1st',
            '1C': 'Wed PM 1st',
            '1D': 'Special Event',
            '2': '2nd Song (Any)',
            '3/4': '3rd/4th Song'
        };

        function renderTable(data) {
            let html = '';

            // Apply editable class conditionally
            const editClass = canEditHymns ? 'editable' : '';

            data.forEach(h => {
                let dateStyle = 'text-slate-400 italic';
                if (h.Date_of_Most_Recent_Use) {
                    const daysAgo = (new Date() - new Date(h.Date_of_Most_Recent_Use)) / (1000 * 60 * 60 * 24);
                    if (daysAgo <= 30) dateStyle = 'text-red-600 font-black';
                    else if (daysAgo <= 60) dateStyle = 'text-orange-500 font-bold';
                    else if (daysAgo <= 90) dateStyle = 'text-yellow-500 font-bold';
                    else dateStyle = 'text-slate-600';
                }

                let idxRaw = h.Service_Index || '';
                let idxTrans = indexTranslations[idxRaw.toUpperCase()]
                    ? `<span class="text-[10px] text-slate-400 font-normal italic ml-1">- ${indexTranslations[idxRaw.toUpperCase()]}</span>`
                    : '';

                html += `
                <tr class="hover:bg-indigo-50/50 transition group" data-id="${h.ID}">
                    <td class="p-3 text-slate-400 font-mono text-xs cursor-default">${h.ID}</td>
                    <td class="p-3 font-bold text-slate-900 ${editClass}" data-field="Name" data-raw="${h.Name || ''}">${h.Name || ''}</td>
                    <td class="p-3 font-bold text-slate-800 ${editClass}" data-field="Service_Index" data-raw="${idxRaw}">${idxRaw}${idxTrans}</td>
                    <td class="p-3 text-center font-bold text-indigo-600 ${editClass}" data-field="NVB" data-raw="${h.NVB || ''}">${h.NVB || ''}</td>
                    <td class="p-3 text-center ${editClass}" data-field="SSH" data-raw="${h.SSH || ''}">${h.SSH || ''}</td>
                    <td class="p-3 text-center ${editClass}" data-field="NVR" data-raw="${h.NVR || ''}">${h.NVR || ''}</td>
                    <td class="p-3 text-center ${editClass}" data-field="MAJ" data-raw="${h.MAJ || ''}">${h.MAJ || ''}</td>
                    <td class="p-3 text-center font-mono text-emerald-600 font-bold ${editClass}" data-field="Key" data-raw="${h.Key || ''}">${h.Key || ''}</td>
                    <td class="p-3 text-xs ${editClass} truncate max-w-[200px]" data-field="OOS" data-raw="${h.OOS || ''}" title="${h.OOS || ''}">${h.OOS || ''}</td>
                    <td class="p-3 text-center ${editClass}" data-field="Verses_to_Sing" data-raw="${h.Verses_to_Sing || ''}">${h.Verses_to_Sing || ''}</td>
                    <td class="p-3 text-xs text-slate-500 italic ${editClass} truncate max-w-[250px]" data-field="First_Line" data-raw="${h.First_Line || ''}" title="${h.First_Line || ''}">${h.First_Line || ''}</td>
                    <td class="p-3 text-[10px] uppercase tracking-wider ${dateStyle} ${editClass}" data-field="Date_of_Most_Recent_Use" data-raw="${h.Date_of_Most_Recent_Use || ''}">${h.Date_of_Most_Recent_Use || 'Never'}</td>
                </tr>`;
            });
            tbody.innerHTML = html;

            if (canEditHymns) {
                attachEditListeners();
            }
        }

        function applyFilters() {
            const query = searchInput.value.toLowerCase();
            const topic = topicFilter.value;
            const idx = indexFilter.value;

            const filtered = hymnsData.filter(h => {
                if (topic && (h[topic] != 1 && h[topic] != '1')) return false;
                if (idx && String(h.Service_Index).toUpperCase() !== idx) return false;

                if (query) {
                    const matches = Object.values(h).some(val =>
                        String(val).toLowerCase().includes(query)
                    );
                    if (!matches) return false;
                }

                return true;
            });
            renderTable(filtered);
        }

        searchInput.addEventListener('input', applyFilters);
        topicFilter.addEventListener('change', applyFilters);
        indexFilter.addEventListener('change', applyFilters);

        document.querySelectorAll('.sortable-header').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.col;

                if (currentSort.column === col) {
                    currentSort.direction = currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    currentSort.column = col;
                    currentSort.direction = 'asc';
                }

                hymnsData.sort((a, b) => {
                    let valA = a[col] || '';
                    let valB = b[col] || '';

                    if (['NVB', 'SSH', 'NVR', 'MAJ', 'ID'].includes(col)) {
                        valA = parseInt(valA) || 0;
                        valB = parseInt(valB) || 0;
                    } else {
                        valA = String(valA).toLowerCase();
                        valB = String(valB).toLowerCase();
                    }

                    if (valA < valB) return currentSort.direction === 'asc' ? -1 : 1;
                    if (valA > valB) return currentSort.direction === 'asc' ? 1 : -1;
                    return 0;
                });

                document.querySelectorAll('.sortable-header').forEach(h => h.innerHTML = h.innerHTML.replace(' &uarr;', ' &#8597;').replace(' &darr;', ' &#8597;'));
                th.innerHTML = th.innerHTML.replace(' &#8597;', currentSort.direction === 'asc' ? ' &uarr;' : ' &darr;');

                applyFilters();
            });
        });

        function attachEditListeners() {
            document.querySelectorAll('.editable').forEach(cell => {

                cell.addEventListener('click', function() {
                    if (this.getAttribute('contenteditable') !== 'true') {
                        this.setAttribute('contenteditable', 'true');
                        this.innerText = this.dataset.raw;
                        this.focus();
                    }
                });

                cell.addEventListener('blur', function() {
                    this.removeAttribute('contenteditable');
                    const newValue = this.innerText.trim();
                    const oldValue = this.dataset.raw.trim();
                    const field = this.dataset.field;
                    const id = this.closest('tr').dataset.id;

                    if (newValue !== oldValue) {
                        if (field !== 'Verses_to_Sing' && field !== 'Date_of_Most_Recent_Use' && field !== 'Service_Index') {
                            const confirmMsg = `WARNING: You are modifying core metadata.\n\nChange [${field}]:\nFrom: "${oldValue}"\nTo: "${newValue}"\n\nAre you sure you want to make this change?`;
                            if (!confirm(confirmMsg)) {
                                applyFilters();
                                return;
                            }
                        }

                        let formData = new FormData();
                        formData.append('action', 'update_hymn');
                        formData.append('id', id);
                        formData.append('field', field);
                        formData.append('value', newValue);

                        fetch('hymns.php', { method: 'POST', body: formData })
                        .then(res => res.json())
                        .then(data => {
                            if(data.status === 'success') {
                                const targetHymn = hymnsData.find(h => h.ID == id);
                                if (targetHymn) targetHymn[field] = newValue;

                                applyFilters();

                                const toast = document.getElementById('save-toast');
                                toast.style.opacity = '1';
                                setTimeout(() => toast.style.opacity = '0', 2000);
                            } else {
                                alert("Failed to save: " + data.message);
                                applyFilters();
                            }
                        })
                        .catch(() => {
                            alert("A network error occurred.");
                            applyFilters();
                        });
                    } else {
                        applyFilters();
                    }
                });

                cell.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.blur();
                    }
                });
            });
        }

        // Initial Render
        renderTable(hymnsData);
    </script>
</body>
</html>