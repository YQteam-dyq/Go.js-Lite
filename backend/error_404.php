<?php
class Error404Analyzer {
    private $logPath;
    private $viewer;
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->logPath = $logPath;
        $this->viewer = new AccessLogViewer($logPath);
    }
    
    public function get404Errors() {
        $logs = $this->viewer->getLogs();
        $errors404 = [];
        
        foreach ($logs['logs'] as $log) {
            if ($log['status'] == 404) {
                $errors404[] = $log;
            }
        }
        
        return $errors404;
    }
    
    public function get404Stats() {
        $errors404 = $this->get404Errors();
        $stats = [
            'total' => count($errors404),
            'unique_paths' => [],
            'unique_ips' => [],
            'broken_pages' => [],
            'error_timeline' => []
        ];
        
        foreach ($errors404 as $error) {
            // Count unique paths
            $path = explode(' ', $error['request'])[1] ?? '';
            if (!isset($stats['unique_paths'][$path])) {
                $stats['unique_paths'][$path] = 0;
            }
            $stats['unique_paths'][$path]++;
            
            // Count unique IPs
            if (!isset($stats['unique_ips'][$error['ip']])) {
                $stats['unique_ips'][$error['ip']] = 0;
            }
            $stats['unique_ips'][$error['ip']]++;
            
            // Group broken pages by path
            if (!isset($stats['broken_pages'][$path])) {
                $stats['broken_pages'][$path] = [
                    'count' => 0,
                    'ips' => [],
                    'times' => []
                ];
            }
            $stats['broken_pages'][$path]['count']++;
            $stats['broken_pages'][$path]['ips'][] = $error['ip'];
            $stats['broken_pages'][$path]['times'][] = $error['time'];
        }
        
        // Sort broken pages by count
        uasort($stats['broken_pages'], function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        // Build error timeline
        foreach ($errors404 as $error) {
            $hour = substr($error['time'], 0, 13);
            if (!isset($stats['error_timeline'][$hour])) {
                $stats['error_timeline'][$hour] = 0;
            }
            $stats['error_timeline'][$hour]++;
        }
        
        ksort($stats['error_timeline']);
        
        return $stats;
    }
    
    public function analyzeDeadLinks() {
        $stats = $this->get404Stats();
        $deadLinks = [];
        
        foreach ($stats['broken_pages'] as $path => $data) {
            if ($data['count'] > 5) { // Consider as dead link if more than 5 errors
                $deadLinks[] = [
                    'path' => $path,
                    'error_count' => $data['count'],
                    'unique_ips' => count(array_unique($data['ips'])),
                    'first_error' => min($data['times']),
                    'last_error' => max($data['times']),
                    'affected_ips' => array_unique($data['ips'])
                ];
            }
        }
        
        // Sort by error count
        usort($deadLinks, function($a, $b) {
            return $b['error_count'] - $a['error_count'];
        });
        
        return $deadLinks;
    }
    
    public function render404Analysis() {
        $stats = $this->get404Stats();
        $deadLinks = $this->analyzeDeadLinks();
        
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
                <button class="tab-button active" onclick="showTab('timeline')">Error Timeline</button>
                <button class="tab-button" onclick="showTab('broken-pages')">Broken Pages</button>
                <button class="tab-button" onclick="showTab('dead-links')">Dead Links</button>
                <button class="tab-button" onclick="showTab('ip-analysis')">IP Analysis</button>
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
                                <td><?php echo min($data['times']); ?></td>
                                <td><?php echo max($data['times']); ?></td>
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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deadLinks as $link): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($link['path']); ?></td>
                                <td><?php echo $link['error_count']; ?></td>
                                <td><?php echo $link['unique_ips']; ?></td>
                                <td><?php echo $this->getTimeDiff($link['first_error'], $link['last_error']); ?></td>
                                <td>
                                    <button onclick="fixLink('<?php echo addslashes($link['path']); ?>')">Fix Link</button>
                                    <button onclick="removeLink('<?php echo addslashes($link['path']); ?>')">Remove</button>
                                </td>
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
                            <?php 
                            $topIPs = array_slice($stats['unique_ips'], 0, 10);
                            $total404s = $stats['total'];
                            foreach ($topIPs as $ip => $count): 
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ip); ?></td>
                                <td><?php echo $count; ?></td>
                                <td><?php echo round(($count / $total404s) * 100, 2); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            // Timeline Chart
            const timelineCtx = document.getElementById('timelineChart').getContext('2d');
            new Chart(timelineCtx, {
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
            
            function showTab(tabName) {
                // Hide all tab contents
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Remove active class from all buttons
                document.querySelectorAll('.tab-button').forEach(button => {
                    button.classList.remove('active');
                });
                
                // Show selected tab
                document.getElementById(tabName).classList.add('active');
                event.target.classList.add('active');
            }
            
            function fixLink(path) {
                if (confirm('Do you want to fix the link: ' + path)) {
                    // Implement link fixing logic here
                    alert('Fix functionality not implemented yet');
                }
            }
            
            function removeLink(path) {
                if (confirm('Do you want to remove the link: ' + path)) {
                    // Implement link removal logic here
                    alert('Remove functionality not implemented yet');
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
            
            .fix-btn {
                background-color: #3498db;
                color: white;
            }
            
            .remove-btn {
                background-color: #e74c3c;
                color: white;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    private function getTimeDiff($startTime, $endTime) {
        $start = new DateTime($startTime);
        $end = new DateTime($endTime);
        $interval = $start->diff($end);
        return $interval->format('%h hours %i minutes');
    }
}
?>