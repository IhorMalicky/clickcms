<?php
session_start();
require_once 'database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if website ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$website_id = (int)$_GET['id'];

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get website details
$stmt = $db->prepare("SELECT * FROM websites WHERE id = ? AND user_id = ?");
$stmt->execute([$website_id, $_SESSION['user_id']]);
$website = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$website) {
    header('Location: index.php');
    exit();
}

// Get date range for filtering
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Process date range for SQL queries
$sql_start_date = $start_date . ' 00:00:00';
$sql_end_date = $end_date . ' 23:59:59';

// Get visitor statistics
$stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT v.id) as total_visitors,
        COUNT(p.id) as total_pageviews,
        ROUND(COUNT(p.id) / COUNT(DISTINCT v.id), 2) as pages_per_visitor,
        AVG(s.session_duration) as avg_session_duration
    FROM visitors v
    LEFT JOIN page_views p ON v.id = p.visitor_id
    LEFT JOIN sessions s ON v.id = s.visitor_id
    WHERE v.website_id = ?
    AND v.created_at BETWEEN ? AND ?
");
$stmt->execute([$website_id, $sql_start_date, $sql_end_date]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get daily visitor data for chart
$stmt = $db->prepare("
    SELECT 
        DATE(v.created_at) as date,
        COUNT(DISTINCT v.id) as visitors,
        COUNT(p.id) as pageviews
    FROM visitors v
    LEFT JOIN page_views p ON v.id = p.visitor_id AND p.created_at BETWEEN ? AND ?
    WHERE v.website_id = ?
    AND v.created_at BETWEEN ? AND ?
    GROUP BY DATE(v.created_at)
    ORDER BY date
");
$stmt->execute([$sql_start_date, $sql_end_date, $website_id, $sql_start_date, $sql_end_date]);
$daily_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top referrers
$stmt = $db->prepare("
    SELECT 
        COALESCE(referrer, '(direct)') as referrer,
        COUNT(DISTINCT id) as visitors
    FROM visitors
    WHERE website_id = ?
    AND created_at BETWEEN ? AND ?
    GROUP BY referrer
    ORDER BY visitors DESC
    LIMIT 10
");
$stmt->execute([$website_id, $sql_start_date, $sql_end_date]);
$referrers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top pages
$stmt = $db->prepare("
    SELECT 
        page_url,
        COUNT(*) as pageviews,
        AVG(time_on_page) as avg_time_on_page
    FROM page_views
    WHERE website_id = ?
    AND created_at BETWEEN ? AND ?
    GROUP BY page_url
    ORDER BY pageviews DESC
    LIMIT 10
");
$stmt->execute([$website_id, $sql_start_date, $sql_end_date]);
$top_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get device statistics
$stmt = $db->prepare("
    SELECT 
        COALESCE(device, 'Unknown') as device,
        COUNT(DISTINCT id) as visitors
    FROM visitors
    WHERE website_id = ?
    AND created_at BETWEEN ? AND ?
    GROUP BY device
    ORDER BY visitors DESC
");
$stmt->execute([$website_id, $sql_start_date, $sql_end_date]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get browser statistics
$stmt = $db->prepare("
    SELECT 
        COALESCE(browser, 'Unknown') as browser,
        COUNT(DISTINCT id) as visitors
    FROM visitors
    WHERE website_id = ?
    AND created_at BETWEEN ? AND ?
    GROUP BY browser
    ORDER BY visitors DESC
");
$stmt->execute([$website_id, $sql_start_date, $sql_end_date]);
$browsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - <?= htmlspecialchars($website['name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/chart.js/3.9.1/chart.min.js"></script>
</head>
<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><?= htmlspecialchars($website['name']) ?> - Analytics</h1>
            <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
        </div>
        
        <div class="card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end">
                    <input type="hidden" name="id" value="<?= $website_id ?>">
                    <div class="col-md-4">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary w-100">Apply Filters</button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?= number_format($stats['total_visitors']) ?></h3>
                        <p class="mb-0">Total Visitors</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?= number_format($stats['total_pageviews']) ?></h3>
                        <p class="mb-0">Total Pageviews</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?= number_format($stats['pages_per_visitor'], 2) ?></h3>
                        <p class="mb-0">Pages/Visitor</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3><?= gmdate("i:s", $stats['avg_session_duration'] ?? 0) ?></h3>
                        <p class="mb-0">Avg. Session Duration</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">Visitor Trends</h5>
            </div>
            <div class="card-body">
                <canvas id="visitorChart" height="100"></canvas>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Top Referrers</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Referrer</th>
                                        <th class="text-end">Visitors</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($referrers as $referrer): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($referrer['referrer']) ?></td>
                                            <td class="text-end"><?= number_format($referrer['visitors']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($referrers)): ?>
                                        <tr>
                                            <td colspan="2" class="text-center">No data available</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Top Pages</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Page</th>
                                        <th class="text-end">Views</th>
                                        <th class="text-end">Avg. Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_pages as $page): ?>
                                        <tr>
                                            <td title="<?= htmlspecialchars($page['page_url']) ?>"><?= htmlspecialchars(substr($page['page_url'], 0, 40)) . (strlen($page['page_url']) > 40 ? '...' : '') ?></td>
                                            <td class="text-end"><?= number_format($page['pageviews']) ?></td>
                                            <td class="text-end"><?= gmdate("i:s", $page['avg_time_on_page']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($top_pages)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center">No data available</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Device Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="deviceChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Browser Distribution</h5>
                    </div>
                    <div class="card-body">
                        <canvas id="browserChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
    <script>
        // Chart color palette
        const colors = [
            'rgba(54, 162, 235, 0.8)',
            'rgba(255, 99, 132, 0.8)',
            'rgba(75, 192, 192, 0.8)',
            'rgba(255, 206, 86, 0.8)',
            'rgba(153, 102, 255, 0.8)',
            'rgba(255, 159, 64, 0.8)',
            'rgba(199, 199, 199, 0.8)',
            'rgba(83, 102, 255, 0.8)',
            'rgba(40, 159, 64, 0.8)',
            'rgba(210, 199, 199, 0.8)'
        ];
        
        // Visitor trend chart
        const visitorData = <?= json_encode($daily_stats) ?>;
        const dates = visitorData.map(item => item.date);
        const visitors = visitorData.map(item => item.visitors);
        const pageviews = visitorData.map(item => item.pageviews);
        
        new Chart(document.getElementById('visitorChart'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Visitors',
                        data: visitors,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Pageviews',
                        data: pageviews,
                        borderColor: 'rgba(75, 192, 192, 1)',
                        backgroundColor: 'rgba(75, 192, 192, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    },
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        display: true,
                        title: {
                            display: true,
                            text: 'Count'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
        
        // Device chart
        const deviceData = <?= json_encode($devices) ?>;
        new Chart(document.getElementById('deviceChart'), {
            type: 'doughnut',
            data: {
                labels: deviceData.map(item => item.device),
                datasets: [{
                    data: deviceData.map(item => item.visitors),
                    backgroundColor: colors.slice(0, deviceData.length)
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
        
        // Browser chart
        const browserData = <?= json_encode($browsers) ?>;
        new Chart(document.getElementById('browserChart'), {
            type: 'doughnut',
            data: {
                labels: browserData.map(item => item.browser),
                datasets: [{
                    data: browserData.map(item => item.visitors),
                    backgroundColor: colors.slice(0, browserData.length)
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'right',
                    }
                }
            }
        });
    </script>
</body>
</html>