<?php
require_once 'db.php';

// 1. Hyper-Safe Variable Extraction
$monthKey = isset($_GET['month']) ? trim($_GET['month']) : date('Y-m');
if (strlen($monthKey) !== 7) { $monthKey = date('Y-m'); }

$schedule = isset($_GET['schedule']) ? trim($_GET['schedule']) : 'Main';

$parts = explode('-', $monthKey);
$year = isset($parts[0]) ? (int)$parts[0] : (int)date('Y');
$monthNum = isset($parts[1]) ? (int)$parts[1] : (int)date('m');

// 2. Hyper-Safe Date Math
$baseDateStr = sprintf("%04d-%02d-01", $year, $monthNum);
$baseTimestamp = strtotime($baseDateStr);
if (!$baseTimestamp) { $baseTimestamp = time(); }

$monthName = date('F', $baseTimestamp);
$daysInMonth = (int)date('t', $baseTimestamp);
$firstDayOfWeek = (int)date('w', $baseTimestamp);

// 3. Initialize Arrays
$specials = [];
$groupsData = [];
$serviceMeta = [];

// 4. Silent Database Fetching
try {
    $stmt = $db->prepare("SELECT * FROM scheduled_specials WHERE service_date LIKE ? AND schedule_name = ? ORDER BY service_type ASC");
    $stmt->execute(["$monthKey-%", $schedule]);
    $specials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

try {
    $gStmt = $db->query("SELECT id, name FROM groups");
    if ($gStmt) {
        foreach($gStmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $groupsData[$g['id']] = $g['name'];
        }
    }
} catch (Exception $e) {}

try {
    $mStmt = $db->query("SELECT * FROM choir_schedule");
    if ($mStmt) {
        foreach($mStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $serviceMeta[$row['id_key']] = $row['text_value'];
        }
    }
} catch (Exception $e) {}

// 5. Organize Specials
$planned = [];
foreach ($specials as $s) {
    $date = isset($s['service_date']) ? (string)$s['service_date'] : '';
    $type = isset($s['service_type']) ? (string)$s['service_type'] : '';

    if ($date === '' || $type === '') continue;

    if (!isset($planned[$date])) $planned[$date] = [];
    if (!isset($planned[$date][$type])) $planned[$date][$type] = [];

    $groupId = isset($s['group_id']) ? (int)$s['group_id'] : 0;
    if ($groupId > 0 && isset($groupsData[$groupId])) {
        $planned[$date][$type][] = $groupsData[$groupId];
    }
}

// 6. DYNAMIC COLUMN ALGORITHM
$activeDaysOfWeek = [0]; // Sunday is ALWAYS visible
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = sprintf("%04d-%02d-%02d", $year, $monthNum, $d);
    $dow = (int)date('w', strtotime($dateStr));

    $hasMarker = !empty($serviceMeta["marker_$dateStr"]);
    $hasEvents = !empty($planned[$dateStr]);

    if ($hasMarker || $hasEvents) {
        if (!in_array($dow, $activeDaysOfWeek)) {
            $activeDaysOfWeek[] = $dow;
        }
    }
}
sort($activeDaysOfWeek);

$gridColumns = [];
foreach ($activeDaysOfWeek as $dow) {
    if ($dow == 0) {
        $gridColumns[] = '2fr'; // Sunday massive footprint
    } else {
        $gridColumns[] = '1fr';
    }
}
$gridTemplateColumns = implode(' ', $gridColumns);

// 7. Build Calendar Matrix
$grid = [];
$currentWeek = array_fill(0, 7, null);
$dayCounter = 1;

for ($i = $firstDayOfWeek; $i < 7; $i++) {
    $currentWeek[$i] = $dayCounter++;
}
$grid[] = $currentWeek;

while ($dayCounter <= $daysInMonth) {
    $currentWeek = array_fill(0, 7, null);
    for ($i = 0; $i < 7 && $dayCounter <= $daysInMonth; $i++) {
        $currentWeek[$i] = $dayCounter++;
    }
    $grid[] = $currentWeek;
}

$altKey = "alternate_$monthKey";
$altId = isset($serviceMeta[$altKey]) ? $serviceMeta[$altKey] : null;
$altName = ($altId && isset($groupsData[$altId])) ? $groupsData[$altId] : null;

