<?php
// init.php - Initialize and Update the SQLite Database Schema

require 'db.php'; // Pulls in the $db connection

echo "<div style='font-family: sans-serif; max-width: 800px; margin: 40px auto; line-height: 1.6;'>";
echo "<h2 style='color: #4f46e5;'>Cue Database Initialization</h2>";

try {
    // ---------------------------------------------------------
    // 1. PEOPLE, GROUPS & ROSTERS
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS people (id INTEGER PRIMARY KEY AUTOINCREMENT, first_name TEXT, last_name TEXT, is_pianist INTEGER DEFAULT 0, email TEXT, phone TEXT)");
    try { $db->exec("ALTER TABLE people ADD COLUMN first_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE people ADD COLUMN last_name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE people ADD COLUMN email TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE people ADD COLUMN phone TEXT"); } catch (Exception $e) {}
    echo "✅ People table verified.<br>";

    $db->exec("CREATE TABLE IF NOT EXISTS groups (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, group_type TEXT)");
    try { $db->exec("ALTER TABLE groups ADD COLUMN name TEXT"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE groups ADD COLUMN group_type TEXT"); } catch (Exception $e) {}
    echo "✅ Groups table verified.<br>";

    $db->exec("CREATE TABLE IF NOT EXISTS group_members (group_id INTEGER, person_id INTEGER, is_pianist INTEGER DEFAULT 0, PRIMARY KEY(group_id, person_id))");
    try { $db->exec("ALTER TABLE group_members ADD COLUMN is_pianist INTEGER DEFAULT 0"); } catch (Exception $e) {}
    echo "✅ Group Members table verified.<br>";

    // ---------------------------------------------------------
    // 2. EMAIL LISTS
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS email_lists (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS email_list_members (list_id INTEGER, person_id INTEGER, PRIMARY KEY(list_id, person_id))");
    echo "✅ Email Lists tables verified.<br>";

    // ---------------------------------------------------------
    // 3. SERVICES & CUE CARDS
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS services (id INTEGER PRIMARY KEY AUTOINCREMENT, service_date TEXT, service_time TEXT, service_type TEXT)");

    $db->exec("CREATE TABLE IF NOT EXISTS service_items (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER, item_type TEXT, sort_order INTEGER, label TEXT, supplemental_info TEXT, main_text TEXT, hymn_id INTEGER, group_id INTEGER, prelude_set_id INTEGER, text_color_override TEXT DEFAULT '#000000')");
    try { $db->exec("ALTER TABLE service_items ADD COLUMN text_color_override TEXT DEFAULT '#000000'"); } catch (Exception $e) {}
    echo "✅ Services & Cue Card items verified.<br>";

    // ---------------------------------------------------------
    // 4. TEMPLATES
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS template_items (id INTEGER PRIMARY KEY AUTOINCREMENT, template_id INTEGER, item_type TEXT, label TEXT, sort_order INTEGER, text_color_override TEXT DEFAULT '#000000')");
    try { $db->exec("ALTER TABLE template_items ADD COLUMN text_color_override TEXT DEFAULT '#000000'"); } catch (Exception $e) {}
    echo "✅ Templates tables verified.<br>";

    // ---------------------------------------------------------
    // 5. MASTER SCHEDULE & META
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS type_colors (item_type TEXT PRIMARY KEY, bg_color TEXT, border_color TEXT, text_color TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS service_capacities (service_type TEXT PRIMARY KEY, max_specials INTEGER DEFAULT 1)");
    $db->exec("CREATE TABLE IF NOT EXISTS choir_schedule (id_key TEXT PRIMARY KEY, text_value TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS scheduled_specials (id INTEGER PRIMARY KEY AUTOINCREMENT, service_date TEXT, service_type TEXT, item_type TEXT, group_id INTEGER, schedule_name TEXT DEFAULT 'Main', main_text TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS monthly_settings (month_year TEXT PRIMARY KEY, chorus_hymn_id INTEGER)");
    echo "✅ Master Schedule and Configuration tables verified.<br>";

    // ---------------------------------------------------------
    // 6. PRELUDES
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS prelude_sets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, hymnal TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS prelude_items (id INTEGER PRIMARY KEY AUTOINCREMENT, set_id INTEGER, hymn_number INTEGER, title TEXT, sort_order INTEGER)");
    echo "✅ Prelude Builder tables verified.<br>";

    // ---------------------------------------------------------
    // 7. HYMNS DATABASE
    // ---------------------------------------------------------
    $db->exec("CREATE TABLE IF NOT EXISTS hymns (
        Name TEXT,
        OOS_Verses TEXT,
        OOS TEXT,
        Total_Verses TEXT,
        Verses_to_Sing TEXT,
        Familiarity TEXT,
        Service_Index TEXT,
        Key TEXT,
        NVB TEXT,
        NVB_Scan TEXT,
        NVB_URL TEXT,
        SSH TEXT,
        SSH_Scan TEXT,
        SSH_URL TEXT,
        MAJ TEXT,
        NVR TEXT,
        NVR_Scan TEXT,
        NVR_URL TEXT,
        Date_of_Most_Recent_Use TEXT,
        First_Line TEXT,
        Notes TEXT,
        ID TEXT PRIMARY KEY,
        Blood INTEGER, Christmas INTEGER, Cross INTEGER, Easter INTEGER,
        Grace INTEGER, Heaven INTEGER, Holy_Spirit INTEGER, Invitation INTEGER,
        Missions INTEGER, Patriotic INTEGER, Prayer INTEGER, Salvation INTEGER,
        Second_Coming INTEGER, Bible INTEGER, Calvary INTEGER, Great_Hymns INTEGER,
        Service INTEGER, Thanksgiving INTEGER, Assurance INTEGER, Christian_Warfare INTEGER,
        Comfort_Guidance INTEGER, Consecration INTEGER, Consolation INTEGER,
        Faith_Trust INTEGER, Joy_Singing INTEGER, Love INTEGER, Praise INTEGER,
        Resurrection INTEGER, Soul_Winning_Service INTEGER, Testimony INTEGER
    )");
    echo "✅ Hymns table verified.<br>";

    echo "<div style='margin-top: 30px; padding: 20px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; color: #166534;'>";
    echo "<h3 style='margin-top: 0;'>🎉 Initialization Complete!</h3>";
    echo "<p>Your SQLite database is fully updated and ready to go.</p>";
    echo "<a href='index.php' style='display: inline-block; background: #16a34a; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go to Dashboard</a>";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='margin-top: 30px; padding: 20px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; color: #991b1b;'>";
    echo "<h3 style='margin-top: 0;'>❌ Initialization Failed</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
}

echo "</div>";
?>