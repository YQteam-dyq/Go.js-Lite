<?php
require_once __DIR__ . '/access_log.php';

class BotAnalyzer {
    private $logPath;
    private $viewer;
    private $trafficCache = null;
    private $botSignatures = [
        'Googlebot' => ['googlebot'],
        'Bingbot' => ['bingbot'],
        'Slurp' => ['yahoo slurp'],
        'DuckDuckBot' => ['duckduckbot'],
        'Baiduspider' => ['baiduspider'],
        'YandexBot' => ['yandexbot'],
        'Facebot' => ['facebookexternalhit'],
        'Twitterbot' => ['twitterbot'],
        'LinkedInBot' => ['linkedinbot'],
        'SemrushBot' => ['semrushbot'],
        'Screaming Frog' => ['screamingfrog seo spider'],
        'AhrefsBot' => ['ahrefsbot'],
        'MJ12bot' => ['mj12bot'],
        'Dotbot' => ['dotbot'],
        'GPTBot' => ['gptbot'],
        'ClaudeBot' => ['claudebot'],
        'GenericBot' => ['bot', 'crawler', 'spider', 'scraper']
    ];
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->logPath = $logPath;
        $this->viewer = new AccessLogViewer($logPath);
    }
    
    public function isBot($userAgent) {
        if (empty($userAgent)) {
            return false;
        }
        
        $userAgent = strtolower($userAgent);
        
        foreach ($this->botSignatures as $botName => $signatures) {
            foreach ($signatures as $signature) {
                if (strpos($userAgent, $signature) !== false) {
                    return $botName;
                }
            }
        }
        
        return false;
    }
    
    public function getBotTraffic() {
        if ($this->trafficCache !== null) {
            return $this->trafficCache;
        }
        
        $allLogs = $this->viewer->getAllLogs();
        $botTraffic = [];
        $humanTraffic = [];
        
        foreach ($allLogs as $log) {
            $botName = $this->isBot($log['user_agent']);
            $path = $this->requestPath($log['request']);
            
            if ($botName) {
                if (!isset($botTraffic[$botName])) {
                    $botTraffic[$botName] = [
                        'count' => 0,
                        'size' => 0,
                        'ips' => [],
                        'paths' => [],
                        'timeline' => []
                    ];
                }
                $botTraffic[$botName]['count']++;
                $botTraffic[$botName]['size'] += $log['size'];
                $botTraffic[$botName]['ips'][] = $log['ip'];
                $botTraffic[$botName]['paths'][] = $path;
                if ($log['timestamp'] !== null) {
                    $botTraffic[$botName]['timeline'][] = date('Y-m-d H', $log['timestamp']);
                }
            } else {
                if (!isset($humanTraffic[$log['ip']])) {
                    $humanTraffic[$log['ip']] = [
                        'count' => 0,
                        'size' => 0,
                        'paths' => []
                    ];
                }
                $humanTraffic[$log['ip']]['count']++;
                $humanTraffic[$log['ip']]['size'] += $log['size'];
                $humanTraffic[$log['ip']]['paths'][] = $path;
            }
        }
        
        foreach ($botTraffic as $botName => $data) {
            $botTraffic[$botName]['unique_ips'] = count(array_unique($data['ips']));
            $botTraffic[$botName]['unique_paths'] = count(array_unique($data['paths']));
            $timeline = array_count_values($data['timeline']);
            ksort($timeline);
            $botTraffic[$botName]['timeline'] = $timeline;
        }
        
        foreach ($humanTraffic as $ip => $data) {
            $humanTraffic[$ip]['unique_paths'] = count(array_unique($data['paths']));
        }
        
        $this->trafficCache = [
            'bot_traffic' => $botTraffic,
            'human_traffic' => $humanTraffic,
            'total_bot_requests' => array_sum(array_column($botTraffic, 'count')),
            'total_human_requests' => array_sum(array_column($humanTraffic, 'count'))
        ];
        
        return $this->trafficCache;
    }
    
    public function getBotStatistics() {
        $traffic = $this->getBotTraffic();
        $totalRequests = $traffic['total_bot_requests'] + $traffic['total_human_requests'];
        
        $stats = [
            'total_requests' => $totalRequests,
            'bot_percentage' => 0,
            'human_percentage' => 0,
            'top_bots' => [],
            'bot_activity' => [],
            'bot_patterns' => []
        ];
        
        if ($totalRequests > 0) {
            $stats['bot_percentage'] = round(($traffic['total_bot_requests'] / $totalRequests) * 100, 2);
            $stats['human_percentage'] = round(100 - $stats['bot_percentage'], 2);
        }
        
        foreach ($traffic['bot_traffic'] as $botName => $data) {
            $stats['top_bots'][] = [
                'name' => $botName,
                'count' => $data['count'],
                'size' => $data['size'],
                'unique_ips' => $data['unique_ips'],
                'percentage' => $totalRequests > 0 ? round(($data['count'] / $totalRequests) * 100, 2) : 0
            ];
            
            $stats['bot_activity'][$botName] = $data['timeline'];
            
            $stats['bot_patterns'][$botName] = [
                'avg_requests_per_ip' => $data['unique_ips'] > 0 ? round($data['count'] / $data['unique_ips'], 2) : 0,
                'most_requested_path' => $this->getMostRequestedPath($data['paths']),
                'unique_paths_ratio' => $data['count'] > 0 ? round(($data['unique_paths'] / $data['count']) * 100, 2) : 0
            ];
        }
        
        usort($stats['top_bots'], function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return $stats;
    }
    
    private function getMostRequestedPath($paths) {
        if (empty($paths)) {
            return '';
        }
        
        $pathCounts = array_count_values($paths);
        arsort($pathCounts);
        $keys = array_keys($pathCounts);
        
        return $keys[0];
    }
    
    private function requestPath($request) {
        $parts = explode(' ', $request);
        return isset($parts[1]) ? $parts[1] : '';
    }
    
    private function chartColor($index) {
        $palette = [
            'rgb(255, 99, 132)',
            'rgb(54, 162, 235)',
            'rgb(255, 205, 86)',
            'rgb(75, 192, 192)',
            'rgb(153, 102, 255)',
            'rgb(255, 159, 64)',
            'rgb(46, 204, 113)',
            'rgb(231, 76, 60)',
            'rgb(52, 73, 94)',
            'rgb(26, 188, 156)'
        ];
        
        return $palette[$index % count($palette)];
    }
    
    public function renderBotAnalysis() {
        $stats = $this->getBotStatistics();
        $traffic = $this->getBotTraffic();
        
        ob_start();
        ?>
        <div class="bot-analysis">
            <h2>Bot Traffic Analysis</h2>
            
            <div class="summary-stats">
                <div class="stat-item">
                    <h3>Total Requests</h3>
                    <div class="stat-value"><?php echo $stats['total_requests']; ?></div>
                </div>
                <div class="stat-item">
                    <h3>Bot Traffic</h3>
                    <div class="stat-value"><?php echo $stats['bot_percentage']; ?>%</div>
                    <small>(<?php echo $traffic['total_bot_requests']; ?> requests)</small>
                </div>
                <div class="stat-item">
                    <h3>Human Traffic</h3>
                    <div class="stat-value"><?php echo $stats['human_percentage']; ?>%</div>
                    <small>(<?php echo $traffic['total_human_requests']; ?> requests)</small>
                </div>
            </div>
            
            <div class="analysis-tabs">
                <button class="tab-button active" onclick="showBotTab('bot-overview', event)">Bot Overview</button>
                <button class="tab-button" onclick="showBotTab('bot-activity', event)">Activity Patterns</button>
                <button class="tab-button" onclick="showBotTab('bot-patterns', event)">Bot Patterns</button>
                <button class="tab-button" onclick="showBotTab('comparison', event)">Traffic Comparison</button>
            </div>
            
            <div id="bot-overview" class="tab-content active">
                <h3>Top Bots by Request Count</h3>
                <div class="bot-list">
                    <table>
                        <thead>
                            <tr>
                                <th>Bot Name</th>
                                <th>Requests</th>
                                <th>Size (bytes)</th>
                                <th>Unique IPs</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['top_bots'] as $bot): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($bot['name']); ?></td>
                                <td><?php echo $bot['count']; ?></td>
                                <td><?php echo number_format($bot['size']); ?></td>
                                <td><?php echo $bot['unique_ips']; ?></td>
                                <td><?php echo $bot['percentage']; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="bot-activity" class="tab-content">
                <h3>Bot Activity Timeline</h3>
                <div class="activity-charts">
                    <?php foreach ($stats['bot_activity'] as $botName => $timeline): ?>
                    <div class="bot-chart">
                        <h4><?php echo htmlspecialchars($botName); ?></h4>
                        <div class="chart-container">
                            <canvas id="activity-<?php echo md5($botName); ?>"></canvas>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div id="bot-patterns" class="tab-content">
                <h3>Bot Behavior Patterns</h3>
                <div class="bot-patterns">
                    <table>
                        <thead>
                            <tr>
                                <th>Bot Name</th>
                                <th>Avg Requests/IP</th>
                                <th>Most Requested Path</th>
                                <th>Unique Paths Ratio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['bot_patterns'] as $botName => $pattern): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($botName); ?></td>
                                <td><?php echo $pattern['avg_requests_per_ip']; ?></td>
                                <td><?php echo htmlspecialchars($pattern['most_requested_path']); ?></td>
                                <td><?php echo $pattern['unique_paths_ratio']; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div id="comparison" class="tab-content">
                <h3>Bot vs Human Traffic Comparison</h3>
                <div class="comparison-charts">
                    <div class="chart-container">
                        <canvas id="trafficComparison"></canvas>
                    </div>
                    <div class="chart-container">
                        <canvas id="botDistribution"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            new Chart(document.getElementById('trafficComparison'), {
                type: 'doughnut',
                data: {
                    labels: ['Bot Traffic', 'Human Traffic'],
                    datasets: [{
                        data: [<?php echo $stats['bot_percentage']; ?>, <?php echo $stats['human_percentage']; ?>],
                        backgroundColor: [
                            'rgb(255, 99, 132)',
                            'rgb(54, 162, 235)'
                        ]
                    }]
                }
            });
            
            new Chart(document.getElementById('botDistribution'), {
                type: 'pie',
                data: {
                    labels: <?php echo json_encode(array_column($stats['top_bots'], 'name')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($stats['top_bots'], 'count')); ?>,
                        backgroundColor: [
                            'rgb(255, 99, 132)',
                            'rgb(54, 162, 235)',
                            'rgb(255, 205, 86)',
                            'rgb(75, 192, 192)',
                            'rgb(153, 102, 255)'
                        ]
                    }]
                }
            });
            
            <?php $botIndex = 0; foreach ($stats['bot_activity'] as $botName => $timeline): ?>
            new Chart(document.getElementById('activity-<?php echo md5($botName); ?>'), {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_keys($timeline)); ?>,
                    datasets: [{
                        label: <?php echo json_encode($botName); ?>,
                        data: <?php echo json_encode(array_values($timeline)); ?>,
                        borderColor: '<?php echo $this->chartColor($botIndex); ?>',
                        tension: 0.1
                    }]
                }
            });
            <?php $botIndex++; endforeach; ?>
            
            function showBotTab(tabName, evt) {
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
            .bot-chart {
                margin-bottom: 30px;
                padding: 20px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .comparison-charts {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
