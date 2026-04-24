<?php
require 'db.php';

$id = $_GET['service_id'] ?? die('No Service Selected');

$stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
$stmt->execute([$id]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    die('Service not found.');
}

// Fetch Roles from the Master Schedule (choir_schedule)
$dateStr = $service['service_date'];
$type = $service['service_type'];
$metaKeys = ["pianist_{$dateStr}_{$type}", "offertory_{$dateStr}_{$type}", "opener_{$dateStr}_{$type}", "special_{$dateStr}_{$type}"];
$inQuery = implode(',', array_fill(0, count($metaKeys), '?'));
$metaStmt = $db->prepare("SELECT id_key, text_value FROM choir_schedule WHERE id_key IN ($inQuery)");
$metaStmt->execute($metaKeys);
$metaData = [];
foreach($metaStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $metaData[$row['id_key']] = $row['text_value'];
}

$pia = trim($metaData["pianist_{$dateStr}_{$type}"] ?? '');
$off = trim($metaData["offertory_{$dateStr}_{$type}"] ?? '');
$opn = trim($metaData["opener_{$dateStr}_{$type}"] ?? '');
$spc = trim($metaData["special_{$dateStr}_{$type}"] ?? '');

// Fetch the standard Cue Card items
$stmt = $db->prepare("SELECT * FROM service_items WHERE service_id = ? ORDER BY sort_order ASC");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch custom color mappings
$customColors = [];
try {
    foreach($db->query("SELECT * FROM type_colors") as $row) {
        $customColors[$row['item_type']] = $row;
    }
} catch (Exception $e) {} // Failsafe if table is empty

// Layout Logic: Two-Up for <= 20, Single Flowing Page for > 20
$itemCount = count($items);
if ($itemCount <= 20) {
    $cardsPerPage = 2; // Two copies on one page
    $cardStyle = "height: 5.5in; overflow: hidden;"; // Hard cutoff
} else {
    $cardsPerPage = 1; // One copy that flows natively
    $cardStyle = "min-height: 10.5in; height: auto; page-break-inside: auto;";
}

$compactClass = $itemCount > 10 ? 'compact-mode' : '';

function getPdfUrl($h) {
    $hymnals = ['NVB', 'SSH', 'NVR'];
    $bestH = null; $bestN = null;
    foreach($hymnals as $hym) {
        if (!empty($h[$hym])) {
            $bestH = $hym; $bestN = $h[$hym]; break;
        }
    }
    if ($bestH && $bestN) {
        return "{$bestH}/{$bestH}-{$bestN}.pdf";
    }
    return null;
}

