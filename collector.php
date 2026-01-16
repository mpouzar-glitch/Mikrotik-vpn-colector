<?php
/**
 * VPN Stats Collector - FINAL VERSION
 */

// ===========================
// KONFIGURACE
// ===========================
$config = [
    // Email konfigurace
    'imap' => [
        'mailbox' => '{localhost:143/imap/notls}INBOX',  // Pridat INBOX na konec
        'username' => 'user@example.com',
        'password' => 'password',
    ],
    
    // Database konfigurace
    'db' => [
        'host' => 'localhost',
        'name' => 'vpn_stats',
        'user' => 'vpn_user',
        'password' => 'password',
        'charset' => 'utf8mb4',
    ],
    
    'cleanup' => [
        'enabled' => true,              // Povolit cisteni
        'method' => 'delete',           // 'delete' = smazat, 'trash' = presunout do kose, 'archive' = archivovat
        'trash_folder' => 'Trash',      // Nazev slozky pro kos
        'archive_folder' => 'Archive',  // Nazev slozky pro archiv
        'keep_days' => 0,               // Ponechat emaily novejsi nez X dni (0 = mazat vse)
    ],
    
    'debug' => true
];

date_default_timezone_set('Europe/Prague');

// ===========================
// FUNKCE PRO KONVERZI DATA
// ===========================
function convertMikrotikDate($dateStr) {
    // Konvertuje "jan/16/2026" na "2026-01-16"
    $months = array(
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'may' => '05', 'jun' => '06', 'jul' => '07', 'aug' => '08',
        'sep' => '09', 'oct' => '10', 'nov' => '11', 'dec' => '12'
    );
    
    $dateStr = trim(strtolower($dateStr));
    
    // Format: jan/16/2026
    $parts = explode('/', $dateStr);
    
    if (count($parts) == 3) {
        $month = $parts[0];
        $day = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $year = $parts[2];
        
        if (isset($months[$month])) {
            return $year . '-' . $months[$month] . '-' . $day;
        }
    }
    
    // Zkusit parsovat jako standardni datum
    $timestamp = strtotime($dateStr);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }
    
    // Fallback - dnesni datum
    return date('Y-m-d');
}

// ===========================
// PRIPOJENI K DB
// ===========================
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s',
        $config['db']['host'], $config['db']['name'], $config['db']['charset']);
    
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "[OK] Database connected\n";
} catch (PDOException $e) {
    die("[ERROR] Database: " . $e->getMessage() . "\n");
}

// ===========================
// MAILBOX
// ===========================
echo "[INFO] Connecting to mailbox\n";

$inbox = imap_open(
    $config['imap']['mailbox'],
    $config['imap']['username'],
    $config['imap']['password']
);

if (!$inbox) {
    die('[ERROR] Cannot connect: ' . imap_last_error() . "\n");
}

echo "[OK] Connected to mailbox\n";

$check = imap_check($inbox);
echo "[INFO] Messages: " . $check->Nmsgs . "\n";

// ===========================
// ZPRACOVANI
// ===========================
$emails = imap_search($inbox, 'ALL');

if ($emails) {
    echo "[INFO] Processing " . count($emails) . " emails\n\n";
    
    $stmt = $pdo->prepare("
        INSERT INTO vpn_connections (
            username, connect_date, connect_time, disconnect_date, disconnect_time,
            traffic_down_mb, traffic_up_mb, internal_traffic_mb,
            tx_packets, rx_packets, internal_tx_packets, internal_rx_packets,
            caller_ip, remote_ip, rx_drops, tx_drops, rx_errors, tx_errors, session_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $processedCount = 0;
    $emailsToDelete = array();
    
    foreach ($emails as $emailNumber) {
        try {
            $overview = imap_fetch_overview($inbox, $emailNumber, 0);
            $subject = isset($overview[0]->subject) ? $overview[0]->subject : '';
            
            if (stripos($subject, 'VPN') === false) {
                continue;
            }
            
            $message = imap_body($inbox, $emailNumber);
            $message = trim($message);
            
            $lines = explode("\n", $message);
            $foundData = false;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (strlen($line) == 0) continue;
                
                $data = str_getcsv($line);
                
                if (count($data) >= 15 && $data[0] == 'VPN_DISCONNECT') {
                    $username = trim($data[1]);
                    if (strlen($username) == 0) continue;
                    
                    // KONVERTOVAT DATA
                    $connectDate = convertMikrotikDate($data[2]);
                    $connectTime = $data[3];
                    $disconnectDate = convertMikrotikDate($data[4]);
                    $disconnectTime = $data[5];
                    
                    if ($config['debug']) {
                        echo "[DEBUG] Email #$emailNumber - User: $username\n";
                        echo "  Connect: {$data[2]} -> $connectDate $connectTime\n";
                        echo "  Disconnect: {$data[4]} -> $disconnectDate $disconnectTime\n";
                    }
                    
                    $stmt->execute(array(
                        $username,
                        $connectDate,
                        $connectTime,
                        $disconnectDate,
                        $disconnectTime,
                        floatval($data[6]),
                        floatval($data[7]),
                        floatval($data[8]),
                        intval($data[9]),
                        intval($data[10]),
                        intval($data[11]),
                        intval($data[12]),
                        $data[13],
                        $data[14],
                        isset($data[15]) ? intval($data[15]) : 0,
                        isset($data[16]) ? intval($data[16]) : 0,
                        isset($data[17]) ? intval($data[17]) : 0,
                        isset($data[18]) ? intval($data[18]) : 0,
                        isset($data[19]) ? intval($data[19]) : 0
                    ));
                    
                    $processedCount++;
                    $foundData = true;
                    echo "  [OK] Processed: $username ($connectDate to $disconnectDate)\n";
                }
            }
            
            if ($foundData) {
                $emailsToDelete[] = $emailNumber;
            }
            
        } catch (Exception $e) {
            echo "  [ERROR] " . $e->getMessage() . "\n";
        }
    }
    
    // Cleanup
    if ($config['cleanup']['enabled'] && count($emailsToDelete) > 0) {
        echo "\n[INFO] Deleting " . count($emailsToDelete) . " emails\n";
        
        foreach ($emailsToDelete as $num) {
            imap_delete($inbox, $num);
        }
        
        imap_expunge($inbox);
        echo "[OK] Deleted\n";
    }
    
    echo "\n========================================\n";
    echo "Processed: $processedCount records\n";
    echo "========================================\n";
    
} else {
    echo "[INFO] No emails\n";
}

imap_close($inbox);
echo "[DONE]\n";
?>

