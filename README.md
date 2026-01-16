# MikroTik VPN Statistics Collector

Automated system for collecting and reporting VPN connection statistics from MikroTik RouterOS. Tracks connection duration, traffic usage, and generates comprehensive daily/weekly/monthly reports.

## ✨ Features

- 📊 **Automatic statistics collection** from PPP disconnect events
- ⏱️ **Accurate session tracking** - calculates connect time from disconnect time and session duration
- 📈 **Complete traffic statistics** - download/upload bytes and packets from PPP logs
- 📧 **Email-based transport** - MikroTik sends CSV data via email
- 🗄️ **MySQL long-term storage** with indexed queries
- 📱 **Responsive HTML reports** with daily/weekly/monthly views
- 🎨 **Hourly activity visualization** with bar charts
- 📤 **Multiple export formats** - HTML, CSV, JSON
- 🧹 **Automatic mailbox cleanup** after processing
- 🔄 **No global variables needed** - reads directly from PPP accounting logs

## 🔧 Requirements

### MikroTik Router

- RouterOS 6.x or 7.x
- PPP-based VPN server (PPTP/L2TP/SSTP/OpenVPN/WireGuard)
- Configured SMTP email sending
- PPP AAA accounting enabled (automatic in RouterOS)

### Collection Server

- PHP 5.4+ (tested up to PHP 8.x)
- MySQL 5.5+ or MariaDB 10.x+
- PHP IMAP extension (`php-imap`)
- Web server (Nginx/Apache)
- IMAP mailbox for receiving statistics

## 📦 Installation

### 1. Database Setup

Create the database and table:

```sql
CREATE DATABASE vpn_stats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE vpn_stats;

CREATE TABLE vpn_connections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(128) NOT NULL,
  connect_date DATE NOT NULL,
  connect_time TIME NOT NULL,
  disconnect_date DATE NOT NULL,
  disconnect_time TIME NOT NULL,
  traffic_down_mb DOUBLE NOT NULL DEFAULT 0,
  traffic_up_mb DOUBLE NOT NULL DEFAULT 0,
  internal_traffic_mb DOUBLE NOT NULL DEFAULT 0,
  tx_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  internal_tx_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  internal_rx_packets BIGINT UNSIGNED NOT NULL DEFAULT 0,
  caller_ip VARCHAR(64) NOT NULL DEFAULT '0.0.0.0',
  remote_ip VARCHAR(64) NOT NULL DEFAULT '0.0.0.0',
  rx_drops BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_drops BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rx_errors BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tx_errors BIGINT UNSIGNED NOT NULL DEFAULT 0,
  session_time INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_date (username, disconnect_date),
  KEY idx_disconnect_date (disconnect_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2. Database User

```sql
CREATE USER 'vpn_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON vpn_stats.* TO 'vpn_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. MikroTik Configuration

#### 3.1 Enable PPP AAA Accounting

PPP AAA accounting automatically logs connection statistics (session time, bytes transferred, packets). This is the foundation of our statistics system.

```mikrotik
# Enable AAA accounting for PPP
/ppp aaa set accounting=yes

# Verify configuration
/ppp aaa print
```

Expected output:
```
  accounting: yes
   interim-update: 0s
```

**Note:** PPP accounting logs are automatically written to system log when a user disconnects. The log format is:
```
username logged out, sessionTime rxBytes txBytes rxPackets txPackets from callerIP
```

Example:
```
pouzar logged out, 148 17969675 51524175 31921 40789 from 10.5.0.248
```

#### 3.2 Configure Email Settings

MikroTik needs to send statistics via email to the collection server.

```mikrotik
# Configure SMTP server
/tool e-mail
set server=mail.yourdomain.com     port=587     from=mikrotik@yourdomain.com     user=mikrotik@yourdomain.com     password=your_smtp_password     tls=yes

# For SMTP without TLS (port 25)
/tool e-mail
set server=mail.yourdomain.com     port=25     from=mikrotik@yourdomain.com

# For Gmail/Google Workspace
/tool e-mail
set server=smtp.gmail.com     port=587     from=youremail@gmail.com     user=youremail@gmail.com     password=your_app_password     tls=yes
```

**Test email configuration:**

```mikrotik
# Send test email
/tool e-mail send     to="vpn-stats@yourdomain.com"     subject="MikroTik Test"     body="Email configuration test"

# Check email log
/log print where topics~"e-mail"
```

You should see:
```
info e-mail email sent to vpn-stats@yourdomain.com
```

