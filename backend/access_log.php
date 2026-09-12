<?php
class AccessLogViewer {
    private $logPath;
    private $currentPage;
    private $itemsPerPage;
    private $filters;
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->logPath = $logPath;
        $this->currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $this->itemsPerPage = 50;
        $this->filters = $this->parseFilters();
    }
    
    private function parseFilters() {
        $filters = [];
        if (isset($_GET['ip'])) $filters['ip'] = $_GET['ip'];
        if (isset($_GET['path'])) $filters['path'] = $_GET['path'];
        if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
        return $filters;
    }
    
    public function getLogs() {
        if (!file_exists($this->logPath)) {
            return [];
        }
        
        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $filteredLogs = [];
        
        foreach ($lines as $line) {
            if ($this->matchesFilters($line)) {
                $filteredLogs[] = $this->parseLogLine($line);
            }
        }
        
        $total = count($filteredLogs);
        $offset = ($this->currentPage - 1) * $this->itemsPerPage;
        $paginatedLogs = array_slice($filteredLogs, $offset, $this->itemsPerPage);
        
        return [
            'logs' => $paginatedLogs,
            'total' => $total,
            'pages' => ceil($total / $this->itemsPerPage),
            'current' => $this->currentPage
        ];
    }
    
    private function matchesFilters($line) {
        if (empty($this->filters)) return true;
        
        if (isset($this->filters['ip']) && strpos($line, $this->filters['ip']) === false) {
            return false;
        }
        
        if (isset($this->filters['path'])) {
            $parsed = $this->parseLogLine($line);
            if (strpos($parsed['request'], $this->filters['path']) === false) {
                return false;
            }
        }
        
        if (isset($this->filters['status'])) {
            $parsed = $this->parseLogLine($line);
            if ($parsed['status'] != $this->filters['status']) {
                return false;
            }
        }
        
        return true;
    }
    
    private function parseLogLine($line) {
        $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d+) (\d+) "([^"]*)" "([^"]*)"/';
        preg_match($pattern, $line, $matches);
        
        return [
            'ip' => $matches[1] ?? '',
            'time' => $matches[2] ?? '',
            'request' => $matches[3] ?? '',
            'status' => (int)($matches[4] ?? 0),
            'size' => (int)($matches[5] ?? 0),
            'referer' => $matches[6] ?? '',
            'user_agent' => $matches[7] ?? ''
        ];
    }
    
    public function render() {
        $logs = $this->getLogs();
        ob_start();
        ?>
        <div class="access-log-viewer">
            <h2>Access Log Viewer</h2>
            
            <div class="filters">
                <form method="GET" class="filter-form">
                    <input type="text" name="ip" placeholder="Filter by IP" value="<?php echo htmlspecialchars($this->filters['ip'] ?? ''); ?>">
                    <input type="text" name="path" placeholder="Filter by Path" value="<?php echo htmlspecialchars($this->filters['path'] ?? ''); ?>">
                    <input type="text" name="status" placeholder="Filter by Status" value="<?php echo htmlspecialchars($this->filters['status'] ?? ''); ?>">
                    <button type="submit">Filter</button>
                    <a href="?">Clear</a>
                </form>
            </div>
            
            <div class="log-table">
                <table>
                    <thead>
                        <tr>
                            <th>IP</th>
                            <th>Time</th>
                            <th>Request</th>
                            <th>Status</th>
                            <th>Size</th>
                            <th>Referer</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs['logs'] as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['ip']); ?></td>
                            <td><?php echo htmlspecialchars($log['time']); ?></td>
                            <td><?php echo htmlspecialchars($log['request']); ?></td>
                            <td><?php echo $log['status']; ?></td>
                            <td><?php echo $log['size']; ?></td>
                            <td><?php echo htmlspecialchars($log['referer']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="pagination">
                <?php if ($logs['current'] > 1): ?>
                    <a href="?page=<?php echo $logs['current'] - 1; ?>">Previous</a>
                <?php endif; ?>
                
                <span>Page <?php echo $logs['current']; ?> of <?php echo $logs['pages']; ?></span>
                
                <?php if ($logs['current'] < $logs['pages']): ?>
                    <a href="?page=<?php echo $logs['current'] + 1; ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
?>