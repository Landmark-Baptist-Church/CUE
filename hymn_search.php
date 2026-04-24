<?php
require 'db.php';
header('Content-Type: application/json');

$q = $_GET['q'] ?? '';
if (strlen($q) < 1) { echo json_encode([]); exit; }

function getPdfUrl($h, $searchedNumber = null) {
    $hymnals = ['NVB', 'SSH', 'NVR'];
    $bestH = null; $bestN = null;

    if ($searchedNumber) {
        foreach($hymnals as $hym) {
            if ((string)$h[$hym] === (string)$searchedNumber) {
                $bestH = $hym; $bestN = $searchedNumber; break;
            }
        }
    }

    if (!$bestH) {
        foreach($hymnals as $hym) {
            if (!empty($h[$hym])) {
                $bestH = $hym; $bestN = $h[$hym]; break;
            }
        }
    }

    if ($bestH && $bestN) {
        // Much simpler now! Just hymnal folder + hymnal-number.pdf
        return "{$bestH}/{$bestH}-{$bestN}.pdf";
    }
    return null;
}

$isNum = is_numeric($q);
$results = [];

if ($isNum) {
    $stmt = $db->prepare("SELECT * FROM hymns WHERE NVB = :q OR SSH = :q OR NVR = :q OR MAJ = :q LIMIT 20");
    $stmt->execute([':q' => $q]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Dynamically map input to Category Boolean Columns
    $cats = ['Blood', 'Christmas', 'Cross', 'Easter', 'Grace', 'Heaven', 'Holy_Spirit', 'Invitation', 'Missions', 'Patriotic', 'Prayer', 'Salvation', 'Second_Coming', 'Bible', 'Calvary', 'Great_Hymns', 'Service', 'Thanksgiving', 'Assurance', 'Christian_Warfare', 'Comfort_Guidance', 'Consecration', 'Consolation', 'Faith_Trust', 'Joy_Singing', 'Love', 'Praise', 'Resurrection', 'Soul_Winning_Service', 'Testimony'];
    $catSql = "";
    $qLower = strtolower(str_replace(' ', '_', $q));

    foreach($cats as $c) {
        if (strtolower($c) === $qLower) {
            $catSql = " OR {$c} = '1' OR {$c} = 1 ";
            break;
        }
    }

    // Search Title OR First Line OR exact Category Match
    $stmt = $db->prepare("SELECT * FROM hymns WHERE Name LIKE :q OR First_Line LIKE :q {$catSql} LIMIT 20");
    $stmt->execute([':q' => "%$q%"]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Append the PDF URL to the payload
$out = [];
foreach($results as $h) {
    $h['pdf_url'] = getPdfUrl($h, $isNum ? $q : null);
    $out[] = $h;
}

echo json_encode($out);