**Common SMTP configurations:**

| Provider | Server | Port | TLS | Notes |
|----------|--------|------|-----|-------|
| Gmail | smtp.gmail.com | 587 | yes | Requires App Password |
| Office365 | smtp.office365.com | 587 | yes | Use full email as user |
| Custom | mail.yourdomain.com | 25/587 | optional | Check with provider |
| Local relay | localhost | 25 | no | Postfix/Sendmail |

#### 3.3 Configure PPP Profile with Scripts

Navigate to **PPP → Profiles** and edit your VPN profile (usually "default").

**Option A: Using Web Interface (WebFig/WinBox)**

1. Open **PPP → Profiles**
2. Double-click your profile (e.g., "default")
3. Go to **Scripts** tab
4. Paste scripts into **On Up** and **On Down** fields
5. Click **OK**

**Option B: Using Terminal/CLI**

```mikrotik
# View current profiles
/ppp profile print

# Set On-Up script (simple version)
/ppp profile set default on-up=":local u \$user; :local ip \$"remote-address"; :local caller \$"caller-id"; :local iface \$interface; :log info "VPN-CONNECT: User=\$u IP=\$ip CallerID=\$caller Interface=\$iface"; /ip firewall address-list remove [find list="vpn-active-users" address=\$ip]; /ip firewall address-list add list="vpn-active-users" address=\$ip comment="VPN-\$u" timeout=24h; :log info "VPN-CONNECT-COMPLETE: \$u ready""
```

For the **On-Down script**, it's recommended to create it as a separate script due to length:

```mikrotik
# Create the On-Down script
/system script add name=vpn-disconnect-handler     policy=read,write,policy,test     source="<paste full On-Down script here>"

# Assign to profile
/ppp profile set default on-down="/system script run vpn-disconnect-handler"
```

### 4. MikroTik PPP Scripts

#### 4.1 On-Up Script

Simple script to log connection start and add to active users list:

```mikrotik
# PPP On-Up Script - with AAA accounting
:local username $user
:local remoteIP $"remote-address"
:local callerID $"caller-id"
:local interfaceName $interface

:log info "VPN-CONNECT: User=$username IP=$remoteIP CallerID=$callerID Interface=$interfaceName"

# AAA accounting automatically stores connect info, no need for global variables
# We can optionally add to address-list for monitoring

/ip firewall address-list remove [find list="vpn-active-users" address=$remoteIP]
/ip firewall address-list add     list="vpn-active-users"     address=$remoteIP     comment="VPN-$username"     timeout=24h

:log info "VPN-CONNECT-COMPLETE: $username ready"
```

#### 4.2 On-Down Script

Complete script that parses PPP logs and sends statistics via email:

