<?php
error_reporting(0); // Prevent any silent DB warnings from breaking JSON outputs
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


function getSortedItems($db, $setId) {
    $itemStmt = $db->prepare("SELECT * FROM prelude_items WHERE set_id = ? ORDER BY sort_order ASC, hymn_number ASC");
    $itemStmt->execute([$setId]);
    return $itemStmt->fetchAll(PDO::FETCH_ASSOC);
}

// --- AJAX HANDLERS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canEditPreludes) {
        die(json_encode(['status' => 'error', 'message' => 'Unauthorized: Missing n-cue-preludes']));
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_set') {
        $stmt = $db->prepare("INSERT INTO prelude_sets (name, hymnal) VALUES (?, ?)");
        $stmt->execute([$_POST['name'], $_POST['hymnal']]);
        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()]); exit;
    }

    if ($action === 'delete_set') {
        $db->prepare("DELETE FROM prelude_sets WHERE id = ?")->execute([$_POST['set_id']]);
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'add_item') {
        $setId = $_POST['set_id'];
        $number = (int)$_POST['number'];
        $title = $_POST['title'];

        // 1. Insert new item
        $stmt = $db->prepare("INSERT INTO prelude_items (set_id, hymn_number, title, sort_order) VALUES (?, ?, ?, 9999)");
        $stmt->execute([$setId, $number, $title]);

        // 2. Fetch all items for this set
        $items = $db->prepare("SELECT * FROM prelude_items WHERE set_id = ?");
        $items->execute([$setId]);
        $all = $items->fetchAll(PDO::FETCH_ASSOC);

        // 3. Force strict numerical sort as requested
        usort($all, function($a, $b) {
            return (int)$a['hymn_number'] <=> (int)$b['hymn_number'];
        });

        // 4. Save the new numerical order to the database
        $updateStmt = $db->prepare("UPDATE prelude_items SET sort_order = ? WHERE id = ?");
        foreach ($all as $index => $item) {
            $updateStmt->execute([$index, $item['id']]);
        }

        // 5. Return the fresh, sorted list to the Javascript
        echo json_encode(['status' => 'success', 'items' => getSortedItems($db, $setId)]); exit;
    }

    if ($action === 'remove_item') {
        $setId = $_POST['set_id'];
        $db->prepare("DELETE FROM prelude_items WHERE id = ?")->execute([$_POST['item_id']]);
        echo json_encode(['status' => 'success', 'items' => getSortedItems($db, $setId)]); exit;
    }

    if ($action === 'update_order') {
        $setId = $_POST['set_id'];
        $order = json_decode($_POST['order'], true);

        $stmt = $db->prepare("UPDATE prelude_items SET sort_order = ? WHERE id = ?");
        foreach ($order as $index => $id) {
            $stmt->execute([$index, $id]);
        }

        echo json_encode(['status' => 'success', 'items' => getSortedItems($db, $setId)]); exit;
    }
}

// --- AJAX GET NEAREST ENDPOINT ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ajax']) && $_GET['ajax'] === 'nearest') {
    try {
        $number = (int)$_GET['number'];
        $hymnal = strtoupper($_GET['hymnal']);
        $col = in_array($hymnal, ['NVB', 'SSH', 'MAJ', 'NVR']) ? $hymnal : 'NVB';

        // 5 Before
        $stmtBefore = $db->prepare("
            SELECT ID, Name AS title, $col AS number
            FROM hymns
            WHERE $col IS NOT NULL AND $col != '' AND CAST($col AS INTEGER) > 0
              AND CAST($col AS INTEGER) < ?
            ORDER BY CAST($col AS INTEGER) DESC
            LIMIT 5
        ");
        $stmtBefore->execute([$number]);
        $before = array_reverse($stmtBefore->fetchAll(PDO::FETCH_ASSOC));

        // 5 After
        $stmtAfter = $db->prepare("
            SELECT ID, Name AS title, $col AS number
            FROM hymns
            WHERE $col IS NOT NULL AND $col != '' AND CAST($col AS INTEGER) > 0
              AND CAST($col AS INTEGER) > ?
            ORDER BY CAST($col AS INTEGER) ASC
            LIMIT 5
        ");
        $stmtAfter->execute([$number]);
        $after = $stmtAfter->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['before' => $before, 'after' => $after]); exit;
    } catch (Exception $e) {
        echo json_encode(['before' => [], 'after' => []]); exit;
    }
}

