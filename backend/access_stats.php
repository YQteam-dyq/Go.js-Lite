<?php
class AccessStatistics {
    private $logPath;
    private $viewer;
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->logPath = $logPath;
        $this->viewer = new AccessLogViewer($logPath);
    }
    
    public function getHourlyStats() {
        $logs = $this->viewer->getLogs();
        $hourlyData = [];
        
        foreach ($logs['logs'] as $log) {
            $hour = substr($log['time'], 0, 13); // Extract hour part
            if (!isset($hourlyData[$hour])) {
                $hourlyData[$hour] = ['pv' => 0, 'uv' => []];
            }
            $hourlyData[$hour]['pv']++;
            $hourlyData[$hour]['uv'][] = $log['ip'];
        }
        
        // Process UV data
        foreach ($hourlyData as $hour => &$data) {
            $data['uv'] = count(array_unique($data['uv']));
        }
        
        return $hourlyData;
    }
    
    public function getDailyStats() {
        $logs = $this->viewer->getLogs();
        $dailyData = [];
        
        foreach ($logs['logs'] as $log) {
            $date = substr($log['time'], 0, 11); // Extract date part
            if (!isset($dailyData[$date])) {
                $dailyData[$date] = ['pv' => 0, 'uv' => []];
            }
            $dailyData[$date]['pv']++;
            $dailyData[$date]['uv'][] = $log['ip'];
        }
        
        // Process UV data
        foreach ($dailyData as $date => &$data) {
            $data['uv'] = count(array_unique($data['uv']));
        }
        
        return $dailyData;
    }
    
    public function getTopPages($limit = 10) {
        $logs = $this->viewer->getLogs();
        $pages = [];
        
        foreach ($logs['logs'] as $log) {
            $path = explode(' ', $log['request'])[1] ?? '';
            if (!isset($pages[$path])) {
                $pages[$path] = ['count' => 0, 'size' => 0];
            }
            $pages[$path]['count']++;
            $pages[$path]['size'] += $log['size'];
        }
        
        uasort($pages, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return array_slice($pages, 0, $limit);
    }
    
    public function getTopIPs($limit = 10) {
        $logs = $this->viewer->getLogs();
        $ips = [];
        
        foreach ($logs['logs'] as $log) {
            if (!isset($ips[$log['ip']])) {
                $ips[$log['ip']] = ['count' => 0, 'size' => 0];
            }
            $ips[$log['ip']]['count']++;
            $ips[$log['ip']]['size'] += $log['size'];
        }
        
        uasort($ips, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return array_slice($ips, 0, $limit);
    }
    
    public function getStatusDistribution() {
        $logs = $this->viewer->getLogs();
        $statusCodes = [];
        
        foreach ($logs['logs'] as $log) {
            $status = $log['status'];
            if (!isset($statusCodes[$status])) {
                $statusCodes[$status] = 0;
            }
            $statusCodes[$status]++;
        }
        
        return $statusCodes;
    }
    
    public function renderCharts() {
        $hourlyStats = $this->getHourlyStats();
        $dailyStats = $this->getDailyStats();
        $topPages = $this->getTopPages();
        $topIPs = $this->getTopIPs();
        $statusDist = $this->getStatusDistribution();
        
        ob_start();
        ?>
        <div class="access-statistics">
            <h2>Access Statistics</h2>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Hourly PV/UV Trends</h3>
                    <div class="chart-container">
                        <canvas id="hourlyChart"></canvas>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>Daily PV/UV Trends</h3>
                    <div class="chart-container">
                        <canvas id="dailyChart"></canvas>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>Top Pages</h3>
                    <div class="top-pages">
                        <table>
                            <thead>
                                <tr>
                                    <th>Path</th>
                                    <th>Views</th>
                                    <th>Total Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topPages as $path => $data): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($path); ?></td>
                                    <td><?php echo $data['count']; ?></td>
                                    <td><?php echo $data['size']; ?> bytes</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>Top Source IPs</h3>
                    <div class="top-ips">
                        <table>
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Requests</th>
                                    <th>Total Size</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($topIPs as $ip => $data): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ip); ?></td>
                                    <td><?php echo $data['count']; ?></td>
                                    <td><?php echo $data['size']; ?> bytes</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>Status Code Distribution</h3>
                    <div class="status-dist">
                        <canvas id="statusChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            // Hourly Chart
            const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
            new Chart(hourlyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_keys($hourlyStats)); ?>,
                    datasets: [{
                        label: 'PV',
                        data: <?php echo json_encode(array_column($hourlyStats, 'pv')); ?>,
                        borderColor: 'rgb(75, 192, 192)',
                        tension: 0.1
                    }, {
                        label: 'UV',
                        data: <?php echo json_encode(array_column($hourlyStats, 'uv')); ?>,
                        borderColor: 'rgb(255, 99, 132)',
                        tension: 0.1
                    }]
                }
            });
            
            // Daily Chart
            const dailyCtx = document.getElementById('dailyChart').getContext('2d');
            new Chart(dailyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_keys($dailyStats)); ?>,
                    datasets: [{
                        label: 'PV',
                        data: <?php echo json_encode(array_column($dailyStats, 'pv')); ?>,
                        borderColor: 'rgb(54, 162, 235)',
                        tension: 0.1
                    }, {
                        label: 'UV',
                        data: <?php echo json_encode(array_column($dailyStats, 'uv')); ?>,
                        borderColor: 'rgb(255, 159, 64)',
                        tension: 0.1
                    }]
                }
            });
            
            // Status Chart
            const statusCtx = document.getElementById('statusChart').getContext('2d');
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_keys($statusDist)); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_values($statusDist)); ?>,
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
        </script>
        
        <style>
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
                gap: 20px;
            }
            .stat-card {
                background: white;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .chart-container {
                height: 300px;
                margin-top: 10px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                padding: 8px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            th {
                background-color: #f5f5f5;
            }
        </style>
        <?php
        return ob_get_clean();
    }
}
?>