#!/usr/bin/env php
<?php

/**
 * Auth Failure Monitor Script
 *
 * Monitors log files for authentication failures, aggregates by IP address,
 * and automatically blocks IPs exceeding configurable thresholds.
 * Sends email alerts when total failures are high.
 *
 * Usage:
 *   php bin/monitor-auth-failures.php [options]
 *
 * Options:
 *   --dry-run   Simulate actions without blocking IPs or sending emails
 *   --verbose   Provide detailed console output
 *   --help      Show this help message
 *
 * @package Eskill
 * @author SEO Team
 * @version 1.0.0
 */

declare(strict_types=1);

// Require composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailerException;
use App\Services\GeoIPService;

/**
 * Database Connection Singleton
 */
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $dbname = $_ENV['DB_DATABASE'] ?? $_ENV['DB_NAME'] ?? 'meli';
            $username = $_ENV['DB_USERNAME'] ?? $_ENV['DB_USER'] ?? 'root';
            $password = $_ENV['DB_PASSWORD'] ?? $_ENV['DB_PASS'] ?? '';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            try {
                self::$instance = new PDO($dsn, $username, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException("Database connection failed: " . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Ensure required tables exist
     */
    public static function ensureTables(): void
    {
        $db = self::getInstance();

        // Create auth_blocked_ips table
        $db->exec("
            CREATE TABLE IF NOT EXISTS auth_blocked_ips (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                reason TEXT,
                failure_count INT NOT NULL DEFAULT 0,
                blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                INDEX idx_ip (ip_address),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Create auth_failure_log table
        $db->exec("
            CREATE TABLE IF NOT EXISTS auth_failure_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                user_agent TEXT,
                username VARCHAR(255),
                failure_type VARCHAR(100),
                detected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ip (ip_address),
                INDEX idx_detected (detected_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

/**
 * Email Service for sending alerts
 */
class EmailService
{
    private string $smtpHost;
    private int $smtpPort;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpSecure;
    private string $adminEmail;
    private bool $dryRun;

    public function __construct(bool $dryRun = false)
    {
        $this->smtpHost = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $this->smtpPort = (int)($_ENV['SMTP_PORT'] ?? 587);
        $this->smtpUser = $_ENV['SMTP_USER'] ?? '';
        $this->smtpPass = $_ENV['SMTP_PASS'] ?? '';
        $this->smtpSecure = $_ENV['SMTP_SECURE'] ?? 'tls';
        $this->adminEmail = $_ENV['ADMIN_EMAIL'] ?? '';
        $this->dryRun = $dryRun;
    }

    /**
     * Send alert email
     */
    public function sendAlert(int $totalFailures, array $blockedIps, array $topOffenders): bool
    {
        if ($this->dryRun) {
            echo "[DRY RUN] Would send email alert to {$this->adminEmail}\n";
            return true;
        }

        if (empty($this->adminEmail) || empty($this->smtpUser)) {
            throw new RuntimeException("Email configuration is incomplete");
        }

        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = $this->smtpSecure;
            $mail->Port = $this->smtpPort;
            $mail->CharSet = 'UTF-8';

            // Recipients
            $mail->setFrom($this->smtpUser, 'Auth Monitor');
            $mail->addAddress($this->adminEmail);

            // Content
            $mail->isHTML(true);
            $mail->Subject = "[ALERT] High Authentication Failure Activity Detected";
            $mail->Body = $this->buildEmailBody($totalFailures, $blockedIps, $topOffenders);

            $mail->send();
            return true;
        } catch (MailerException $e) {
            throw new RuntimeException("Email sending failed: {$mail->ErrorInfo}");
        }
    }

    /**
     * Build HTML email body
     */
    private function buildEmailBody(int $totalFailures, array $blockedIps, array $topOffenders): string
    {
        $timestamp = date('Y-m-d H:i:s');

        $html = "
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 800px; margin: 0 auto; padding: 20px; }
                .header { background: #d32f2f; color: white; padding: 20px; border-radius: 5px; }
                .stats { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
                .stat-item { margin: 10px 0; }
                .stat-label { font-weight: bold; color: #555; }
                .stat-value { color: #d32f2f; font-size: 1.2em; font-weight: bold; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
                th { background: #333; color: white; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 2px solid #ddd; color: #777; font-size: 0.9em; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⚠️ Authentication Failure Alert</h1>
                    <p>High volume of failed authentication attempts detected</p>
                </div>

                <div class='stats'>
                    <div class='stat-item'>
                        <span class='stat-label'>Total Failures:</span>
                        <span class='stat-value'>{$totalFailures}</span>
                    </div>
                    <div class='stat-item'>
                        <span class='stat-label'>IPs Blocked:</span>
                        <span class='stat-value'>" . count($blockedIps) . "</span>
                    </div>
                    <div class='stat-item'>
                        <span class='stat-label'>Detection Time:</span>
                        <span class='stat-value'>{$timestamp}</span>
                    </div>
                </div>

                <h2>Top Offending IPs</h2>
                <table>
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Failure Count</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>";

        foreach ($topOffenders as $offender) {
            $status = in_array($offender['ip_address'], $blockedIps) ? 'BLOCKED' : 'Monitored';
            $html .= "
                        <tr>
                            <td>{$offender['ip_address']}</td>
                            <td>{$offender['count']}</td>
                            <td><strong>{$status}</strong></td>
                        </tr>";
        }

        $html .= "
                    </tbody>
                </table>

                <h2>Blocked IPs</h2>
                <p>The following IP addresses have been automatically blocked:</p>
                <ul>";

        foreach ($blockedIps as $ip) {
            $html .= "<li><code>{$ip}</code></li>";
        }

        $html .= "
                </ul>

                <div class='footer'>
                    <p>This is an automated alert from the Authentication Failure Monitor.</p>
                    <p>Please review the server logs for more details.</p>
                </div>
            </div>
        </body>
        </html>";

        return $html;
    }
}

/**
 * Main Auth Failure Monitor Class
 */
class AuthFailureMonitor
{
    private PDO $db;
    private GeoIPService $geoip;
    private bool $dryRun = false;
    private bool $verbose = false;

    private int $blockThreshold;
    private int $alertThreshold;
    private int $blockDuration;
    private array $ipWhitelist;
    private string $logDir;
    private int $timeWindow;

    private array $stats = [
        'logs_analyzed' => 0,
        'failures_detected' => 0,
        'unique_ips' => 0,
        'ips_blocked' => 0,
        'ips_expired' => 0,
        'alerts_sent' => 0,
        'errors' => []
    ];

    public function __construct(bool $dryRun = false, bool $verbose = false)
    {
        $this->dryRun = $dryRun;
        $this->verbose = $verbose;
        $this->db = Database::getInstance();
        $this->geoip = new GeoIPService();

        // Load configuration
        $this->blockThreshold = (int)($_ENV['AUTH_BLOCK_THRESHOLD'] ?? 10);
        $this->alertThreshold = (int)($_ENV['AUTH_FAILURE_ALERT_THRESHOLD'] ?? 50);
        $this->blockDuration = (int)($_ENV['AUTH_BLOCK_DURATION'] ?? 3600);
        $this->timeWindow = (int)($_ENV['AUTH_TIME_WINDOW'] ?? 3600);
        $this->logDir = $_ENV['AUTH_LOG_DIR'] ?? __DIR__ . '/../storage/logs';
        $this->blockDuration = (int)($_ENV['AUTH_BLOCK_DURATION'] ?? 3600);
        $this->timeWindow = (int)($_ENV['AUTH_TIME_WINDOW'] ?? 3600); // 1 hour default

        $whitelistStr = $_ENV['AUTH_IP_WHITELIST'] ?? '';
        $this->ipWhitelist = array_filter(array_map('trim', explode(',', $whitelistStr)));

        $this->logDir = realpath(__DIR__ . '/../storage/logs');
    }

    /**
     * Run the monitor
     */
    public function run(): void
    {
        $this->log("Starting Auth Failure Monitor", true);
        $this->log("Configuration:", true);
        $this->log("  Block Threshold: {$this->blockThreshold}", true);
        $this->log("  Alert Threshold: {$this->alertThreshold}", true);
        $this->log("  Block Duration: {$this->blockDuration}s", true);
        $this->log("  Time Window: {$this->timeWindow}s", true);
        $this->log("  Whitelist: " . implode(', ', $this->ipWhitelist), true);
        $this->log("", true);

        try {
            // Ensure tables exist
            Database::ensureTables();
            $this->log("Database tables verified", true);

            // Clean expired blocks
            $this->cleanExpiredBlocks();

            // Analyze logs
            $failures = $this->analyzeLogs();
            $this->stats['failures_detected'] = count($failures);

            if (empty($failures)) {
                $this->log("No authentication failures detected", true);
                return;
            }

            // Aggregate by IP
            $aggregated = $this->aggregateByIp($failures);
            $this->stats['unique_ips'] = count($aggregated);

            // Block IPs exceeding threshold
            $blockedIps = $this->blockOffendingIps($aggregated);

            // Send alert if necessary
            if ($this->stats['failures_detected'] >= $this->alertThreshold) {
                $this->sendAlert($blockedIps, $aggregated);
            }

            $this->log("\nMonitoring complete", true);
        } catch (Exception $e) {
            $this->stats['errors'][] = $e->getMessage();
            $this->log("ERROR: " . $e->getMessage(), true);
            throw $e;
        }
    }

    /**
     * Clean expired IP blocks
     */
    private function cleanExpiredBlocks(): void
    {
        if ($this->dryRun) {
            $stmt = $this->db->query("SELECT COUNT(*) as count FROM auth_blocked_ips WHERE expires_at < NOW()");
            $count = $stmt->fetch()['count'];
            $this->log("[DRY RUN] Would clean {$count} expired IP blocks", true);
            $this->stats['ips_expired'] = $count;
            return;
        }

        $stmt = $this->db->prepare("DELETE FROM auth_blocked_ips WHERE expires_at < NOW()");
        $stmt->execute();
        $this->stats['ips_expired'] = $stmt->rowCount();
        $this->log("Cleaned {$this->stats['ips_expired']} expired IP blocks", true);
    }

    /**
     * Analyze log files for authentication failures
     */
    private function analyzeLogs(): array
    {
        $failures = [];
        $cutoffTime = time() - $this->timeWindow;

        $logFiles = glob($this->logDir . '/*.log*');
        $this->stats['logs_analyzed'] = count($logFiles);

        $this->log("Analyzing {$this->stats['logs_analyzed']} log files", true);

        foreach ($logFiles as $logFile) {
            $this->log("  Processing: " . basename($logFile));

            if (!is_readable($logFile)) {
                $this->log("    WARNING: File not readable, skipping");
                continue;
            }

            $handle = fopen($logFile, 'r');
            if (!$handle) {
                continue;
            }

            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                // Skip lines older than time window
                $timestamp = $this->extractTimestamp($line);
                if ($timestamp && $timestamp < $cutoffTime) {
                    continue;
                }

                // Check for authentication failure patterns
                $failure = $this->parseFailure($line);
                if ($failure) {
                    $failures[] = $failure;
                    $this->log("    Line {$lineNumber}: Failure detected from {$failure['ip_address']}");
                }
            }

            fclose($handle);
        }

        $this->log("  Total failures found: " . count($failures), true);
        return $failures;
    }

    /**
     * Extract timestamp from log line
     */
    private function extractTimestamp(string $line): ?int
    {
        // Try to parse JSON log
        if (str_starts_with(trim($line), '{')) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                if (array_key_exists('timestamp', $data)) {
                    $parsed = $this->coerceLogTimestamp($data['timestamp']);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
                if (array_key_exists('datetime', $data)) {
                    $parsed = $this->coerceLogTimestamp($data['datetime']);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }

        // Try to extract timestamp from text log
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            $parsed = strtotime($matches[1]);
            return $parsed !== false ? $parsed : null;
        }

        return null;
    }

    /**
     * Normaliza timestamp de log JSON (string ISO, unix seconds ou unix ms).
     * StructuredLogService grava timestamp numérico — strtotime() com
     * declare(strict_types=1) lança TypeError e derruba o cron.
     */
    private function coerceLogTimestamp(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            $numeric = (float) $value;
            if ($numeric <= 0) {
                return null;
            }
            // ms since epoch (~1e12+) → seconds
            if ($numeric > 1000000000000) {
                return (int) floor($numeric / 1000);
            }
            return (int) floor($numeric);
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return $this->coerceLogTimestamp((float) $trimmed);
        }

        $parsed = strtotime($trimmed);
        return $parsed !== false ? $parsed : null;
    }

    /**
     * Parse failure from log line
     */
    private function parseFailure(string $line): ?array
    {
        // Patterns for authentication failures
        $failurePatterns = [
            'login failed',
            'authentication failed',
            'invalid credentials',
            'brute force',
            'failed login',
            'unauthorized',
            'access denied',
            '401',
            '403'
        ];

        $hasFailure = false;
        $failureType = 'unknown';

        foreach ($failurePatterns as $pattern) {
            if (stripos($line, $pattern) !== false) {
                $hasFailure = true;
                $failureType = $pattern;
                break;
            }
        }

        if (!$hasFailure) {
            return null;
        }

        // Extract IP address
        $ipAddress = $this->extractIpAddress($line);
        if (!$ipAddress) {
            return null;
        }

        // Try to parse JSON
        $data = json_decode($line, true);

        return [
            'ip_address' => $ipAddress,
            'user_agent' => $data['user_agent'] ?? $this->extractUserAgent($line),
            'username' => $data['username'] ?? $this->extractUsername($line),
            'failure_type' => $failureType,
            'detected_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Extract IP address from log line
     */
    private function extractIpAddress(string $line): ?string
    {
        // Try JSON first
        $data = json_decode($line, true);
        if (is_array($data)) {
            $jsonIp = $data['ip'] ?? $data['ip_address'] ?? null;
            if (is_string($jsonIp) && $jsonIp !== '') {
                $validated = $this->validateIpAddress($jsonIp);
                if ($validated !== null) {
                    return $validated;
                }
            }
        }

        // IPv4 pattern
        if (preg_match('/\b(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\b/', $line, $matches)) {
            $validated = $this->validateIpAddress($matches[1]);
            if ($validated !== null) {
                return $validated;
            }
        }

        // IPv6 pattern (simplified) — exige ':' para não capturar unix timestamps (7+ dígitos hex)
        if (preg_match('/\b([0-9a-fA-F]{0,4}(?::[0-9a-fA-F]{0,4}){2,7})\b/', $line, $matches)) {
            $validated = $this->validateIpAddress($matches[1]);
            if ($validated !== null) {
                return $validated;
            }
        }

        return null;
    }

    /**
     * Aceita apenas IP real (IPv4/IPv6). Evita timestamps/horários como "1786062602" ou "02:30:02".
     */
    private function validateIpAddress(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return $candidate;
    }

    /**
     * Extract user agent from log line
     */
    private function extractUserAgent(string $line): ?string
    {
        if (preg_match('/"([^"]*Mozilla[^"]*)"/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Extract username from log line
     */
    private function extractUsername(string $line): ?string
    {
        if (preg_match('/user[:\s]+([^\s,\]]+)/i', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Aggregate failures by IP address
     */
    private function aggregateByIp(array $failures): array
    {
        $aggregated = [];

        foreach ($failures as $failure) {
            $ip = $failure['ip_address'];

            if (!isset($aggregated[$ip])) {
                $aggregated[$ip] = [
                    'ip_address' => $ip,
                    'count' => 0,
                    'failures' => []
                ];
            }

            $aggregated[$ip]['count']++;
            $aggregated[$ip]['failures'][] = $failure;
        }

        // Sort by count descending
        uasort($aggregated, fn($a, $b) => $b['count'] <=> $a['count']);

        $this->log("\nTop offending IPs:", true);
        $top10 = array_slice($aggregated, 0, 10);
        foreach ($top10 as $data) {
            $this->log("  {$data['ip_address']}: {$data['count']} failures", true);
        }

        return $aggregated;
    }

    /**
     * Block IPs that exceed threshold
     */
    private function blockOffendingIps(array $aggregated): array
    {
        $blockedIps = [];

        foreach ($aggregated as $data) {
            $ip = $data['ip_address'];

            // Skip if below threshold
            if ($data['count'] < $this->blockThreshold) {
                continue;
            }

            // Skip whitelisted IPs
            if (in_array($ip, $this->ipWhitelist)) {
                $this->log("  {$ip}: Whitelisted, not blocking", true);
                continue;
            }

            // Check if already blocked
            $stmt = $this->db->prepare("SELECT id FROM auth_blocked_ips WHERE ip_address = ? AND expires_at > NOW()");
            $stmt->execute([$ip]);
            if ($stmt->fetch()) {
                $this->log("  {$ip}: Already blocked", true);
                continue;
            }

            // Block the IP
            if ($this->blockIp($data)) {
                $blockedIps[] = $ip;
                $this->stats['ips_blocked']++;
            }
        }

        if (!empty($blockedIps)) {
            $this->log("\nBlocked " . count($blockedIps) . " IPs", true);
        }

        return $blockedIps;
    }

    /**
     * Block an IP address
     */
    private function blockIp(array $data): bool
    {
        $ip = $data['ip_address'];
        $count = $data['count'];
        $expiresAt = date('Y-m-d H:i:s', time() + $this->blockDuration);

        if ($this->dryRun) {
            $this->log("[DRY RUN] Would block {$ip} (failures: {$count}, expires: {$expiresAt})", true);

            // Still log failures in dry run
            foreach ($data['failures'] as $failure) {
                $this->logFailure($failure);
            }

            return true;
        }

        try {
            // Resolver geolocalização
            $geoData = $this->geoip->lookup($ip);

            // Insert block record
            $stmt = $this->db->prepare("
                INSERT INTO auth_blocked_ips
                (ip_address, country_code, country_name, city, reason, failure_count, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $reason = "Exceeded threshold of {$this->blockThreshold} failed authentication attempts";
            $stmt->execute([
                $ip,
                $geoData['country_code'] ?? null,
                $geoData['country_name'] ?? null,
                $geoData['city'] ?? null,
                $reason,
                $count,
                $expiresAt
            ]);

            // Log all failures
            foreach ($data['failures'] as $failure) {
                $this->logFailure($failure);
            }

            $location = $geoData ? $this->geoip->formatLocation($geoData) : 'Unknown';
            $this->log("BLOCKED: {$ip} from {$location} (failures: {$count}, expires: {$expiresAt})", true);
            return true;
        } catch (PDOException $e) {
            $this->log("ERROR blocking {$ip}: " . $e->getMessage(), true);
            return false;
        }
    }

    /**
     * Log individual failure to database
     */
    private function logFailure(array $failure): void
    {
        if ($this->dryRun) {
            return;
        }

        try {
            // Resolver geolocalização
            $geoData = $this->geoip->lookup($failure['ip_address']);

            $stmt = $this->db->prepare("
                INSERT INTO auth_failure_log
                (ip_address, country_code, country_name, city, latitude, longitude,
                 user_agent, username, failure_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $failure['ip_address'],
                $geoData['country_code'] ?? null,
                $geoData['country_name'] ?? null,
                $geoData['city'] ?? null,
                $geoData['latitude'] ?? null,
                $geoData['longitude'] ?? null,
                $failure['user_agent'],
                $failure['username'],
                $failure['failure_type']
            ]);
        } catch (PDOException $e) {
            // Silently fail to avoid disrupting the main process
        }
    }

    /**
     * Send alert email
     */
    private function sendAlert(array $blockedIps, array $aggregated): void
    {
        $this->log("\nTotal failures ({$this->stats['failures_detected']}) exceeded alert threshold ({$this->alertThreshold})", true);
        $this->log("Sending alert email...", true);

        try {
            $topOffenders = array_slice($aggregated, 0, 20);
            $emailService = new EmailService($this->dryRun);

            if ($emailService->sendAlert($this->stats['failures_detected'], $blockedIps, $topOffenders)) {
                $this->stats['alerts_sent']++;
                $this->log("Alert email sent successfully", true);
            }
        } catch (Exception $e) {
            $this->log("ERROR sending alert: " . $e->getMessage(), true);
            $this->stats['errors'][] = "Email: " . $e->getMessage();
        }
    }

    /**
     * Get execution statistics
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Log message to console
     */
    private function log(string $message, bool $always = false): void
    {
        if ($always || $this->verbose) {
            echo $message . "\n";
        }
    }
}

// =============================================================================
// Main execution
// =============================================================================

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'help']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$showHelp = isset($options['help']);

// Show help
if ($showHelp) {
    echo <<<HELP
Auth Failure Monitor

Monitors log files for authentication failures and automatically blocks
offending IP addresses.

Usage:
  php bin/monitor-auth-failures.php [options]

Options:
  --dry-run   Simulate actions without blocking IPs or sending emails
  --verbose   Provide detailed console output
  --help      Show this help message

Environment Variables:
  AUTH_BLOCK_THRESHOLD              Number of failures to trigger IP block (default: 10)
  AUTH_FAILURE_ALERT_THRESHOLD      Total failures to trigger email alert (default: 50)
  AUTH_BLOCK_DURATION              Block duration in seconds (default: 3600)
  AUTH_TIME_WINDOW                 Time window to analyze in seconds (default: 3600)
  AUTH_IP_WHITELIST                Comma-separated list of IPs to never block

  DB_HOST, DB_PORT, DB_NAME        Database connection settings
  DB_USER, DB_PASS

  ADMIN_EMAIL                      Email address for alerts
  SMTP_HOST, SMTP_PORT             SMTP server settings
  SMTP_USER, SMTP_PASS
  SMTP_SECURE                      'tls' or 'ssl'

Examples:
  # Run in production mode
  php bin/monitor-auth-failures.php

  # Test without making changes
  php bin/monitor-auth-failures.php --dry-run --verbose

  # Production with detailed output
  php bin/monitor-auth-failures.php --verbose

HELP;
    exit(0);
}

// Load environment variables
try {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (Exception $e) {
    echo "ERROR: Failed to load .env file: {$e->getMessage()}\n";
    exit(1);
}

// Check environment (allow verbose mode in non-production for testing)
$appEnv = $_ENV['APP_ENV'] ?? 'development';
if ($appEnv !== 'production' && !$verbose) {
    echo "ERROR: This script should only run in production environment.\n";
    echo "       Use --verbose flag to run in development for testing.\n";
    exit(1);
}

// Run monitor
try {
    $startTime = microtime(true);

    $monitor = new AuthFailureMonitor($dryRun, $verbose);
    $monitor->run();

    $executionTime = round(microtime(true) - $startTime, 2);
    $stats = $monitor->getStats();
    $stats['execution_time'] = $executionTime;
    $stats['timestamp'] = date('Y-m-d H:i:s');

    // Output final report as JSON
    echo "\n" . str_repeat('=', 70) . "\n";
    echo "EXECUTION REPORT\n";
    echo str_repeat('=', 70) . "\n";
    echo json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    exit(0);
} catch (Exception $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