// --- INITIAL DATA FETCH ---
$activeSetId = $_GET['set'] ?? null;
$preludeSets = [];
try { $preludeSets = $db->query("SELECT * FROM prelude_sets ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) {}

$activeSet = null;
$setItems = [];
if ($activeSetId) {
    $stmt = $db->prepare("SELECT * FROM prelude_sets WHERE id = ?");
    $stmt->execute([$activeSetId]);
    $activeSet = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($activeSet) {
        $setItems = getSortedItems($db, $activeSetId);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cue - Preludes</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .list-item-drag { cursor: grab; transition: background 0.2s; }
        .list-item-drag:active { cursor: grabbing; background: #f8fafc; }
        .ghost-drop { opacity: 0.4; border: 2px dashed #cbd5e1; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex flex-col font-sans">

    <header class="bg-slate-900 text-white p-4 shadow-md z-50 shrink-0">
        <div class="max-w-[1800px] mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-black text-indigo-400 tracking-tighter">CUE</h1>

            <div class="flex items-center">
                <nav class="space-x-8 text-sm font-bold uppercase tracking-widest text-slate-400 flex items-center">
                    <a href="index.php" class="hover:text-white">Builder</a>
                    <?php if($canAccessSchedule): ?><a href="schedule.php" class="hover:text-white">Schedule</a><?php endif; ?>
                    <?php if($canAccessHymns): ?><a href="hymns.php" class="hover:text-white">Hymns</a><?php endif; ?>
                    <?php if($canAccessGroups): ?><a href="groups.php" class="hover:text-white">Groups</a><?php endif; ?>
                    <a href="preludes.php" class="text-white border-b-2 border-indigo-500 pb-1">Preludes</a>
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

    <div class="flex-1 max-w-[1800px] mx-auto w-full p-8 flex gap-8">

        <div class="w-1/4 flex flex-col gap-4">
            <div class="flex justify-between items-end mb-2">
                <h2 class="text-2xl font-black text-slate-900 leading-none">Saved Sets</h2>
                <?php if ($canEditPreludes): ?>
                    <button onclick="createNewSet()" class="bg-indigo-600 text-white px-3 py-1.5 rounded text-xs font-black uppercase tracking-widest hover:bg-indigo-700 transition">+ New Set</button>
                <?php endif; ?>
            </div>

            <div class="bg-white border border-slate-300 rounded-xl shadow-sm overflow-hidden flex flex-col relative">
                <?php if (empty($preludeSets)): ?>
                    <div class="p-6 text-center text-slate-400 text-sm font-bold">No sets created yet.</div>
                <?php else: ?>
                    <?php foreach ($preludeSets as $set): ?>
                        <a href="?set=<?= $set['id'] ?>" class="p-4 border-b border-slate-100 hover:bg-slate-50 transition flex items-center justify-between <?= $activeSetId == $set['id'] ? 'bg-indigo-50 border-l-4 border-l-indigo-600' : '' ?>">
                            <div>
                                <div class="font-black text-slate-800 text-sm"><?= htmlspecialchars($set['name']) ?></div>
                                <div class="mt-1">
                                    <span class="text-[9px] font-black text-white bg-slate-400 px-1.5 py-0.5 rounded uppercase tracking-widest"><?= htmlspecialchars($set['hymnal']) ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="w-3/4 flex flex-col relative">
            <?php if (!$canEditPreludes): ?>
                <div class="absolute top-0 right-4 text-xs font-black uppercase tracking-widest text-slate-400 pointer-events-none z-10">Read Only Mode</div>
            <?php endif; ?>

            <?php if (!$activeSet): ?>
                <div class="flex-1 border-2 border-dashed border-slate-300 rounded-xl flex items-center justify-center text-slate-400 font-bold uppercase tracking-widest mt-8">
                    Select a Prelude Set to view
                </div>
            <?php else: ?>
                <div class="flex justify-between items-end mb-6 mt-2">
                    <div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-indigo-600 mb-1"><?= htmlspecialchars($activeSet['hymnal']) ?> Prelude Set</div>
                        <h2 class="text-4xl font-black text-slate-900 leading-none"><?= htmlspecialchars($activeSet['name']) ?></h2>
                    </div>
                    <?php if ($canEditPreludes): ?>
                        <button onclick="deleteSet(<?= $activeSet['id'] ?>)" class="text-xs font-bold text-red-600 hover:underline">Delete Set</button>
                    <?php endif; ?>
                </div>

                <div class="flex gap-6">

                    <?php if ($canEditPreludes): ?>
                    <div class="w-1/2 flex flex-col gap-6">
                        <div class="bg-white border border-slate-300 rounded-xl shadow-sm p-4 relative">
                            <h3 class="text-xs font-black text-slate-400 uppercase tracking-widest mb-3">Find Hymn</h3>
                            <input type="text" id="hymn-search" placeholder="Search Number, Title, or First Line..." class="w-full p-3 bg-slate-50 border border-slate-200 rounded-lg text-sm font-bold focus:border-indigo-500 focus:bg-white outline-none transition" onkeyup="searchHymns(this)">

                            <div id="search-results" class="mt-2 flex flex-col max-h-[350px] overflow-y-auto pr-1"></div>
                        </div>

                        <div class="bg-indigo-50 border border-indigo-100 rounded-xl shadow-sm p-4">
                            <h3 class="text-xs font-black text-indigo-400 uppercase tracking-widest mb-3">Numerical Suggestions</h3>
                            <div id="suggestions-box" class="flex flex-col gap-1">
                                <div class="text-xs font-bold text-indigo-300 italic">Select a hymn above to load surrounding numerical songs...</div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="<?= $canEditPreludes ? 'w-1/2' : 'w-full max-w-2xl' ?>">
                        <div class="bg-white border border-slate-300 rounded-xl shadow-sm p-4 h-full flex flex-col">
                            <div class="flex justify-between items-center border-b border-slate-200 pb-3 mb-3">
                                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Prelude Sequence</h3>
                                <?php if ($canEditPreludes): ?>
                                    <div class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Drag to Reorder</div>
                                <?php endif; ?>
                            </div>

                            <ul id="set-list" class="flex flex-col gap-2">
                                <?php foreach ($setItems as $item): ?>
                                    <li class="<?= $canEditPreludes ? 'list-item-drag' : '' ?> flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-lg group" <?= $canEditPreludes ? 'draggable="true"' : '' ?> data-id="<?= $item['id'] ?>">
                                        <div class="flex items-center gap-3 flex-1 min-w-0">
                                            <?php if ($canEditPreludes): ?>
                                                <div class="text-slate-300 cursor-grab shrink-0">⋮⋮</div>
                                            <?php endif; ?>
                                            <div class="font-black text-slate-800 text-sm flex-1 truncate">
                                                <?= htmlspecialchars($item['title']) ?>
                                            </div>
                                            <div class="text-indigo-600 font-black text-sm shrink-0 pr-2">
                                                #<?= htmlspecialchars($item['hymn_number']) ?>
                                            </div>
                                        </div>
                                        <?php if ($canEditPreludes): ?>
                                            <button onclick="removeItem(<?= $item['id'] ?>)" class="text-slate-300 hover:text-red-500 font-bold opacity-0 group-hover:opacity-100 transition shrink-0">&times;</button>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                                <?php if (empty($setItems)): ?>
                                    <div id="empty-state" class="text-center text-slate-400 text-sm font-bold mt-8">Set is empty. <?= $canEditPreludes ? 'Search to add songs.' : '' ?></div>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const canEditPreludes = <?= $canEditPreludes ? 'true' : 'false' ?>;
        const setId = <?= $activeSetId ? $activeSetId : 'null' ?>;
        const currentHymnal = "<?= $activeSet ? $activeSet['hymnal'] : '' ?>";

        async function createNewSet() {
            if (!canEditPreludes) return;
            const name = prompt("Enter a name for the new Prelude Set:");
            if (!name) return;
            const hymnal = prompt("Which hymnal? (NVB, SSH, NVR, MAJ):", "NVB");
            if (!hymnal || !['NVB', 'SSH', 'NVR', 'MAJ'].includes(hymnal.toUpperCase())) {
                alert("Invalid hymnal. Please enter NVB, SSH, NVR, or MAJ.");
                return;
            }

            let fd = new FormData();
            fd.append('action', 'create_set');
            fd.append('name', name);
            fd.append('hymnal', hymnal.toUpperCase());

            const res = await fetch('preludes.php', { method: 'POST', body: fd }).then(r => r.json());
            window.location.href = `?set=${res.id}`;
        }

        async function deleteSet(id) {
            if (!canEditPreludes) return;
            if(!confirm("Are you sure you want to delete this entire set?")) return;
            let fd = new FormData();
            fd.append('action', 'delete_set');
            fd.append('set_id', id);
            await fetch('preludes.php', { method: 'POST', body: fd });
            window.location.href = 'preludes.php';
        }

        // --- DOM RENDERER FOR SPEED ---
        function renderList(items) {
            const list = document.getElementById('set-list');
            list.innerHTML = '';

            if (items.length === 0) {
                list.innerHTML = `<div id="empty-state" class="text-center text-slate-400 text-sm font-bold mt-8">Set is empty. ${canEditPreludes ? 'Search to add songs.' : ''}</div>`;
                return;
            }

            items.forEach(item => {
                const li = document.createElement('li');
                li.className = (canEditPreludes ? 'list-item-drag ' : '') + 'flex items-center justify-between p-3 bg-slate-50 border border-slate-200 rounded-lg group';
                if (canEditPreludes) li.draggable = true;
                li.dataset.id = item.id;

                let dragHandle = canEditPreludes ? '<div class="text-slate-300 cursor-grab shrink-0">⋮⋮</div>' : '';
                let deleteBtn = canEditPreludes ? `<button onclick="removeItem(${item.id})" class="text-slate-300 hover:text-red-500 font-bold opacity-0 group-hover:opacity-100 transition shrink-0">&times;</button>` : '';

                li.innerHTML = `
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        ${dragHandle}
                        <div class="font-black text-slate-800 text-sm flex-1 truncate">
                            ${item.title}
                        </div>
                        <div class="text-indigo-600 font-black text-sm shrink-0 pr-2">
                            #${item.hymn_number}
                        </div>
                    </div>
                    ${deleteBtn}
                `;
                list.appendChild(li);
            });
        }

        // --- SEARCH ENGINE (ONLY IF EDITABLE) ---
        let searchTimeout;
        async function searchHymns(input) {
            if (!canEditPreludes) return;
            const query = input.value;
            const box = document.getElementById('search-results');

            if (query.trim().length < 1) {
                box.innerHTML = '';
                return;
            }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(async () => {
                try {
                    const resp = await fetch(`hymn_search.php?q=${encodeURIComponent(query)}`);
                    const allResults = await resp.json();

                    const results = allResults.filter(h => h[currentHymnal] && h[currentHymnal].toString().trim() !== '');

                    box.innerHTML = '';

                    if (results.length === 0) {
                        box.innerHTML = '<div class="text-xs text-slate-400 font-bold p-2 text-center mt-4">No hymns found in the ' + currentHymnal + ' hymnal.</div>';
                        return;
                    }

                    results.forEach(h => {
                        const div = document.createElement('div');
                        div.className = 'p-3 hover:bg-indigo-50 border-b border-slate-100 flex justify-between items-center group transition cursor-pointer';

                        const pdfBtn = h.pdf_url ? `<a href="${h.pdf_url}" target="_blank" onclick="event.stopPropagation()" class="px-2 py-1.5 bg-white hover:bg-indigo-500 hover:text-white rounded text-[9px] font-black tracking-wider text-indigo-500 transition shadow-sm border border-slate-200 uppercase whitespace-nowrap">View PDF</a>` : '';
                        const number = h[currentHymnal];

                        div.innerHTML = `
                            <div class="flex-1 pr-2 min-w-0">
                                <div class="flex justify-between items-start mb-1">
                                    <strong class="text-sm text-slate-800 leading-tight pr-2 truncate">${h.Name}</strong>
                                    <span class="text-indigo-600 font-black text-sm leading-tight shrink-0">#${number}</span>
                                </div>
                                <div>
                                    <span class="text-[10px] text-slate-500">NVB:${h.NVB || '-'} | SSH:${h.SSH || '-'} | NVR:${h.NVR || '-'} | MAJ:${h.MAJ || '-'}</span><br>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0 ml-2">
                                ${pdfBtn}
                                <button class="text-indigo-600 font-black text-lg leading-none hover:scale-110 px-3 py-1 bg-indigo-100 rounded" title="Add to Prelude">+</button>
                            </div>
                        `;
                        div.onclick = () => {
                            addItem(number, h.Name);
                            loadSuggestions(number);
                            input.value = '';
                            box.innerHTML = '';
                        };
                        box.appendChild(div);
                    });
                } catch (e) {
                    console.error("Search Error:", e);
                }
            }, 300);
        }

        // --- NEAREST SUGGESTIONS (ONLY IF EDITABLE) ---
        function createSuggestionButton(hymn) {
            const btn = document.createElement('button');
            btn.className = 'w-full text-left p-2 bg-white hover:bg-indigo-600 hover:text-white border border-indigo-100 rounded shadow-sm transition flex gap-3 items-center group';
            btn.innerHTML = `
                <span class="font-bold text-sm truncate flex-1">${hymn.title}</span>
                <span class="font-black text-indigo-500 group-hover:text-indigo-200 shrink-0">#${hymn.number}</span>
            `;
            btn.onclick = () => {
                addItem(hymn.number, hymn.title);
                loadSuggestions(hymn.number);
            };
            return btn;
        }

        async function loadSuggestions(baseNumber) {
            if (!canEditPreludes) return;
            const box = document.getElementById('suggestions-box');
            box.innerHTML = '<div class="text-xs text-indigo-300 font-bold italic">Loading nearby hymns...</div>';

            try {
                const res = await fetch(`preludes.php?ajax=nearest&number=${baseNumber}&hymnal=${currentHymnal}`).then(r => r.json());
                box.innerHTML = '';

                if (res.before && res.before.length > 0) {
                    box.innerHTML += '<div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-2 mb-1 pl-1">Preceding Hymns</div>';
                    res.before.forEach(hymn => box.appendChild(createSuggestionButton(hymn)));
                }

                if (res.after && res.after.length > 0) {
                    box.innerHTML += '<div class="text-[10px] font-black text-slate-400 uppercase tracking-widest mt-4 mb-1 pl-1">Following Hymns</div>';
                    res.after.forEach(hymn => box.appendChild(createSuggestionButton(hymn)));
                }
            } catch (e) {
                box.innerHTML = '<div class="text-xs text-slate-400 font-bold p-2 text-center mt-4">Error loading suggestions.</div>';
            }
        }

        // --- FAST ITEM MANAGEMENT ---
        async function addItem(number, title) {
            if (!canEditPreludes) return;
            let fd = new FormData();
            fd.append('action', 'add_item');
            fd.append('set_id', setId);
            fd.append('number', number);
            fd.append('title', title);

            const res = await fetch('preludes.php', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') renderList(res.items);
        }

        async function removeItem(id) {
            if (!canEditPreludes) return;
            let fd = new FormData();
            fd.append('action', 'remove_item');
            fd.append('set_id', setId);
            fd.append('item_id', id);

            const res = await fetch('preludes.php', { method: 'POST', body: fd }).then(r => r.json());
            if (res.status === 'success') renderList(res.items);
        }

        // --- NATIVE DRAG AND DROP REORDERING ---
        const list = document.getElementById('set-list');
        let draggedItem = null;

        if (list && canEditPreludes) {
            list.addEventListener('dragstart', e => {
                draggedItem = e.target.closest('li');
                if(draggedItem) setTimeout(() => draggedItem.classList.add('ghost-drop'), 0);
            });

            list.addEventListener('dragend', async e => {
                if(!draggedItem) return;
                draggedItem.classList.remove('ghost-drop');

                const items = [...list.querySelectorAll('li.list-item-drag')];
                const orderMap = items.map(li => li.dataset.id);

                let fd = new FormData();
                fd.append('action', 'update_order');
                fd.append('set_id', setId);
                fd.append('order', JSON.stringify(orderMap));

                const res = await fetch('preludes.php', { method: 'POST', body: fd }).then(r => r.json());
                if (res.status === 'success') renderList(res.items);

                draggedItem = null;
            });

            list.addEventListener('dragover', e => {
                e.preventDefault();
                if(!draggedItem) return;

                const afterElement = getDragAfterElement(list, e.clientY);
                const li = e.target.closest('li.list-item-drag');
                if (!li || li === draggedItem) return;

                if (afterElement == null) {
                    list.appendChild(draggedItem);
                } else {
                    list.insertBefore(draggedItem, afterElement);
                }
            });
        }

        function getDragAfterElement(container, y) {
            const draggableElements = [...container.querySelectorAll('li.list-item-drag:not(.ghost-drop)')];
            return draggableElements.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset: offset, element: child };
                } else {
                    return closest;
                }
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
    </script>
</body>
</html>