$weeksCount = count($grid);
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars((string)$monthName) ?> <?= htmlspecialchars((string)$year) ?> Schedule</title>
    <style>
        @page { size: letter landscape; margin: 0.25in; }

        * { box-sizing: border-box; }

        /* THE AUTO-SQUISH CSS VARIABLES */
        :root { --scale: 1; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0; padding: 0; color: #0f172a;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
            height: 100vh; display: flex; flex-direction: column; overflow: hidden;
        }

        .header-wrap {
            flex-shrink: 0; display: flex; justify-content: space-between; align-items: flex-end;
            border-bottom: 3px solid #0f172a; padding-bottom: 4px; margin-bottom: 6px;
        }
        .title { font-size: 20pt; font-weight: 900; letter-spacing: -0.5px; line-height: 1; text-transform: uppercase; color: #0f172a; }
        .subtitle { font-size: 9pt; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 2px; }
        .alt-group { font-size: 9pt; font-weight: 800; color: #dc2626; text-transform: uppercase; letter-spacing: 0.5px; }

        .grid-container {
            flex: 1; display: grid;
            grid-template-columns: <?= $gridTemplateColumns ?>;
            /* ELASTIC ROWS: 'min-content' allows heavy weeks to grow and empty weeks to shrink */
            grid-template-rows: max-content repeat(<?= (int)$weeksCount ?>, minmax(min-content, 1fr));
            border-top: 2px solid #cbd5e1; border-left: 2px solid #cbd5e1;
            min-height: 0;
            /* This triggers the scrollHeight measurement if it overflows */
            overflow: hidden;
        }

        .header-cell {
            background: #f8fafc; text-align: center; font-weight: 800; text-transform: uppercase; color: #475569;
            border-right: 2px solid #cbd5e1; border-bottom: 2px solid #cbd5e1;
            /* Scaled properties */
            font-size: calc(7.5pt * var(--scale));
            padding: calc(4px * var(--scale));
            letter-spacing: calc(0.5px * var(--scale));
        }

        .day-cell {
            position: relative; border-right: 2px solid #cbd5e1; border-bottom: 2px solid #cbd5e1;
            background: #ffffff; display: flex; flex-direction: column; overflow: hidden;
            /* Scaled */
            padding: calc(3px * var(--scale));
        }
        .day-cell.empty { background: #f1f5f9; }

        .date-num {
            position: absolute; font-weight: 900; color: #94a3b8; line-height: 1; z-index: 10;
            /* Scaled */
            font-size: calc(11pt * var(--scale));
            top: calc(3px * var(--scale));
            right: calc(4px * var(--scale));
        }

        .day-content-wrapper {
            display: flex; flex-direction: column; flex: 1; min-height: 0;
            /* Scaled */
            gap: calc(2px * var(--scale));
            padding-top: calc(14px * var(--scale));
        }

        .marker {
            color: #dc2626; font-weight: 900; text-transform: uppercase; text-align: center; line-height: 1.1;
            /* Scaled */
            font-size: calc(7.5pt * var(--scale));
            margin-top: calc(-10px * var(--scale));
            margin-bottom: calc(4px * var(--scale));
        }

        .services-layout {
            display: flex; flex: 1; min-height: 0; overflow: hidden;
            /* Scaled */
            gap: calc(6px * var(--scale));
        }

        .layout-row { flex-direction: row; align-items: stretch; }
        .layout-row .svc-card { flex: 1; min-width: 0; }
        .layout-col { flex-direction: column; }

        .svc-card {
            background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px;
            display: flex; flex-direction: column; overflow: hidden;
            /* Scaled */
            padding: calc(4px * var(--scale));
            gap: calc(2px * var(--scale));
        }

        .svc-hdr {
            font-weight: 900; color: #475569; text-transform: uppercase; border-bottom: 1px solid #cbd5e1;
            /* Scaled */
            font-size: calc(6.5pt * var(--scale));
            padding-bottom: calc(1px * var(--scale));
            letter-spacing: calc(0.5px * var(--scale));
            margin-bottom: calc(2px * var(--scale));
        }

        .roles-wrap {
            background: #e2e8f0; border-bottom: 2px solid #cbd5e1; border-radius: 3px;
            display: flex; flex-wrap: wrap;
            /* Scaled */
            padding: calc(3px * var(--scale)) calc(4px * var(--scale));
            gap: calc(2px * var(--scale)) calc(6px * var(--scale));
            margin-bottom: calc(2px * var(--scale));
        }
        .role-item {
            display: flex; align-items: baseline; font-weight: 700; color: #1e293b; line-height: 1.1;
            /* Scaled */
            font-size: calc(7.5pt * var(--scale));
            gap: calc(3px * var(--scale));
        }

        .badge {
            font-weight: 900; color: #fff; border-radius: 2px; text-transform: uppercase;
            /* Scaled */
            font-size: calc(5pt * var(--scale));
            padding: calc(1px * var(--scale)) calc(3px * var(--scale));
            letter-spacing: calc(0.5px * var(--scale));
        }
        .b-pia { background: #2563eb; }
        .b-off { background: #059669; }
        .b-opn { background: #dc2626; }
        .b-spc { background: #7c3aed; }

        .c-line { display: flex; align-items: flex-start; background: #fef2f2; border-left: 2px solid #ef4444;
            /* Scaled */
            gap: calc(3px * var(--scale)); padding: calc(2px * var(--scale)) calc(3px * var(--scale)); margin-bottom: calc(1px * var(--scale));
        }
        .s-line { display: flex; align-items: flex-start; background: #f5f3ff; border-left: 2px solid #8b5cf6;
            /* Scaled */
            gap: calc(3px * var(--scale)); padding: calc(2px * var(--scale)) calc(3px * var(--scale)); margin-bottom: calc(2px * var(--scale));
        }
        .c-text {
            font-weight: 800; color: #0f172a; line-height: 1.1;
            /* Scaled */
            font-size: calc(7.5pt * var(--scale));
        }

        .sp-list { display: flex; flex-direction: column;
            /* Scaled */
            gap: calc(1px * var(--scale)); margin-top: calc(1px * var(--scale));
        }
        .sp-item { font-weight: 800; color: #0f172a; line-height: 1.1; display: flex;
            /* Scaled */
            font-size: calc(7.5pt * var(--scale)); gap: calc(3px * var(--scale));
        }
        .sp-bullet { color: #94a3b8; }

        @media print {
            body { height: 100vh; overflow: hidden; }
        }
    </style>
</head>
<body>

    <div class="header-wrap">
        <div>
            <div class="title"><?= htmlspecialchars((string)$monthName) ?> <?= htmlspecialchars((string)$year) ?></div>
            <div class="subtitle"><?= htmlspecialchars((string)$schedule) ?> Music Schedule</div>
        </div>
        <?php if ($altName): ?>
            <div class="alt-group">Alternate: <?= htmlspecialchars((string)$altName) ?></div>
        <?php endif; ?>
    </div>

    <div class="grid-container" id="schedule-grid">
        <?php foreach ($activeDaysOfWeek as $dow): ?>
            <div class="header-cell"><?= $dayNames[$dow] ?></div>
        <?php endforeach; ?>

        <?php foreach ($grid as $week): ?>
            <?php foreach ($activeDaysOfWeek as $dayOfWeek):
                $dayNum = $week[$dayOfWeek];
            ?>
                <?php if (!$dayNum): ?>
                    <div class="day-cell empty"></div>
                <?php else: ?>
                    <?php
                        $dateStr = sprintf("%04d-%02d-%02d", $year, $monthNum, $dayNum);
                        $markerKey = "marker_$dateStr";
                        $marker = isset($serviceMeta[$markerKey]) ? $serviceMeta[$markerKey] : null;
                    ?>
                    <div class="day-cell" data-day="<?= $dayOfWeek ?>">
                        <div class="date-num"><?= htmlspecialchars((string)$dayNum) ?></div>

                        <div class="day-content-wrapper">
                            <?php if ($marker): ?>
                                <div class="marker"><?= htmlspecialchars((string)$marker) ?></div>
                            <?php endif; ?>

                            <?php
                            $servicesToday = [];
                            if ($dayOfWeek == 0) $servicesToday = ['Sunday AM', 'Sunday PM'];
                            if ($dayOfWeek == 3) $servicesToday = ['Wednesday PM'];

                            if (isset($planned[$dateStr])) {
                                foreach (array_keys($planned[$dateStr]) as $t) {
                                    if (!in_array($t, $servicesToday)) $servicesToday[] = $t;
                                }
                            }
                            ?>

                            <?php if (!empty($servicesToday)): ?>
                                <div class="services-layout <?= ($dayOfWeek == 0) ? 'layout-row' : 'layout-col' ?>">
                                    <?php foreach ($servicesToday as $type):
                                        $openerKey = "opener_{$dateStr}_{$type}";
                                        $specialKey = "special_{$dateStr}_{$type}";
                                        $offKey = "offertory_{$dateStr}_{$type}";
                                        $piaKey = "pianist_{$dateStr}_{$type}";
                                        $showChoirKey = "show_choir_{$dateStr}_{$type}";

                                        $opener = isset($serviceMeta[$openerKey]) ? $serviceMeta[$openerKey] : '';
                                        $special = isset($serviceMeta[$specialKey]) ? $serviceMeta[$specialKey] : '';
                                        $offertory = isset($serviceMeta[$offKey]) ? $serviceMeta[$offKey] : '';
                                        $pianist = isset($serviceMeta[$piaKey]) ? $serviceMeta[$piaKey] : '';
                                        $specialsList = isset($planned[$dateStr][$type]) ? $planned[$dateStr][$type] : [];

                                        $hasChoirData = ($opener !== '' || $special !== '' || (isset($serviceMeta[$showChoirKey]) && $serviceMeta[$showChoirKey] == '1'));

                                        if (!in_array($type, ['Sunday AM', 'Sunday PM', 'Wednesday PM']) && empty($specialsList) && $offertory === '' && $pianist === '' && !$hasChoirData) {
                                            continue;
                                        }

                                        $displayTitle = (string)$type;
                                        if ($type === 'Sunday AM') $displayTitle = 'MORNING';
                                        elseif ($type === 'Sunday PM') $displayTitle = 'EVENING';
                                        elseif ($type === 'Wednesday PM') $displayTitle = 'MIDWEEK';
                                    ?>
                                        <div class="svc-card">
                                            <div class="svc-hdr"><?= htmlspecialchars($displayTitle) ?></div>

                                            <?php if ($pianist !== '' || $offertory !== ''): ?>
                                                <div class="roles-wrap">
                                                    <?php if ($pianist !== ''): ?>
                                                        <div class="role-item"><span class="badge b-pia">PIA</span> <?= htmlspecialchars((string)$pianist) ?></div>
                                                    <?php endif; ?>
                                                    <?php if ($offertory !== ''): ?>
                                                        <div class="role-item"><span class="badge b-off">OFF</span> <?= htmlspecialchars((string)$offertory) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($opener !== ''): ?>
                                                <div class="c-line">
                                                    <span class="badge b-opn">OPN</span>
                                                    <span class="c-text" style="color: #991b1b;"><?= htmlspecialchars((string)$opener) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($special !== ''): ?>
                                                <div class="s-line">
                                                    <span class="badge b-spc">SPC</span>
                                                    <span class="c-text" style="color: #5b21b6;"><?= htmlspecialchars((string)$special) ?></span>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($specialsList)): ?>
                                                <div class="sp-list">
                                                    <?php foreach ($specialsList as $sp): ?>
                                                        <div class="sp-item"><span class="sp-bullet">&bull;</span> <span><?= htmlspecialchars((string)$sp) ?></span></div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <script>
        window.addEventListener('load', function() {
            const grid = document.getElementById('schedule-grid');
            let currentScale = 1.0;

            // If the scrollHeight (content) is greater than clientHeight (box), it is overflowing.
            // We loop down by 2% until everything perfectly fits the page, down to a minimum of 50% scale.
            while (grid.scrollHeight > grid.clientHeight && currentScale > 0.5) {
                currentScale -= 0.02;
                document.documentElement.style.setProperty('--scale', currentScale);
            }

            // Automatically pop up the print dialog once scaling is finished
            setTimeout(() => { window.print(); }, 200);
        });
    </script>
</body>
</html>