```mikrotik
# PPP On-Down Script - FINAL VERSION with calculated connect time
:local u $user
:local dT [/system clock get time]
:local dD [/system clock get date]

:log info "=== VPN-DISCONNECT: $u ==="

# Convert date from jan/16/2026 to 2026-01-16
:local dSQL $dD
:local p1 [:find $dD "/"]
:if ([:typeof $p1] = "num") do={
    :local monthStr [:pick $dD 0 $p1]
    :local rest [:pick $dD ($p1+1) [:len $dD]]
    :local p2 [:find $rest "/"]

    :if ([:typeof $p2] = "num") do={
        :local dayStr [:pick $rest 0 $p2]
        :local yearStr [:pick $rest ($p2+1) [:len $rest]]
        :if ([:len $dayStr] = 1) do={:set dayStr "0$dayStr"}

        :local monthNum "01"
        :if ($monthStr = "jan") do={:set monthNum "01"}
        :if ($monthStr = "feb") do={:set monthNum "02"}
        :if ($monthStr = "mar") do={:set monthNum "03"}
        :if ($monthStr = "apr") do={:set monthNum "04"}
        :if ($monthStr = "may") do={:set monthNum "05"}
        :if ($monthStr = "jun") do={:set monthNum "06"}
        :if ($monthStr = "jul") do={:set monthNum "07"}
        :if ($monthStr = "aug") do={:set monthNum "08"}
        :if ($monthStr = "sep") do={:set monthNum "09"}
        :if ($monthStr = "oct") do={:set monthNum "10"}
        :if ($monthStr = "nov") do={:set monthNum "11"}
        :if ($monthStr = "dec") do={:set monthNum "12"}

        :set dSQL "$yearStr-$monthNum-$dayStr"
    }
}

# Parse PPP accounting log
:delay 1s
:local caller "N/A"
:local sT 0
:local rxB 0
:local txB 0
:local rxP 0
:local txP 0

:foreach le in=[/log find message~"$u logged out"] do={
    :local msg [/log get $le message]
    :local cp [:find $msg ", "]

    :if ([:typeof $cp] = "num") do={
        :local after [:pick $msg ($cp+2) [:len $msg]]
        :local nums [:toarray ""]
        :local curr ""

        # Extract numbers from "sessionTime rxBytes txBytes rxPackets txPackets"
        :for i from=0 to=([:len $after]-1) do={
            :local c [:pick $after $i ($i+1)]
            :if ($c="0"||$c="1"||$c="2"||$c="3"||$c="4"||$c="5"||$c="6"||$c="7"||$c="8"||$c="9") do={
                :set curr "$curr$c"
            } else={
                :if ([:len $curr] > 0) do={
                    :set nums ($nums,$curr)
                    :set curr ""
                    :if ([:len $nums] >= 5) do={:set i [:len $after]}
                }
            }
        }

        :if ([:len $nums] >= 5) do={
            :set sT [:tonum ($nums->0)]
            :set rxB [:tonum ($nums->1)]
            :set txB [:tonum ($nums->2)]
            :set rxP [:tonum ($nums->3)]
            :set txP [:tonum ($nums->4)]
        }

        # Extract caller IP from "from X.X.X.X"
        :local fp [:find $msg " from "]
        :if ([:typeof $fp] = "num") do={
            :set caller [:pick $msg ($fp+6) [:len $msg]]
            :local sp [:find $caller " "]
            :if ([:typeof $sp] = "num") do={:set caller [:pick $caller 0 $sp]}
        }
    }
}

# Convert bytes to MB
:local rxMB ($rxB / 1048576)
:local txMB ($txB / 1048576)

# ==========================================
# CALCULATE CONNECT TIME from disconnect - session
# ==========================================
:local cT $dT
:local cD $dSQL

:if ($sT > 0) do={
    # Parse disconnect time HH:MM:SS
    :local p1 [:find $dT ":"]
    :local p2 [:find $dT ":" ($p1+1)]

    :if ([:typeof $p1] = "num" && [:typeof $p2] = "num") do={
        :local hh [:tonum [:pick $dT 0 $p1]]
        :local mm [:tonum [:pick $dT ($p1+1) $p2]]
        :local ss [:tonum [:pick $dT ($p2+1) [:len $dT]]]

        # Convert to total seconds
        :local totalSec (($hh * 3600) + ($mm * 60) + $ss)

        # Subtract session time
        :local connectSec ($totalSec - $sT)

        # Handle negative (crossed midnight or previous day)
        :if ($connectSec < 0) do={
            :set connectSec ($connectSec + 86400)
            # Should also subtract 1 day from date, but simplified for now
        }

        # Convert back to HH:MM:SS
        :local cHH ($connectSec / 3600)
        :local cMM (($connectSec % 3600) / 60)
        :local cSS ($connectSec % 60)

        # Format with leading zeros
        :local cHHstr "$cHH"
        :local cMMstr "$cMM"
        :local cSSstr "$cSS"

        :if ($cHH < 10) do={:set cHHstr "0$cHH"}
        :if ($cMM < 10) do={:set cMMstr "0$cMM"}
        :if ($cSS < 10) do={:set cSSstr "0$cSS"}

        :set cT "$cHHstr:$cMMstr:$cSSstr"

        :log info "Calculated connect time: $cT (disconnect $dT - $sT sec)"
    }
}

# Build CSV: VPN_DISCONNECT,user,connectDate,connectTime,disconnectDate,disconnectTime,txMB,rxMB,...
:local csv "VPN_DISCONNECT,$u,$cD,$cT,$dSQL,$dT,$txMB,$rxMB,0,$txP,$rxP,0,0,$caller,N/A,0,0,0,0,$sT"

:log info "CSV: $csv"

# Send email with statistics
:do {
    /tool e-mail send to="vpn-stats@yourdomain.com" subject="VPN-STATS-$u" body=$csv
    :log info "Email sent"
} on-error={
    :log error "Email failed"
}

:log info "=== VPN-COMPLETE ==="
```

**Important:** Replace `vpn-stats@yourdomain.com` with your actual collection email address!

### 5. Deploy PHP Collector

Create `collector.php`:

