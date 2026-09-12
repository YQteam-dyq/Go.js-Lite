<?php
require_once 'access_log.php';
require_once 'access_stats.php';
require_once 'error_404.php';
require_once 'bot_analyzer.php';

class LogAnalysisDashboard {
    private $viewer;
    private $stats;
    private $error404;
    private $botAnalyzer;
    
    public function __construct($logPath = '/var/log/nginx/access.log') {
        $this->viewer = new AccessLogViewer($logPath);
        $this->stats = new AccessStatistics($logPath);
        $this->error404 = new Error404Analyzer($logPath);
        $this->botAnalyzer = new BotAnalyzer($logPath);
    }
    
    public function render() {
        $activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'viewer';
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="zh-CN">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>网站日志分析仪表板</title>
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
                
                .quick-stats {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                    gap: 20px;
                    margin-bottom: 30px;
                }
                
                .quick-stat-card {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: white;
                    padding: 25px;
                    border-radius: 8px;
                    text-align: center;
                    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
                }
                
                .quick-stat-card h3 {
                    font-size: 1.2em;
                    margin-bottom: 10px;
                    opacity: 0.9;
                }
                
                .quick-stat-card .value {
                    font-size: 2.5em;
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                
                .quick-stat-card .label {
                    font-size: 0.9em;
                    opacity: 0.8;
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
                    <h1>网站日志分析仪表板</h1>
                    <p>并行组 B：网站日志与分析系统</p>
                </div>
            </div>
            
            <div class="nav-tabs">
                <ul>
                    <li><a href="?tab=viewer" class="<?php echo $activeTab == 'viewer' ? 'active' : ''; ?>">Access Log 查看器</a></li>
                    <li><a href="?tab=stats" class="<?php echo $activeTab == 'stats' ? 'active' : ''; ?>">访问统计图表</a></li>
                    <li><a href="?tab=error404" class="<?php echo $activeTab == 'error404' ? 'active' : ''; ?>">404错误分析</a></li>
                    <li><a href="?tab=bot" class="<?php echo $activeTab == 'bot' ? 'active' : ''; ?>">爬虫识别与统计</a></li>
                </ul>
            </div>
            
            <div class="main-content">
                <?php if ($activeTab == 'viewer'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=viewer&export=csv" class="export-btn">导出 CSV</a>
                            <a href="?tab=viewer&export=json" class="export-btn secondary">导出 JSON</a>
                        </div>
                        <?php echo $this->viewer->render(); ?>
                    </div>
                <?php elseif ($activeTab == 'stats'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=stats&export=csv" class="export-btn">导出统计数据</a>
                            <a href="?tab=stats&export=json" class="export-btn secondary">导出 JSON</a>
                        </div>
                        <?php echo $this->stats->renderCharts(); ?>
                    </div>
                <?php elseif ($activeTab == 'error404'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=error404&export=csv" class="export-btn">导出404数据</a>
                            <a href="?tab=error404&export=json" class="export-btn secondary">导出 JSON</a>
                        </div>
                        <?php echo $this->error404->render404Analysis(); ?>
                    </div>
                <?php elseif ($activeTab == 'bot'): ?>
                    <div class="tab-content active">
                        <div class="export-buttons">
                            <a href="?tab=bot&export=csv" class="export-btn">导出爬虫数据</a>
                            <a href="?tab=bot&export=json" class="export-btn secondary">导出 JSON</a>
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

// 初始化仪表板
$dashboard = new LogAnalysisDashboard();
echo $dashboard->render();
?>