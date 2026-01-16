<?php
/**
 * VPN Stats Collector - FINAL VERSION
 */

// ===========================
// KONFIGURACE
// ===========================
$config = [
    // Database konfigurace
    'db' => [
        'host' => 'localhost',
        'name' => 'vpn_stats',
        'user' => 'vpn_user',
        'password' => 'Milevsko25*',
        'charset' => 'utf8mb4',
    ],
    
    // Report konfigurace
    'report' => [
        'email' => 'pouzar@milnet.cz',
        'timezone' => 'Europe/Prague',
    ],
    
    'timezone' => 'Europe/Prague',
];

// Nastavit timezone - s fallback
$timezone = $config['timezone'];
if (empty($timezone)) {
    $timezone = 'Europe/Prague';
}

if (!date_default_timezone_set($timezone)) {
    date_default_timezone_set('Europe/Prague');
}

// ===========================
// ZPRACOVANI PARAMETRU
// ===========================
$reportDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$reportType = isset($_GET['type']) ? $_GET['type'] : 'daily';
$format = isset($_GET['format']) ? $_GET['format'] : 'html';

// Validace data
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $reportDate = date('Y-m-d');
}

// ===========================
// PRIPOJENI K DATABAZI
// ===========================
try {
    $pdo = new PDO(
        "mysql:host=" . $config['db']['host'] . ";dbname=" . $config['db']['name'] . ";charset=utf8mb4",
        $config['db']['user'],
        $config['db']['password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// ===========================
// FUNKCE PRO FORMATOVANI CASU
// ===========================
function formatDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        return round($seconds / 60) . 'm';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = round(($seconds % 3600) / 60);
        if ($minutes > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }
        return $hours . 'h';
    }
}

function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

// ===========================
// ZISKAT DATA PODLE TYPU REPORTU
// ===========================
$dateCondition = "";
$dateLabel = "";

switch ($reportType) {
    case 'weekly':
        $startDate = date('Y-m-d', strtotime('monday this week', strtotime($reportDate)));
        $endDate = date('Y-m-d', strtotime('sunday this week', strtotime($reportDate)));
        $dateCondition = "disconnect_date BETWEEN '$startDate' AND '$endDate'";
        $dateLabel = "Týden " . date('W/Y', strtotime($reportDate)) . " (" . date('d.m', strtotime($startDate)) . " - " . date('d.m.Y', strtotime($endDate)) . ")";
        break;
    
    case 'monthly':
        $startDate = date('Y-m-01', strtotime($reportDate));
        $endDate = date('Y-m-t', strtotime($reportDate));
        $dateCondition = "disconnect_date BETWEEN '$startDate' AND '$endDate'";
        $dateLabel = date('F Y', strtotime($reportDate));
        break;
    
    case 'daily':
    default:
        $dateCondition = "disconnect_date = '$reportDate'";
        $dateLabel = date('d.m.Y', strtotime($reportDate));
        break;
}

// ===========================
// HLAVNI QUERY - STATISTIKY PO UZIVATELICH
// ===========================
$stmt = $pdo->prepare("
    SELECT 
        username,
        COUNT(*) as total_connections,
        SUM(traffic_down_mb) as total_down_mb,
        SUM(traffic_up_mb) as total_up_mb,
        SUM(traffic_down_mb + traffic_up_mb) as total_traffic_mb,
        SUM(internal_traffic_mb) as total_internal_mb,
        SUM(tx_packets) as total_tx_packets,
        SUM(rx_packets) as total_rx_packets,
        SUM(internal_tx_packets) as total_internal_tx_packets,
        SUM(internal_rx_packets) as total_internal_rx_packets,
        SUM(rx_drops + tx_drops) as total_drops,
        SUM(rx_errors + tx_errors) as total_errors,
        SUM(session_time) as total_session_time,
        AVG(session_time) as avg_session_time,
        MAX(session_time) as max_session_time,
        MIN(session_time) as min_session_time,
        MIN(connect_date) as first_connection_date,
        MAX(disconnect_date) as last_connection_date,
        MIN(CONCAT(connect_date, ' ', connect_time)) as first_connection,
        MAX(CONCAT(disconnect_date, ' ', disconnect_time)) as last_connection
    FROM vpn_connections 
    WHERE $dateCondition
    GROUP BY username
    ORDER BY total_session_time DESC
");

$stmt->execute();
$userStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===========================
// CELKOVE STATISTIKY
// ===========================
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT username) as unique_users,
        COUNT(*) as total_connections,
        SUM(traffic_down_mb) as total_down_mb,
        SUM(traffic_up_mb) as total_up_mb,
        SUM(internal_traffic_mb) as total_internal_mb,
        SUM(session_time) as total_session_time,
        AVG(session_time) as avg_session_time,
        SUM(rx_drops + tx_drops + rx_errors + tx_errors) as total_issues
    FROM vpn_connections 
    WHERE $dateCondition
");

$stmt->execute();
$totalStats = $stmt->fetch(PDO::FETCH_ASSOC);

// ===========================
// STATISTIKY PO HODINACH
// ===========================
$stmt = $pdo->prepare("
    SELECT 
        HOUR(disconnect_time) as hour,
        COUNT(*) as connections,
        SUM(traffic_down_mb + traffic_up_mb) as traffic_mb,
        SUM(session_time) as session_time
    FROM vpn_connections 
    WHERE $dateCondition
    GROUP BY HOUR(disconnect_time)
    ORDER BY hour
");

$stmt->execute();
$hourlyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===========================
// TOP 5 PRIPOJENI (NEJDELSI)
// ===========================
$stmt = $pdo->prepare("
    SELECT 
        username,
        CONCAT(connect_date, ' ', connect_time) as connect_time,
        CONCAT(disconnect_date, ' ', disconnect_time) as disconnect_time,
        session_time,
        traffic_down_mb + traffic_up_mb as total_traffic_mb,
        caller_ip
    FROM vpn_connections 
    WHERE $dateCondition
    ORDER BY session_time DESC
    LIMIT 5
");

$stmt->execute();
$topSessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===========================
// GENEROVAT HTML REPORT
// ===========================
if ($format == 'html') {
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VPN Report - <?php echo $dateLabel; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f7fa; 
            padding: 20px;
            color: #2c3e50;
        }
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            background: white; 
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #2c3e50; 
            border-bottom: 4px solid #3498db; 
            padding-bottom: 15px; 
            margin-bottom: 30px;
            font-size: 32px;
        }
        h2 { 
            color: #34495e; 
            margin: 30px 0 15px 0; 
            font-size: 24px;
            border-left: 4px solid #3498db;
            padding-left: 15px;
        }
        
        /* Filter Form */
        .filter-form {
            background: #ecf0f1;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .filter-form label {
            margin-right: 10px;
            font-weight: bold;
        }
        .filter-form input, .filter-form select, .filter-form button {
            padding: 8px 15px;
            margin-right: 10px;
            border: 1px solid #bdc3c7;
            border-radius: 4px;
            font-size: 14px;
        }
        .filter-form button {
            background: #3498db;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
        }
        .filter-form button:hover {
            background: #2980b9;
        }
        
        /* Summary Cards */
        .summary-cards { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .summary-card { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            color: white; 
            padding: 25px; 
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .summary-card h3 { 
            font-size: 14px; 
            opacity: 0.9; 
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .summary-card .value { 
            font-size: 36px; 
            font-weight: bold; 
            margin-bottom: 5px;
        }
        .summary-card .label { 
            font-size: 12px; 
            opacity: 0.8; 
        }
        .summary-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .summary-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .summary-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        
        /* Tables */
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        th { 
            background: #3498db; 
            color: white; 
            padding: 15px; 
            text-align: left; 
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        td { 
            padding: 12px 15px; 
            border-bottom: 1px solid #ecf0f1;
        }
        tr:hover { 
            background: #f8f9fa; 
        }
        .total-row { 
            background: #2c3e50 !important; 
            color: white; 
            font-weight: bold;
            font-size: 15px;
        }
        .total-row td { 
            border: none; 
            padding: 15px;
        }
        .number { 
            text-align: right; 
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        .warning { 
            color: #e74c3c; 
            font-weight: bold; 
        }
        .success { 
            color: #27ae60; 
            font-weight: bold; 
        }
        
        /* Chart */
        .chart-container {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .chart-bar {
            display: flex;
            align-items: center;
            margin: 10px 0;
        }
        .chart-label {
            width: 80px;
            font-weight: bold;
            color: #34495e;
        }
        .chart-bar-fill {
            height: 30px;
            background: linear-gradient(90deg, #3498db 0%, #2ecc71 100%);
            border-radius: 4px;
            display: flex;
            align-items: center;
            padding: 0 10px;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }
        
        /* Footer */
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
            text-align: center;
            color: #7f8c8d;
            font-size: 13px;
        }
        
        /* Print styles */
        @media print {
            body { background: white; }
            .filter-form { display: none; }
            .container { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 VPN Provozní Report - <?php echo $dateLabel; ?></h1>
        
        <!-- Filter Form -->
        <div class="filter-form">
            <form method="GET" action="">
                <label>Datum:</label>
                <input type="date" name="date" value="<?php echo $reportDate; ?>">
                
                <label>Typ:</label>
                <select name="type">
                    <option value="daily" <?php echo $reportType == 'daily' ? 'selected' : ''; ?>>Denní</option>
                    <option value="weekly" <?php echo $reportType == 'weekly' ? 'selected' : ''; ?>>Týdenní</option>
                    <option value="monthly" <?php echo $reportType == 'monthly' ? 'selected' : ''; ?>>Měsíční</option>
                </select>
                
                <button type="submit">Zobrazit</button>
                <button type="button" onclick="window.location.href='?date=<?php echo date('Y-m-d'); ?>&type=daily'">Dnes</button>
                <button type="button" onclick="window.print()">Tisk</button>
            </form>
        </div>
        
        <?php if (count($userStats) == 0): ?>
            <div style="padding: 40px; text-align: center; color: #7f8c8d;">
                <h2>Žádná data pro zvolené období</h2>
                <p>V tomto období nebyly zaznamenány žádné VPN připojení.</p>
            </div>
        <?php else: ?>
        
        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card">
                <h3>Uživatelé</h3>
                <div class="value"><?php echo $totalStats['unique_users']; ?></div>
                <div class="label">Unikátních uživatelů</div>
            </div>
            <div class="summary-card green">
                <h3>Připojení</h3>
                <div class="value"><?php echo $totalStats['total_connections']; ?></div>
                <div class="label">Celkem spojení</div>
            </div>
            <div class="summary-card orange">
                <h3>Celkový čas</h3>
                <div class="value"><?php echo formatDuration($totalStats['total_session_time']); ?></div>
                <div class="label"><?php echo formatTime($totalStats['total_session_time']); ?></div>
            </div>
            <div class="summary-card blue">
                <h3>Průměrný čas</h3>
                <div class="value"><?php echo formatDuration($totalStats['avg_session_time']); ?></div>
                <div class="label">Na spojení</div>
            </div>
        </div>
        
        <div class="summary-cards">
            <div class="summary-card green">
                <h3>Download</h3>
                <div class="value"><?php echo number_format($totalStats['total_down_mb'] / 1024, 2); ?> GB</div>
                <div class="label"><?php echo number_format($totalStats['total_down_mb'], 0); ?> MB</div>
            </div>
            <div class="summary-card orange">
                <h3>Upload</h3>
                <div class="value"><?php echo number_format($totalStats['total_up_mb'] / 1024, 2); ?> GB</div>
                <div class="label"><?php echo number_format($totalStats['total_up_mb'], 0); ?> MB</div>
            </div>
            <div class="summary-card blue">
                <h3>Interní provoz</h3>
                <div class="value"><?php echo number_format($totalStats['total_internal_mb'], 0); ?> MB</div>
                <div class="label">Do vnitřní sítě</div>
            </div>
            <div class="summary-card <?php echo $totalStats['total_issues'] > 0 ? 'orange' : 'green'; ?>">
                <h3>Problémy</h3>
                <div class="value"><?php echo $totalStats['total_issues']; ?></div>
                <div class="label">Drops & Errors</div>
            </div>
        </div>
        
        <!-- Hourly Activity Chart -->
        <?php if (count($hourlyStats) > 0): ?>
        <h2>📈 Aktivita podle hodin</h2>
        <div class="chart-container">
            <?php 
            $maxConnections = 0;
            foreach ($hourlyStats as $hour) {
                if ($hour['connections'] > $maxConnections) {
                    $maxConnections = $hour['connections'];
                }
            }
            
            for ($h = 0; $h < 24; $h++):
                $hourData = null;
                foreach ($hourlyStats as $stat) {
                    if ($stat['hour'] == $h) {
                        $hourData = $stat;
                        break;
                    }
                }
                
                $connections = $hourData ? $hourData['connections'] : 0;
                $width = $maxConnections > 0 ? ($connections / $maxConnections) * 100 : 0;
            ?>
            <div class="chart-bar">
                <div class="chart-label"><?php echo sprintf('%02d:00', $h); ?></div>
                <?php if ($connections > 0): ?>
                <div class="chart-bar-fill" style="width: <?php echo $width; ?>%;">
                    <?php echo $connections; ?> připojení
                </div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
        
        <!-- User Statistics -->
        <h2>👥 Statistika podle uživatelů</h2>
        <table>
            <thead>
                <tr>
                    <th>Uživatel</th>
                    <th style="text-align: center;">Připojení</th>
                    <th class="number">Celkový čas</th>
                    <th class="number">Ø Čas</th>
                    <th class="number">Download (MB)</th>
                    <th class="number">Upload (MB)</th>
                    <th class="number">Interní (MB)</th>
                    <th class="number">RX Packets</th>
                    <th class="number">TX Packets</th>
                    <th style="text-align: center;">Problémy</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totalConnections = 0;
                $totalSessionTime = 0;
                $totalDownMB = 0;
                $totalUpMB = 0;
                $totalInternalMB = 0;
                $totalRxPackets = 0;
                $totalTxPackets = 0;
                $totalIssues = 0;
                
                foreach ($userStats as $user): 
                    $totalConnections += $user['total_connections'];
                    $totalSessionTime += $user['total_session_time'];
                    $totalDownMB += $user['total_down_mb'];
                    $totalUpMB += $user['total_up_mb'];
                    $totalInternalMB += $user['total_internal_mb'];
                    $totalRxPackets += $user['total_rx_packets'];
                    $totalTxPackets += $user['total_tx_packets'];
                    $totalIssues += $user['total_drops'] + $user['total_errors'];
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                    <td style="text-align: center;"><?php echo $user['total_connections']; ?></td>
                    <td class="number" title="<?php echo formatTime($user['total_session_time']); ?>">
                        <?php echo formatDuration($user['total_session_time']); ?>
                    </td>
                    <td class="number"><?php echo formatDuration($user['avg_session_time']); ?></td>
                    <td class="number"><?php echo number_format($user['total_down_mb'], 2); ?></td>
                    <td class="number"><?php echo number_format($user['total_up_mb'], 2); ?></td>
                    <td class="number"><?php echo number_format($user['total_internal_mb'], 2); ?></td>
                    <td class="number"><?php echo number_format($user['total_rx_packets']); ?></td>
                    <td class="number"><?php echo number_format($user['total_tx_packets']); ?></td>
                    <td style="text-align: center;" class="<?php echo ($user['total_drops'] + $user['total_errors']) > 0 ? 'warning' : 'success'; ?>">
                        <?php echo $user['total_drops'] + $user['total_errors']; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="total-row">
                    <td>CELKEM</td>
                    <td style="text-align: center;"><?php echo $totalConnections; ?></td>
                    <td class="number"><?php echo formatDuration($totalSessionTime); ?></td>
                    <td class="number">-</td>
                    <td class="number"><?php echo number_format($totalDownMB, 2); ?></td>
                    <td class="number"><?php echo number_format($totalUpMB, 2); ?></td>
                    <td class="number"><?php echo number_format($totalInternalMB, 2); ?></td>
                    <td class="number"><?php echo number_format($totalRxPackets); ?></td>
                    <td class="number"><?php echo number_format($totalTxPackets); ?></td>
                    <td style="text-align: center;"><?php echo $totalIssues; ?></td>
                </tr>
            </tbody>
        </table>
        
        <!-- Top 5 Longest Sessions -->
        <?php if (count($topSessions) > 0): ?>
        <h2>⏱️ TOP 5 nejdelších spojení</h2>
        <table>
            <thead>
                <tr>
                    <th>Uživatel</th>
                    <th>Připojení</th>
                    <th>Odpojení</th>
                    <th class="number">Doba trvání</th>
                    <th class="number">Traffic (MB)</th>
                    <th>Caller IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topSessions as $session): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($session['username']); ?></strong></td>
                    <td><?php echo date('d.m.Y H:i', strtotime($session['connect_time'])); ?></td>
                    <td><?php echo date('d.m.Y H:i', strtotime($session['disconnect_time'])); ?></td>
                    <td class="number"><?php echo formatDuration($session['session_time']); ?></td>
                    <td class="number"><?php echo number_format($session['total_traffic_mb'], 2); ?></td>
                    <td><?php echo htmlspecialchars($session['caller_ip']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php endif; // end if userStats ?>
        
        <div class="footer">
            Vygenerováno: <?php echo date('d.m.Y H:i:s'); ?> | VPN Repor v1.0 | (c) MILNET.cz | Martin Pouzar
        </div>
    </div>
</body>
</html>
<?php
} // end HTML format

// ===========================
// CSV FORMAT
// ===========================
elseif ($format == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vpn-report-' . $reportDate . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Header
    fputcsv($output, array('Username', 'Connections', 'Total Time (sec)', 'Avg Time (sec)', 
        'Download (MB)', 'Upload (MB)', 'Internal (MB)', 'RX Packets', 'TX Packets', 'Issues'));
    
    // Data
    foreach ($userStats as $user) {
        fputcsv($output, array(
            $user['username'],
            $user['total_connections'],
            $user['total_session_time'],
            round($user['avg_session_time']),
            number_format($user['total_down_mb'], 2, '.', ''),
            number_format($user['total_up_mb'], 2, '.', ''),
            number_format($user['total_internal_mb'], 2, '.', ''),
            $user['total_rx_packets'],
            $user['total_tx_packets'],
            $user['total_drops'] + $user['total_errors']
        ));
    }
    
    fclose($output);
}

// ===========================
// JSON FORMAT
// ===========================
elseif ($format == 'json') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'date' => $reportDate,
        'type' => $reportType,
        'summary' => $totalStats,
        'users' => $userStats,
        'hourly' => $hourlyStats,
        'top_sessions' => $topSessions
    ));
}
?>