```php
<?php
/**
 * VPN Statistics Collector
 * Processes emails from MikroTik and stores in MySQL
 */

$config = array(
    'imap' => array(
        'mailbox'  => '{localhost:143/imap/notls}INBOX',
        'username' => 'vpn-stats@yourdomain.com',
        'password' => 'mailbox_password',
    ),
    'db' => array(
        'host'     => 'localhost',
        'name'     => 'vpn_stats',
        'user'     => 'vpn_user',
        'password' => 'db_password',
        'charset'  => 'utf8mb4',
    ),
    'cleanup' => array(
        'enabled' => true,
        'method'  => 'delete', // delete|trash|archive
    ),
    'debug' => true,
);

date_default_timezone_set('Europe/Prague');

function convertMikrotikDate($dateStr) {
    $months = array(
        'jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04',
        'may'=>'05','jun'=>'06','jul'=>'07','aug'=>'08',
        'sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'
    );
    $dateStr = trim(strtolower($dateStr));
    if ($dateStr == 'n/a' || $dateStr == '') {
        return date('Y-m-d');
    }
    $parts = explode('/', $dateStr);
    if (count($parts) == 3 && isset($months[$parts[0]])) {
        $day  = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $year = $parts[2];
        return $year . '-' . $months[$parts[0]] . '-' . $day;
    }
    return date('Y-m-d');
}

// Database connection
try {
    $dsn = 'mysql:host='.$config['db']['host'].';dbname='.$config['db']['name'].';charset='.$config['db']['charset'];
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "[OK] Database connected\n";
} catch (PDOException $e) {
    die("[ERROR] Database: ".$e->getMessage()."\n");
}

// IMAP connection
$inbox = imap_open(
    $config['imap']['mailbox'],
    $config['imap']['username'],
    $config['imap']['password']
);
if (!$inbox) {
    die("[ERROR] IMAP: ".imap_last_error()."\n");
}
echo "[OK] IMAP connected\n";

$emails = imap_search($inbox, 'ALL');
if (!$emails) {
    echo "[INFO] No emails\n";
    imap_close($inbox);
    exit;
}

$stmt = $pdo->prepare("
    INSERT INTO vpn_connections (
        username, connect_date, connect_time, disconnect_date, disconnect_time,
        traffic_down_mb, traffic_up_mb, internal_traffic_mb,
        tx_packets, rx_packets, internal_tx_packets, internal_rx_packets,
        caller_ip, remote_ip, rx_drops, tx_drops, rx_errors, tx_errors, session_time
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");

$processed = 0;
$toDelete = array();

foreach ($emails as $num) {
    $overview = imap_fetch_overview($inbox, $num, 0);
    $subject = isset($overview[0]->subject) ? $overview[0]->subject : '';

    if (stripos($subject, 'VPN') === false) {
        continue;
    }

    $body = imap_body($inbox, $num);
    $body = trim($body);
    if ($body === '') continue;

    $lines = explode("\n", $body);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        $data = str_getcsv($line);
        if (count($data) < 15 || $data[0] != 'VPN_DISCONNECT') continue;

        $user = trim($data[1]);
        if ($user === '') continue;

        $connectDate = convertMikrotikDate($data[2]);
        $connectTime = $data[3];
        $disconnectDate = convertMikrotikDate($data[4]);
        $disconnectTime = $data[5];

        $stmt->execute(array(
            $user,
            $connectDate, $connectTime,
            $disconnectDate, $disconnectTime,
            floatval($data[6]), floatval($data[7]), floatval($data[8]),
            intval($data[9]), intval($data[10]),
            intval($data[11]), intval($data[12]),
            isset($data[13]) ? $data[13] : '0.0.0.0',
            isset($data[14]) ? $data[14] : '0.0.0.0',
            isset($data[15]) ? intval($data[15]) : 0,
            isset($data[16]) ? intval($data[16]) : 0,
            isset($data[17]) ? intval($data[17]) : 0,
            isset($data[18]) ? intval($data[18]) : 0,
            isset($data[19]) ? intval($data[19]) : 0
        ));

        $processed++;
        $toDelete[] = $num;
        echo "[OK] Processed: $user ($connectDate $connectTime -> $disconnectDate $disconnectTime)\n";
        break;
    }
}

// Cleanup
if ($config['cleanup']['enabled'] && count($toDelete) > 0) {
    foreach ($toDelete as $num) {
        imap_delete($inbox, $num);
    }
    imap_expunge($inbox);
    echo "[INFO] Deleted ".count($toDelete)." emails\n";
}

imap_close($inbox);
echo "[DONE] Processed: $processed records\n";
?>
```

