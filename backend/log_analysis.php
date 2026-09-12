<?php
require_once __DIR__ . '/access_log.php';
require_once __DIR__ . '/access_stats.php';
require_once __DIR__ . '/error_404.php';
require_once __DIR__ . '/bot_analyzer.php';

class LogAnalysisDashboard {
    private $viewer;
    private $stats;
    private $error404;
    private $botAnalyzer;
    private $formats = array('csv', 'json');
    private $tabs = array('viewer', 'stats', 'error404', 'bot');
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->authorize();
        
        $this->viewer = new AccessLogViewer($logPath);
        $this->stats = new AccessStatistics($logPath);
        $this->error404 = new Error404Analyzer($logPath);
        $this->botAnalyzer = new BotAnalyzer($logPath);
    }
    
    private function authorize() {
        if (PHP_SAPI === 'cli') {
            return;
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                || (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
            session_set_cookie_params(array(
                'lifetime' => 86400,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Strict'
            ));
            session_start();
        }
        
        $authenticated = !empty($_SESSION['authenticated'])
            || !empty($_SESSION['access_token_valid'])
            || !empty($_SESSION['api_token_scopes']);
        if (!$authenticated) {
            $this->denyAccess('Authentication is required to view the access log dashboard.');
        }
        
        if ($this->resolveRoleRank() < $this->requiredRoleRank()) {
            $this->denyAccess('Administrator role is required to view the access log dashboard.');
        }
        
        if (function_exists('gojs_log_operation')) {
            gojs_log_operation('log_analysis.view', 'backend/log_analysis.php', true, 'Viewed the access log dashboard');
        }
    }
    
    private function requiredRoleRank() {
        if (function_exists('gojs_role_rank')) {
            return gojs_role_rank('admin');
        }
        return 3;
    }
    
    private function resolveRoleRank() {
        if (function_exists('gojs_current_role_rank')) {
            $rank = gojs_current_role_rank();
            if ($rank > 0) {
                return $rank;
            }
        }
        
        if (!empty($_SESSION['user_role']) && function_exists('gojs_role_rank')) {
            $named = gojs_role_rank($_SESSION['user_role']);
            if ($named > 0) {
                return $named;
            }
        }
        
        if (!empty($_SESSION['authenticated'])
            || !empty($_SESSION['access_token_valid'])
            || !empty($_SESSION['api_token_scopes'])) {
            return $this->requiredRoleRank();
        }
        
        return 0;
    }
    
    private function denyAccess($message) {
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $message;
        exit;
    }
    
    private function csvRow($fields) {
        $escaped = array();
        foreach ($fields as $field) {
            $escaped[] = '"' . str_replace('"', '""', (string)$field) . '"';
        }
        return implode(',', $escaped) . "\n";
    }
    
    private function handleExport($activeTab, $format) {
        if (!in_array($format, $this->formats, true)) {
            $this->denyAccess('Unsupported export format.');
        }
        
        $contentTypes = array('csv' => 'text/csv; charset=utf-8', 'json' => 'application/json; charset=utf-8');
        $filename = 'log_analysis_' . $activeTab . '_' . date('Ymd_His') . '.' . $format;
        
        if (!headers_sent()) {
            header('Content-Type: ' . $contentTypes[$format]);
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
        }
        
        switch ($activeTab) {
            case 'viewer':
                $this->exportViewerData($format);
                break;
            case 'stats':
                $this->exportStatsData($format);
                break;
            case 'error404':
                $this->exportError404Data($format);
                break;
            case 'bot':
                $this->exportBotData($format);
                break;
            default:
                $this->denyAccess('Unsupported export tab.');
        }
        
        exit;
    }
    
    private function exportViewerData($format) {
        $records = $this->viewer->getAllLogs();
        
        if ($format === 'csv') {
            $output = $this->csvRow(array('IP', 'Time', 'Request', 'Status', 'Size', 'Referer', 'User Agent'));
            foreach ($records as $record) {
                $output .= $this->csvRow(array(
                    $record['ip'],
                    $record['time'],
                    $record['request'],
                    $record['status'],
                    $record['size'],
                    $record['referer'],
                    $record['user_agent']
                ));
            }
            echo $output;
            return;
        }
        
        echo json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    private function exportStatsData($format) {
        $hourlyStats = $this->stats->getHourlyStats();
        $dailyStats = $this->stats->getDailyStats();
        $topPages = $this->stats->getTopPages();
        $topIPs = $this->stats->getTopIPs();
        $statusDistribution = $this->stats->getStatusDistribution();
        
        if ($format === 'csv') {
            $output = $this->csvRow(array('Section', 'Key', 'Page Views', 'Unique Visitors'));
            foreach ($hourlyStats as $bucket => $data) {
                $output .= $this->csvRow(array('Hourly (Last 24 Hours)', $bucket, $data['pv'], $data['uv']));
            }
            foreach ($dailyStats as $bucket => $data) {
                $output .= $this->csvRow(array('Daily', $bucket, $data['pv'], $data['uv']));
            }
            foreach ($topPages as $path => $data) {
                $output .= $this->csvRow(array('Top Page', $path, $data['count'], $data['size'] . ' bytes'));
            }
            foreach ($topIPs as $ip => $data) {
                $output .= $this->csvRow(array('Top IP', $ip, $data['count'], $data['size'] . ' bytes'));
            }
            foreach ($statusDistribution as $status => $count) {
                $output .= $this->csvRow(array('Status Code', $status, $count, ''));
            }
            echo $output;
            return;
        }
        
        echo json_encode(array(
            'hourly_stats' => $hourlyStats,
            'daily_stats' => $dailyStats,
            'top_pages' => $topPages,
            'top_ips' => $topIPs,
            'status_distribution' => $statusDistribution
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    private function exportError404Data($format) {
        $stats = $this->error404->get404Stats();
        $deadLinks = $this->error404->analyzeDeadLinks();
        
        if ($format === 'csv') {
            $output = $this->csvRow(array('Section', 'Path', 'Count', 'Unique IPs', 'First Error', 'Last Error'));
            foreach ($stats['broken_pages'] as $path => $data) {
                $output .= $this->csvRow(array(
                    'Broken Page',
                    $path,
                    $data['count'],
                    count(array_unique($data['ips'])),
                    $data['first_timestamp'] === null ? 'n/a' : date('Y-m-d H:i:s', $data['first_timestamp']),
                    $data['last_timestamp'] === null ? 'n/a' : date('Y-m-d H:i:s', $data['last_timestamp'])
                ));
            }
            foreach ($deadLinks as $link) {
                $output .= $this->csvRow(array('Dead Link', $link['path'], $link['error_count'], $link['unique_ips'], '', ''));
            }
            echo $output;
            return;
        }
        
        echo json_encode(array(
            'summary' => array(
                'total' => $stats['total'],
                'unique_paths' => count($stats['unique_paths']),
                'unique_ips' => count($stats['unique_ips'])
            ),
            'error_timeline' => $stats['error_timeline'],
            'broken_pages' => $stats['broken_pages'],
            'dead_links' => $deadLinks
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    private function exportBotData($format) {
        $stats = $this->botAnalyzer->getBotStatistics();
        $traffic = $this->botAnalyzer->getBotTraffic();
        
        if ($format === 'csv') {
            $output = $this->csvRow(array('Bot Name', 'Requests', 'Size', 'Unique IPs', 'Share Of Total Requests'));
            foreach ($stats['top_bots'] as $bot) {
                $output .= $this->csvRow(array(
                    $bot['name'],
                    $bot['count'],
                    $bot['size'],
                    $bot['unique_ips'],
                    $bot['percentage'] . '%'
                ));
            }
            echo $output;
            return;
        }
        
        echo json_encode(array(
            'summary' => array(
                'total_requests' => $stats['total_requests'],
                'bot_requests' => $traffic['total_bot_requests'],
                'human_requests' => $traffic['total_human_requests'],
                'bot_percentage' => $stats['bot_percentage'],
                'human_percentage' => $stats['human_percentage']
            ),
            'top_bots' => $stats['top_bots'],
            'bot_patterns' => $stats['bot_patterns'],
            'bot_activity' => $stats['bot_activity']
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    
    public function render() {
        $activeTab = isset($_GET['tab']) ? (string)$_GET['tab'] : 'viewer';
        if (!in_array($activeTab, $this->tabs, true)) {
            $activeTab = 'viewer';
        }
        
        if (isset($_GET['export']) && $_GET['export'] !== '') {
            $this->handleExport($activeTab, (string)$_GET['export']);
            return;
        }
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Access Log Analysis Dashboard</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background-color: #f5f5f5;
                    color: #333;
                }
                
                .header {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 20px 0;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                
                .header-content {
                    max-width: 1200px;
                    margin: 0 auto;
                    padding: 0 20px;
                }
                
                .header h1 {
                    font-size: 2.5em;
                    margin-bottom: 10px;
                }
                
                .header p {
                    opacity: 0.9;
                    font-size: 1.1em;
                }
                
                .nav-tabs {
                    background: white;
                    border-bottom: 1px solid #ddd;
                    padding: 0 20px;
                    position: sticky;
                    top: 0;
                    z-index: 100;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                }
                
                .nav-tabs ul {
                    list-style: none;
                    display: flex;
                    max-width: 1200px;
                    margin: 0 auto;
                }
                
                .nav-tabs li {
                    margin-right: 2px;
                }
                
                .nav-tabs a {
                    display: block;
                    padding: 15px 25px;
                    text-decoration: none;
                    color: #666;
                    border-bottom: 3px solid transparent;
                    transition: all 0.3s ease;
                }
                
                .nav-tabs a:hover {
                    background-color: #f8f9fa;
                    color: #333;
                }
                
                .nav-tabs a.active {
                    color: #667eea;
                    border-bottom-color: #667eea;
                    background-color: #f8f9fa;
                }
                
                .main-content {
                    max-width: 1200px;
                    margin: 20px auto;
                    padding: 20px;
                }
                
                .tab-content {
                    display: none;
                    background: white;
                    border-radius: 8px;
                    padding: 30px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                
                .tab-content.active {
                    display: block;
                }
                
                .export-buttons {
                    display: flex;
                    gap: 10px;
                    margin-bottom: 20px;
                }
                
                .export-btn {
                    padding: 10px 20px;
                    background: #28a745;
                    color: white;
                    border: none;
                    border-radius: 5px;
                    cursor: pointer;
                    text-decoration: none;
                    transition: background-color 0.3s ease;
                }
                
                .export-btn:hover {
                    background: #218838;
                }
                
                .export-btn.secondary {
                    background: #6c757d;
                }
                
                .export-btn.secondary:hover {
                    background: #5a6268;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="header-content">
                    <h1>Access Log Analysis Dashboard</h1>
                    <p>Group B: Website Log Analysis System</p>
                </div>
            </div>
            
            <div class="nav-tabs">
                <ul>
                    <li><a href="?tab=viewer" class="<?php echo $activeTab === 'viewer' ? 'active' : ''; ?>">Access Log Viewer</a></li>
                    <li><a href="?tab=stats" class="<?php echo $activeTab === 'stats' ? 'active' : ''; ?>">Access Statistics</a></li>
                    <li><a href="?tab=error404" class="<?php echo $activeTab === 'error404' ? 'active' : ''; ?>">404 Error Analysis</a></li>
                    <li><a href="?tab=bot" class="<?php echo $activeTab === 'bot' ? 'active' : ''; ?>">Bot Analyzer</a></li>
                </ul>
            </div>
            
            <div class="main-content">
                <?php if ($activeTab === 'viewer'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=viewer&amp;export=csv" class="export-btn">Export CSV</a>
                            <a href="?tab=viewer&amp;export=json" class="export-btn secondary">Export JSON</a>
                        </div>
                        <?php echo $this->viewer->render(); ?>
                    </div>
                <?php elseif ($activeTab === 'stats'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=stats&amp;export=csv" class="export-btn">Export CSV</a>
                            <a href="?tab=stats&amp;export=json" class="export-btn secondary">Export JSON</a>
                        </div>
                        <?php echo $this->stats->renderCharts(); ?>
                    </div>
                <?php elseif ($activeTab === 'error404'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=error404&amp;export=csv" class="export-btn">Export CSV</a>
                            <a href="?tab=error404&amp;export=json" class="export-btn secondary">Export JSON</a>
                        </div>
                        <?php echo $this->error404->render404Analysis(); ?>
                    </div>
                <?php elseif ($activeTab === 'bot'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=bot&amp;export=csv" class="export-btn">Export CSV</a>
                            <a href="?tab=bot&amp;export=json" class="export-btn secondary">Export JSON</a>
                        </div>
                        <?php echo $this->botAnalyzer->renderBotAnalysis(); ?>
                    </div>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
}

$logAnalysisEntryPoint = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;

if ($logAnalysisEntryPoint !== false && $logAnalysisEntryPoint === realpath(__FILE__)) {
    $dashboard = new LogAnalysisDashboard();
    echo $dashboard->render();
}