function resolve($item, $db, $customColors) {
    $c1 = $item['label'] ?: $item['item_type'];
    $c2 = $item['supplemental_info'] ?? '';
    $c3 = $item['main_text'] ?? '';
    $c3_html = null;
    $c4 = '';

    // Default Color Logic
    $musicTypes = ['Hymn', 'Prelude', 'Choir Special', 'Special Music'];
    $speakingTypes = ['Welcome', 'Prayer', 'Message', 'Announcements', 'Introduction', 'Dismissal'];

    $bg = '#f8fafc'; $border = '#64748b'; $text = '#475569';
    if (in_array($item['item_type'], $musicTypes)) { $bg = '#eff6ff'; $border = '#3b82f6'; $text = '#1e40af'; }
    elseif (in_array($item['item_type'], $speakingTypes)) { $bg = '#f0fdf4'; $border = '#22c55e'; $text = '#166534'; }

    // Apply User Configured Overrides
    if (isset($customColors[$item['item_type']])) {
        $bg = $customColors[$item['item_type']]['bg_color'];
        $border = $customColors[$item['item_type']]['border_color'];
        $text = $customColors[$item['item_type']]['text_color'];
    }

    // Emergency Row-Level Text Color Override
    $textOverride = null;
    if (!empty($item['text_color_override']) && $item['text_color_override'] !== '#000000') {
        $textOverride = $item['text_color_override'];
    }

    switch ($item['item_type']) {
        case 'Hymn':
            if ($item['hymn_id']) {
                $h = $db->prepare("SELECT Name, NVB, SSH, NVR, Verses_to_Sing, OOS, Key FROM hymns WHERE ID = ?");
                $h->execute([$item['hymn_id']]);
                $res = $h->fetch(PDO::FETCH_ASSOC);

                $c2 = $item['supplemental_info'] ?: ($res['Verses_to_Sing'] ?? '');
                $displayText = $item['main_text'] ?: (($res['Name'] ?? '') . " (#" . ($res['NVB'] ?? '') . ") - " . ($res['OOS'] ?? ''));

                $pdfUrl = getPdfUrl($res);
                if ($pdfUrl) {
                    $c3_html = "<a href='" . htmlspecialchars($pdfUrl, ENT_QUOTES) . "' target='_blank' style='text-decoration: none; color: inherit;' title='Click to view PDF'>" . htmlspecialchars($displayText) . "</a>";
                } else {
                    $c3 = $displayText;
                }

                $c4 = $res['Key'] ?? '';
            }
            break;
        case 'Welcome': case 'Prayer': case 'Message': case 'Announcements': case 'Introduction':
            $c2 = ""; $c3 = $item['main_text']; break;
        case 'Dismissal':
            $c2 = $item['supplemental_info']; $c3 = ""; break;
        case 'Choir Special':
            $c2 = ""; $c3 = $item['main_text']; break;
        case 'Special Music':
            if ($item['group_id']) {
                $g = $db->prepare("SELECT name FROM groups WHERE id = ?");
                $g->execute([$item['group_id']]);
                $groupName = $g->fetchColumn();

                $c2 = $item['supplemental_info'];
                $c3 = $groupName; // Fallback

                $mStmt = $db->prepare("
                    SELECT p.first_name, p.last_name, gm.is_pianist
                    FROM group_members gm
                    JOIN people p ON gm.person_id = p.id
                    WHERE gm.group_id = ?
                    ORDER BY gm.is_pianist DESC, p.last_name ASC, p.first_name ASC
                ");
                $mStmt->execute([$item['group_id']]);
                $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($members)) {
                    $singers = [];
                    $pianists = [];
                    foreach($members as $m) {
                        $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                        if (!empty($m['is_pianist'])) {
                            $pianists[] = $name . " (Pianist)";
                        } else {
                            $singers[] = $name;
                        }
                    }

                    $allNames = array_merge($singers, $pianists);
                    $memberString = implode(', ', $allNames);

                    $c3_html = "<div style='line-height: 1.15; padding: 2px 0;'>
                                    <div>" . htmlspecialchars($groupName) . "</div>
                                    <div style='font-size: 8.5pt; font-weight: 500; color: " . ($textOverride ?? "#475569") . "; margin-top: 2px;'>" . htmlspecialchars($memberString) . "</div>
                                </div>";
                }
            }
            break;
        case 'Prelude':
            if (!empty($item['prelude_set_id'])) {
                // Fetch the Set Info
                $sStmt = $db->prepare("SELECT name, hymnal FROM prelude_sets WHERE id = ?");
                $sStmt->execute([$item['prelude_set_id']]);
                $setInfo = $sStmt->fetch(PDO::FETCH_ASSOC);

                if ($setInfo) {
                    $c2 = $setInfo['name']; // Middle Column

                    // Fetch the Numbers perfectly ordered
                    $iStmt = $db->prepare("SELECT hymn_number FROM prelude_items WHERE set_id = ? ORDER BY sort_order ASC");
                    $iStmt->execute([$item['prelude_set_id']]);
                    $nums = $iStmt->fetchAll(PDO::FETCH_COLUMN);

                    if (!empty($nums)) {
                        $c3 = $setInfo['hymnal'] . ": " . implode(', ', $nums); // Right Column
                    }
                }
            }
            break;
    }

    return [
        'c1' => $c1, 'c2' => $c2, 'c3' => $c3, 'c3_html' => $c3_html, 'c4' => $c4,
        'bg' => $bg, 'border' => $border, 'text' => $text, 'textOverride' => $textOverride
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cue Card Print - <?= date('m/d', strtotime($service['service_date'])) ?></title>
    <style>
        body {
            margin: 0; padding: 20px; background: #e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        .page { background: white; width: 8.5in; margin: 0 auto; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }

        .cue-card {
            <?= $cardStyle ?>
            width: 8.5in; border-bottom: 2px dashed #94a3b8;
            padding: 0.25in 0.4in; box-sizing: border-box;
        }
        .cue-card:nth-child(<?= $cardsPerPage ?>n) { border-bottom: none; }

        .header-row {
            display: flex; justify-content: space-between; align-items: baseline;
            border-bottom: 3px solid black; padding-bottom: 0.05in; margin-bottom: 0.05in;
        }
        .header-title { font-size: 16pt; font-weight: 900; text-transform: uppercase; letter-spacing: -0.5px; }
        .header-date { font-size: 11pt; font-weight: bold; color: #333; }

        .meta-row {
            display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 0.15in; padding-bottom: 0.08in; border-bottom: 1px solid #cbd5e1;
        }
        .meta-badge {
            font-weight: 900; font-size: 7pt; margin-right: 5px; letter-spacing: 0.05em;
            padding: 2px 4px; border-radius: 3px;
        }
        .meta-item {
            font-size: 10pt; font-weight: 700; color: #334155; display: flex; align-items: center;
        }

        /* WIDENED MIDDLE COLUMN: 1.5in */
        .grid-container { display: grid; grid-template-columns: minmax(1.4in, 1.8in) 1.5in 1fr max-content; border-top: 1px solid black; }
        .row { display: contents; }

        .cell { padding: 5px 8px; border-bottom: 1px solid #cbd5e1; display: flex; align-items: center; }

        .c1 {
            font-weight: 800; text-transform: uppercase; font-size: 9.5pt;
            border-left: 4px solid transparent; border-right: 1px solid black;
            line-height: 1.1; overflow-wrap: break-word; hyphens: auto;
        }

        .c2 { font-size: 10pt; font-weight: 600; color: #334155; }
        .c3 { font-size: 11pt; font-weight: 700; color: black; line-height: 1.2; padding-right: 5px; word-break: break-word; border-right: 1px solid black; }
        .c4 { font-size: 10pt; font-weight: 400; color: #16a34a; font-family: monospace; padding-left: 10px; text-align: right; }

        .compact-mode .cell { padding: 3px 8px; }
        .compact-mode .c1 { font-size: 8.5pt; }
        .compact-mode .c2 { font-size: 9pt; }
        .compact-mode .c3 { font-size: 10pt; }

        .section-break-row {
            grid-column: 1 / -1;
            border-bottom: 2.5px solid black;
            margin: 4px 0;
        }

        @media screen {
            .c3 a:hover { text-decoration: underline !important; color: inherit; opacity: 0.8; }
        }

        @media print { body { background: none; padding: 0; } .page { box-shadow: none; } @page { size: letter; margin: 0; } }
    </style>
</head>
<body>
    <div class="page <?= $compactClass ?>">
        <?php for($i=0; $i<$cardsPerPage; $i++): ?>
            <div class="cue-card">

                <div class="header-row">
                    <div class="header-title"><?= htmlspecialchars($service['service_type']) ?></div>
                    <div class="header-date"><?= date('l, M j, Y', strtotime($service['service_date'])) ?> <?= htmlspecialchars($service['service_time'] ?? '') ?></div>
                </div>

                <?php if ($pia || $off || $opn || $spc): ?>
                    <div class="meta-row">
                        <?php if ($pia): ?>
                            <div class="meta-item"><span class="meta-badge" style="background-color: #dbeafe; color: #1d4ed8;">PIA</span> <?= htmlspecialchars($pia) ?></div>
                        <?php endif; ?>
                        <?php if ($off): ?>
                            <div class="meta-item"><span class="meta-badge" style="background-color: #d1fae5; color: #047857;">OFF</span> <?= htmlspecialchars($off) ?></div>
                        <?php endif; ?>
                        <?php if ($opn): ?>
                            <div class="meta-item"><span class="meta-badge" style="background-color: #fee2e2; color: #b91c1c;">OPN</span> <?= htmlspecialchars($opn) ?></div>
                        <?php endif; ?>
                        <?php if ($spc): ?>
                            <div class="meta-item"><span class="meta-badge" style="background-color: #f3e8ff; color: #7e22ce;">SPC</span> <?= htmlspecialchars($spc) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="grid-container">
                    <?php foreach($items as $item): ?>

                        <?php if ($item['item_type'] === 'Section Break'): ?>
                            <div class="section-break-row"></div>

                        <?php else: $cols = resolve($item, $db, $customColors);
                            $textStyle = $cols['textOverride'] ? "color: {$cols['textOverride']} !important;" : "";
                        ?>
                            <div class="row">
                                <div class="cell c1" style="background-color: <?= $cols['bg'] ?>; border-left-color: <?= $cols['border'] ?>; color: <?= $cols['text'] ?>;">
                                    <?= htmlspecialchars($cols['c1'] ?? '') ?>
                                </div>

                                <div class="cell c2" style="<?= $textStyle ?>"><?= htmlspecialchars($cols['c2'] ?? '') ?></div>
                                <div class="cell c3" style="<?= $textStyle ?>"><?= $cols['c3_html'] ?? htmlspecialchars($cols['c3'] ?? '') ?></div>
                                <div class="cell c4"><?= htmlspecialchars($cols['c4'] ?? '') ?></div>
                            </div>
                        <?php endif; ?>

                    <?php endforeach; ?>
                </div>
            </div>
        <?php endfor; ?>
    </div>
</body>
</html>