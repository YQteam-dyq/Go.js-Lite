<?php

class AccessLogViewer {
    private $logPath;
    private $currentPage;
    private $itemsPerPage;
    private $filters;
    private $parsedLogs = null;
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->logPath = $logPath;
        $this->currentPage = $this->readPage();
        $this->itemsPerPage = 50;
        $this->filters = $this->parseFilters();
    }
    
    private function scalarGet($key) {
        if (!isset($_GET[$key]) || !is_string($_GET[$key])) {
            return '';
        }
        return $_GET[$key];
    }
    
    private function readPage() {
        $page = (int)$this->scalarGet('page');
        return ($page < 1) ? 1 : $page;
    }
    
    private function parseFilters() {
        $filters = [];
        if ($this->scalarGet('ip') !== '') $filters['ip'] = $this->scalarGet('ip');
        if ($this->scalarGet('path') !== '') $filters['path'] = $this->scalarGet('path');
        if ($this->scalarGet('status') !== '') $filters['status'] = $this->scalarGet('status');
        return $filters;
    }
    
    public function getFilters() {
        return $this->filters;
    }
    
    public function buildQuery($overrides = []) {
        $params = [];
        foreach (['tab', 'ip', 'path', 'status'] as $key) {
            $value = $this->scalarGet($key);
            if ($value !== '') {
                $params[$key] = $value;
            }
        }
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($params[$key]);
            } else {
                $params[$key] = $value;
            }
        }
        return http_build_query($params);
    }
    
    public function toTimestamp($time) {
        $parsed = DateTime::createFromFormat('d/M/Y:H:i:s O', $time);
        if ($parsed instanceof DateTime) {
            return $parsed->getTimestamp();
        }
        $fallback = strtotime($time);
        return ($fallback === false) ? null : $fallback;
    }
    
    public function getLatestTimestamp($logs) {
        $latest = null;
        foreach ($logs as $log) {
            if ($log['timestamp'] === null) {
                continue;
            }
            if ($latest === null || $log['timestamp'] > $latest) {
                $latest = $log['timestamp'];
            }
        }
        return $latest;
    }
    
    public function restrictBucketsToRecentWindow($buckets, $hours) {
        if (empty($buckets)) {
            return $buckets;
        }
        
        $latestKey = max(array_keys($buckets));
        $latestStamp = strtotime($latestKey . ':00:00');
        if ($latestStamp === false) {
            $latestStamp = strtotime($latestKey);
        }
        if ($latestStamp === false) {
            return $buckets;
        }
        
        $cutoff = date('Y-m-d H', $latestStamp - (($hours - 1) * 3600));
        
        foreach (array_keys($buckets) as $key) {
            if ($key < $cutoff) {
                unset($buckets[$key]);
            }
        }
        
        return $buckets;
    }
    
    public function getLogs() {
        $filteredLogs = $this->getAllLogs();
        $total = count($filteredLogs);
        $offset = ($this->currentPage - 1) * $this->itemsPerPage;
        $paginatedLogs = array_slice($filteredLogs, $offset, $this->itemsPerPage);
        
        return [
            'logs' => $paginatedLogs,
            'total' => $total,
            'pages' => (int)ceil($total / $this->itemsPerPage),
            'current' => $this->currentPage
        ];
    }
    
    public function getAllLogs() {
        if ($this->parsedLogs !== null) {
            return $this->parsedLogs;
        }
        
        $this->parsedLogs = [];
        
        if (!file_exists($this->logPath) || !is_readable($this->logPath)) {
            return $this->parsedLogs;
        }
        
        $handle = fopen($this->logPath, 'r');
        if ($handle === false) {
            return $this->parsedLogs;
        }
        
        while (($line = fgets($handle)) !== false) {
            $record = $this->parseLogLine(rtrim($line, "\r\n"));
            if ($record === null) {
                continue;
            }
            if ($this->matchesFilters($record)) {
                $this->parsedLogs[] = $record;
            }
        }
        
        fclose($handle);
        
        return $this->parsedLogs;
    }
    
    private function matchesFilters($record) {
        if (empty($this->filters)) {
            return true;
        }
        
        if (isset($this->filters['ip']) && strpos($record['ip'], $this->filters['ip']) === false) {
            return false;
        }
        
        if (isset($this->filters['path']) && strpos($record['request'], $this->filters['path']) === false) {
            return false;
        }
        
        if (isset($this->filters['status']) && (string)$record['status'] !== (string)$this->filters['status']) {
            return false;
        }
        
        return true;
    }
    
    private function parseLogLine($line) {
        $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d+) (-|\d+) "([^"]*)" "([^"]*)"/';
        if (!preg_match($pattern, $line, $matches)) {
            return null;
        }
        
        return [
            'ip' => $matches[1],
            'time' => $matches[2],
            'timestamp' => $this->toTimestamp($matches[2]),
            'request' => $matches[3],
            'status' => (int)$matches[4],
            'size' => ($matches[5] === '-') ? 0 : (int)$matches[5],
            'referer' => $matches[6],
            'user_agent' => $matches[7]
        ];
    }
    
    public function render() {
        $logs = $this->getLogs();
        $filters = $this->filters;
        ob_start();
        ?>
        <div class="access-log-viewer">
            <h2>Access Log Viewer</h2>
            
            <div class="filters">
                <form method="GET" class="filter-form">
                    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($this->scalarGet('tab')); ?>">
                    <input type="text" name="ip" placeholder="Filter by IP" value="<?php echo htmlspecialchars(isset($filters['ip']) ? $filters['ip'] : ''); ?>">
                    <input type="text" name="path" placeholder="Filter by Path" value="<?php echo htmlspecialchars(isset($filters['path']) ? $filters['path'] : ''); ?>">
                    <input type="text" name="status" placeholder="Filter by Status" value="<?php echo htmlspecialchars(isset($filters['status']) ? $filters['status'] : ''); ?>">
                    <button type="submit">Filter</button>
                    <a href="?<?php echo htmlspecialchars($this->buildQuery(['page' => null, 'ip' => null, 'path' => null, 'status' => null])); ?>">Clear</a>
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
                        <?php if (empty($logs['logs'])): ?>
                        <tr>
                            <td colspan="6">No log records match the current filters.</td>
                        </tr>
                        <?php endif; ?>
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
                    <a href="?<?php echo htmlspecialchars($this->buildQuery(['page' => $logs['current'] - 1])); ?>">Previous</a>
                <?php endif; ?>
                
                <span>Page <?php echo $logs['current']; ?> of <?php echo max(1, $logs['pages']); ?></span>
                
                <?php if ($logs['current'] < $logs['pages']): ?>
                    <a href="?<?php echo htmlspecialchars($this->buildQuery(['page' => $logs['current'] + 1])); ?>">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
