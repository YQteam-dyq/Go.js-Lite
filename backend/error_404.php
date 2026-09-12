<?php
require_once __DIR__ . '/access_log.php';

class Error404Analyzer {
    private $logPath;
    private $viewer;
    private $statsCache = null;
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->logPath = $logPath;
        $this->viewer = new AccessLogViewer($logPath);
    }
    
    public function get404Errors() {
        $errors404 = [];
        
        foreach ($this->viewer->getAllLogs() as $log) {
            if ((int)$log['status'] === 404) {
                $errors404[] = $log;
            }
        }
        
        return $errors404;
    }
    
    public function get404Stats() {
        if ($this->statsCache !== null) {
            return $this->statsCache;
        }
        
        $errors404 = $this->get404Errors();
        $stats = [
            'total' => count($errors404),
            'unique_paths' => [],
            'unique_ips' => [],
            'broken_pages' => [],
            'error_timeline' => []
        ];
        
        foreach ($errors404 as $error) {
            $path = $this->requestPath($error['request']);
            
            if (!isset($stats['unique_paths'][$path])) {
                $stats['unique_paths'][$path] = 0;
            }
            $stats['unique_paths'][$path]++;
            
            if (!isset($stats['unique_ips'][$error['ip']])) {
                $stats['unique_ips'][$error['ip']] = 0;
            }
            $stats['unique_ips'][$error['ip']]++;
            
            if (!isset($stats['broken_pages'][$path])) {
                $stats['broken_pages'][$path] = [
                    'count' => 0,
                    'ips' => [],
                    'first_timestamp' => null,
                    'last_timestamp' => null
                ];
            }
            $stats['broken_pages'][$path]['count']++;
            $stats['broken_pages'][$path]['ips'][] = $error['ip'];
            
            $timestamp = isset($error['timestamp']) ? $error['timestamp'] : null;
            if ($timestamp !== null) {
                $current = $stats['broken_pages'][$path];
                if ($current['first_timestamp'] === null || $timestamp < $current['first_timestamp']) {
                    $stats['broken_pages'][$path]['first_timestamp'] = $timestamp;
                }
                if ($current['last_timestamp'] === null || $timestamp > $current['last_timestamp']) {
                    $stats['broken_pages'][$path]['last_timestamp'] = $timestamp;
                }
            }
            
            $hour = ($timestamp === null) ? null : date('Y-m-d H', $timestamp);
            if ($hour !== null) {
                if (!isset($stats['error_timeline'][$hour])) {
                    $stats['error_timeline'][$hour] = 0;
                }
                $stats['error_timeline'][$hour]++;
            }
        }
        
        uasort($stats['broken_pages'], function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        ksort($stats['error_timeline']);
        $stats['error_timeline'] = $this->viewer->restrictBucketsToRecentWindow($stats['error_timeline'], 24);
        
        uasort($stats['unique_paths'], function($a, $b) {
            return $b - $a;
        });
        
        arsort($stats['unique_ips']);
        
        $this->statsCache = $stats;
        
        return $this->statsCache;
    }
    
    public function analyzeDeadLinks() {
        $stats = $this->get404Stats();
        $deadLinks = [];
        
        foreach ($stats['broken_pages'] as $path => $data) {
            if ($data['count'] > 5) {
                $deadLinks[] = [
                    'path' => $path,
                    'error_count' => $data['count'],
                    'unique_ips' => count(array_unique($data['ips'])),
                    'first_timestamp' => $data['first_timestamp'],
                    'last_timestamp' => $data['last_timestamp'],
                    'affected_ips' => array_unique($data['ips'])
                ];
            }
        }
        
        usort($deadLinks, function($a, $b) {
            return $b['error_count'] - $a['error_count'];
        });
        
        return $deadLinks;
    }
    
    public function getTopErrorIPs($limit = 10) {
        $stats = $this->get404Stats();
        return array_slice($stats['unique_ips'], 0, $limit, true);
    }
    
    public function render404Analysis() {
        $stats = $this->get404Stats();
        $deadLinks = $this->analyzeDeadLinks();
        $topErrorIPs = $this->getTopErrorIPs();
        
        ob_start();
        ?>
        <div class="error-404-analysis">
            <h2>404 Error Analysis</h2>
            
            <div class="summary-stats">
                <div class="stat-item">
                    <h3>Total 404 Errors</h3>
                    <div class="stat-value"><?php echo $stats['total']; ?></div>
                </div>
                <div class="stat-item">
                    <h3>Unique Broken Pages</h3>
                    <div class="stat-value"><?php echo count($stats['unique_paths']); ?></div>
                </div>
                <div class="stat-item">
                    <h3>Unique IPs with 404s</h3>
                    <div class="stat-value"><?php echo count($stats['unique_ips']); ?></div>
                </div>
            </div>
            
            <div class="analysis-tabs">
                <button class="tab-button active" onclick="showTab('timeline', event)">Error Timeline</button>
                <button class="tab-button" onclick="showTab('broken-pages', event)">Broken Pages</button>
                <button class="tab-button" onclick="showTab('dead-links', event)">Dead Links</button>
                <button class="tab-button" onclick="showTab('ip-analysis', event)">IP Analysis</button>
            </div>
            
            <div id="timeline" class="tab-content active">
                <h3>Error Timeline (Last 24 Hours)</h3>
                <div class="chart-container">
                    <canvas id="timelineChart"></canvas>
                </div>
            </div>
            
            <div id="broken-pages" class="tab-content">
                <h3>Most Frequently Broken Pages</h3>
                <div class="broken-pages-list">
                    <table>
                        <thead>
                            <tr>
                                <th>Path</th>
                                <th>Error Count</th>
                                <th>Unique IPs</th>
                                <th>First Error</th>
                                <th>Last Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['broken_pages'] as $path => $data): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($path); ?></td>
                                <td><?php echo $data['count']; ?></td>
                                <td><?php echo count(array_unique($data['ips'])); ?></td>
                                <td><?php echo $this->formatTimestamp($data['first_timestamp']); ?></td>
                                <td><?php echo $this->formatTimestamp($data['last_timestamp']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="dead-links" class="tab-content">
                <h3>Potential Dead Links</h3>
                <div class="dead-links-list">
                    <?php if (empty($deadLinks)): ?>
                    <p>No significant dead links found.</p>
                    <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Path</th>
                                <th>Error Count</th>
                                <th>Unique IPs</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deadLinks as $link): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($link['path']); ?></td>
                                <td><?php echo $link['error_count']; ?></td>
                                <td><?php echo $link['unique_ips']; ?></td>
                                <td><?php echo $this->getTimeDiff($link['first_timestamp'], $link['last_timestamp']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <div id="ip-analysis" class="tab-content">
                <h3>IPs Generating Most 404s</h3>
                <div class="top-ips">
                    <table>
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>404 Count</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topErrorIPs as $ip => $count): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ip); ?></td>
                                <td><?php echo $count; ?></td>
                                <td><?php echo $stats['total'] > 0 ? round(($count / $stats['total']) * 100, 2) : 0; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            new Chart(document.getElementById('timelineChart'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_keys($stats['error_timeline'])); ?>,
                    datasets: [{
                        label: '404 Errors',
                        data: <?php echo json_encode(array_values($stats['error_timeline'])); ?>,
                        borderColor: 'rgb(255, 99, 132)',
                        backgroundColor: 'rgba(255, 99, 132, 0.1)',
                        tension: 0.1
                    }]
                }
            });
            
            function showTab(tabName, evt) {
                document.querySelectorAll('.tab-content').forEach(function(tab) {
                    tab.classList.remove('active');
                });
                document.querySelectorAll('.tab-button').forEach(function(button) {
                    button.classList.remove('active');
                });
                document.getElementById(tabName).classList.add('active');
                if (evt && evt.target) {
                    evt.target.classList.add('active');
                }
            }
        </script>
        
        <style>
            .summary-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            .stat-item {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                text-align: center;
            }
            .stat-value {
                font-size: 2em;
                font-weight: bold;
                color: #e74c3c;
            }
            .analysis-tabs {
                display: flex;
                margin-bottom: 20px;
                border-bottom: 1px solid #ddd;
            }
            .tab-button {
                padding: 10px 20px;
                border: none;
                background: none;
                cursor: pointer;
                border-bottom: 2px solid transparent;
            }
            .tab-button.active {
                border-bottom-color: #3498db;
                color: #3498db;
            }
            .tab-content {
                display: none;
            }
            .tab-content.active {
                display: block;
            }
            .chart-container {
                height: 300px;
                margin-bottom: 20px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                background: white;
                border-radius: 8px;
                overflow: hidden;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            th, td {
                padding: 12px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            th {
                background-color: #f8f9fa;
                font-weight: 600;
            }
            button {
                padding: 6px 12px;
                margin: 0 4px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    private function requestPath($request) {
        $parts = explode(' ', $request);
        return isset($parts[1]) ? $parts[1] : '';
    }
    
    private function formatTimestamp($timestamp) {
        if ($timestamp === null) {
            return 'n/a';
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
    
    private function getTimeDiff($startTimestamp, $endTimestamp) {
        if ($startTimestamp === null || $endTimestamp === null) {
            return 'n/a';
        }
        
        $seconds = $endTimestamp - $startTimestamp;
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }
        
        $hours = (int)floor($seconds / 3600);
        $minutes = (int)floor(($seconds % 3600) / 60);
        
        return $hours . ' hours ' . $minutes . ' minutes';
    }
}