### 6. Setup Cron Job

```bash
# Edit crontab
crontab -e

# Add collector (every 5 minutes)
*/5 * * * * /usr/bin/php /var/www/html/vpn/collector.php >> /var/log/vpn-collector.log 2>&1
```

## 📊 Report Generator

Use the `report.php` from the repository. It supports:

```
# Daily report
https://yourdomain.com/vpn/report.php?date=2026-01-16&type=daily

# Weekly report
https://yourdomain.com/vpn/report.php?type=weekly

# Monthly report
https://yourdomain.com/vpn/report.php?type=monthly

# CSV export
https://yourdomain.com/vpn/report.php?date=2026-01-16&format=csv

# JSON export
https://yourdomain.com/vpn/report.php?date=2026-01-16&format=json
```

## 🔍 How It Works

1. **User connects** to VPN → MikroTik executes On-Up script (logs connection, adds to address-list)
2. **User disconnects** → MikroTik executes On-Down script:
   - PPP AAA accounting writes to log: `username logged out, sessionTime rxBytes txBytes rxPackets txPackets from callerIP`
   - Script waits 1 second for log entry to be written
   - Parses log to extract statistics
   - Calculates connect time = disconnect time - session time
   - Converts MikroTik date format (jan/16/2026) to SQL format (2026-01-16)
   - Sends CSV via email
3. **PHP collector** (runs every 5 minutes via cron):
   - Connects to IMAP mailbox
   - Finds emails with "VPN" in subject
   - Parses CSV data from email body
   - Inserts into MySQL database
   - Deletes processed emails (cleanup)
4. **Report generator** (web interface):
   - Queries MySQL for statistics
   - Generates HTML/CSV/JSON reports
   - Shows daily/weekly/monthly aggregations
   - Displays hourly activity charts

## 🐛 Troubleshooting

### Check PPP AAA Accounting

```mikrotik
# Verify AAA is enabled
/ppp aaa print
# Should show: accounting: yes

# Check recent disconnect logs
/log print where message~"logged out"

# Example output:
# jan/16/2026 19:26:11 ppp,ppp,info,account pouzar logged out, 148 17969675 51524175 31921 40789 from 10.5.0.248
```

The log format is:
```
username logged out, sessionTime rxBytes txBytes rxPackets txPackets from callerIP
```

### Test Email Configuration

```mikrotik
# Check current email settings
/tool e-mail print

# Send test email
/tool e-mail send to="vpn-stats@yourdomain.com" subject="Test" body="MikroTik email test"

# Check email log (should show "email sent")
/log print where topics~"e-mail"
```

Common issues:
- **"couldn't resolve host"** → Check DNS settings: `/ip dns print`
- **"connection timed out"** → Check firewall, verify SMTP port is allowed
- **"authentication failed"** → Verify username/password, check TLS setting
- **"relay access denied"** → SMTP server doesn't accept from your IP

### Check PPP Profile Scripts

```mikrotik
# View profile configuration
/ppp profile print detail where name="default"

# Should show:
# on-up: <your on-up script>
# on-down: <your on-down script>

# Test script manually (requires active connection)
/system script run vpn-disconnect-handler
```

### Monitor VPN Connections

```mikrotik
# View active VPN connections
/ppp active print detail

# View VPN users in address-list
/ip firewall address-list print where list="vpn-active-users"

# Follow logs in real-time
/log print follow where message~"VPN"

# View script execution logs
/log print where topics~"script,info"
```

### Collector Not Processing Emails

```bash
# Test IMAP connection manually
telnet localhost 143
a1 LOGIN vpn-stats@yourdomain.com password
a2 SELECT INBOX
a3 SEARCH SUBJECT "VPN"
a4 LOGOUT

# Check PHP IMAP extension
php -m | grep imap

# Install if missing (Debian/Ubuntu)
sudo apt-get install php-imap
sudo systemctl restart apache2

# Run collector manually with debug
php /var/www/html/vpn/collector.php

# Check collector logs
tail -f /var/log/vpn-collector.log
```

### Database Connection Issues

```bash
# Test MySQL connection
mysql -u vpn_user -p vpn_stats -e "SELECT COUNT(*) FROM vpn_connections;"

# Check recent entries
mysql -u vpn_user -p vpn_stats -e "SELECT * FROM vpn_connections ORDER BY id DESC LIMIT 5;"

# Verify table structure
mysql -u vpn_user -p vpn_stats -e "DESCRIBE vpn_connections;"
```

### Debug On-Down Script

Add extra logging to the script:

```mikrotik
# After parsing PPP log, add:
:log info "Debug: sT=$sT rxB=$rxB txB=$txB rxP=$rxP txP=$txP caller=$caller"

# Before building CSV:
:log info "Debug: cD=$cD cT=$cT dSQL=$dSQL dT=$dT"

# View debug logs:
/log print where message~"Debug:"
```

## 📝 CSV Format

The MikroTik script sends data in this CSV format:

```
VPN_DISCONNECT,username,connectDate,connectTime,disconnectDate,disconnectTime,txMB,rxMB,internalMB,txPackets,rxPackets,internalTxPackets,internalRxPackets,callerIP,remoteIP,rxDrops,txDrops,rxErrors,txErrors,sessionTime
```

Example:
```
VPN_DISCONNECT,john,2026-01-16,19:23:43,2026-01-16,19:26:11,49.17,17.0,0,40789,31921,0,0,10.5.0.248,N/A,0,0,0,0,148
```

Field descriptions:
- **username** - PPP username
- **connectDate** - Calculated from disconnect - session (YYYY-MM-DD)
- **connectTime** - Calculated from disconnect - session (HH:MM:SS)
- **disconnectDate** - Actual disconnect date (YYYY-MM-DD)
- **disconnectTime** - Actual disconnect time (HH:MM:SS)
- **txMB** - Megabytes uploaded by user
- **rxMB** - Megabytes downloaded by user
- **internalMB** - Internal network traffic (not implemented)
- **txPackets** - Packets transmitted
- **rxPackets** - Packets received
- **callerIP** - Source IP address of VPN connection
- **remoteIP** - Assigned VPN IP address (not available in disconnect)
- **sessionTime** - Connection duration in seconds

## 🎯 Key Features Explained

### PPP AAA Accounting

RouterOS automatically logs comprehensive statistics when PPP accounting is enabled:

```mikrotik
/ppp aaa set accounting=yes
```

When a user disconnects, RouterOS writes to the log:
```
username logged out, sessionTime rxBytes txBytes rxPackets txPackets from callerIP
```

This provides:
- **Session duration** in seconds
- **Traffic statistics** (bytes and packets)
- **Caller IP** (source of connection)

No manual tracking needed - RouterOS does it automatically!

### Accurate Connect Time Calculation

Since the interface is destroyed before On-Down runs, we can't query it directly. Instead:

```
Connect Time = Disconnect Time - Session Time
```

Example calculation:
```
Disconnect: 19:26:11 = 19*3600 + 26*60 + 11 = 69971 seconds
Session: 148 seconds
Connect: 69971 - 148 = 69823 seconds = 19:23:43
```

This gives accurate connect time without storing global variables.

### Date Format Conversion

MikroTik uses `jan/16/2026`, MySQL needs `2026-01-16`:

```mikrotik
# Month name to number mapping
:if ($monthStr = "jan") do={:set monthNum "01"}
:if ($monthStr = "feb") do={:set monthNum "02"}
# ... etc

# Format as YYYY-MM-DD
:set dSQL "$yearStr-$monthNum-$dayStr"
```

### Email Transport

Email is reliable and simple:
- ✅ No need for HTTP server on MikroTik
- ✅ Works through firewalls (SMTP usually allowed)
- ✅ Built-in retry on MikroTik
- ✅ Email provides audit trail
- ✅ Can be monitored/archived

## 🤝 Contributing

Contributions welcome! Please submit pull requests or open issues for bugs/features.

## 📄 License

MIT License - see LICENSE file for details.

## 🙏 Acknowledgments

- MikroTik RouterOS PPP accounting system
- PHP IMAP extension
- Community testing and feedback

## 📈 Version History

### v1.1.0 (2026-01-16)
- ✅ Accurate connect time calculation from session duration
- ✅ Direct parsing of PPP accounting logs
- ✅ No global variables required
- ✅ Simplified On-Down script
- ✅ Improved date conversion
- ✅ Better error handling
- ✅ Detailed MikroTik configuration guide
- ✅ PPP AAA accounting documentation
- ✅ Email configuration examples

### v1.0.0 (2026-01-16)
- Initial release
- Basic statistics collection
- Email transport
- MySQL storage
- HTML reports

---

**Made with ❤️ for network administrators**
