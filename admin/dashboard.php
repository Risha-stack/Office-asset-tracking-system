<?php
// Admin Dashboard - admin/dashboard.php
require_once __DIR__ . '/../includes/header.php';

// Check if unauthorized access occurred from other scripts
$warning_alert = "";
if (isset($_GET['error']) && $_GET['error'] === 'unauthorized') {
    $warning_alert = "Access denied! Administrative credentials are required to perform that operation.";
}

// 1. Fetch count metrics for cards
$total_assets = 0;
$assigned_assets = 0;
$available_assets = 0;
$damaged_assets = 0;

try {
    $total_assets = $pdo->query("SELECT COUNT(*) FROM assets")->fetchColumn();
    $assigned_assets = $pdo->query("SELECT COUNT(*) FROM assets WHERE status = 'Assigned'")->fetchColumn();
    $available_assets = $pdo->query("SELECT COUNT(*) FROM assets WHERE status = 'Available'")->fetchColumn();
    $damaged_assets = $pdo->query("SELECT COUNT(*) FROM assets WHERE status = 'Damaged'")->fetchColumn();
} catch (PDOException $e) {
    // Seeder may not be executed yet
}

// 2. Fetch category metrics for SVG Chart
$chart_labels = [];
$chart_values = [];

try {
    $stmt = $pdo->query("SELECT c.name, COUNT(a.id) as count 
                         FROM categories c 
                         LEFT JOIN assets a ON c.id = a.category_id 
                         GROUP BY c.id");
    $cat_data = $stmt->fetchAll();
    
    foreach ($cat_data as $row) {
        $chart_labels[] = $row->name;
        $chart_values[] = $row->count;
    }
} catch (PDOException $e) {
    // Fail-safe default values
    $chart_labels = ["Laptops", "Monitors", "Printers", "Routers", "Furniture", "Keyboards"];
    $chart_values = [0, 0, 0, 0, 0, 0];
}

$chart_labels_str = implode(',', $chart_labels);
$chart_values_str = implode(',', $chart_values);

// 3. Fetch Recent Activity Logs
// We can retrieve the last 5 assignments or status edits
$activities = [];
try {
    $stmt = $pdo->query("SELECT a.assigned_date, a.return_date, a.status, e.first_name, e.last_name, ast.name as asset_name 
                         FROM assignments a 
                         JOIN employees e ON a.employee_id = e.id 
                         JOIN assets ast ON a.asset_id = ast.id 
                         ORDER BY a.id DESC LIMIT 5");
    $activities = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fail-safe empty activity array
}
?>

<?php if (!empty($warning_alert)): ?>
    <div class="alert alert-danger animate-fade-in" style="margin-bottom: 24px;">
        <i class="fa-solid fa-shield-halved"></i>
        <span><?php echo $warning_alert; ?></span>
        <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
<?php endif; ?>

<!-- 1. Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card total">
        <div class="stat-info">
            <h3>Total Assets</h3>
            <div class="stat-value"><?php echo $total_assets; ?></div>
        </div>
        <div class="stat-icon">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
    </div>

    <div class="stat-card assigned">
        <div class="stat-info">
            <h3>Assigned Assets</h3>
            <div class="stat-value"><?php echo $assigned_assets; ?></div>
        </div>
        <div class="stat-icon">
            <i class="fa-solid fa-user-check"></i>
        </div>
    </div>

    <div class="stat-card available">
        <div class="stat-info">
            <h3>Available Stock</h3>
            <div class="stat-value"><?php echo $available_assets; ?></div>
        </div>
        <div class="stat-icon">
            <i class="fa-solid fa-circle-check"></i>
        </div>
    </div>

    <div class="stat-card damaged">
        <div class="stat-info">
            <h3>Damaged / Defect</h3>
            <div class="stat-value"><?php echo $damaged_assets; ?></div>
        </div>
        <div class="stat-icon">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
    </div>
</div>

<!-- 2. Interactive Charts & Log Activities Layout -->
<div class="dashboard-grid">
    <!-- Chart Card -->
    <div class="card" style="margin-bottom: 0;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3 style="font-size: 16px; font-weight: 700;">Asset Category Distribution</h3>
            <span style="font-size: 11px; background: var(--primary-glow); color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: bold;">Realtime tracking</span>
        </div>
        
        <!-- Custom JS SVG Chart target -->
        <div id="dashboardSVGChart" class="chart-container" 
             data-chart-labels="<?php echo htmlspecialchars($chart_labels_str); ?>" 
             data-chart-values="<?php echo htmlspecialchars($chart_values_str); ?>">
            <!-- The SVG chart will be dynamically appended here by js/app.js -->
        </div>
    </div>

    <!-- Recent Activities Cards -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 20px;">Recent Activity Logs</h3>
        
        <div class="activity-list">
            <?php if (empty($activities)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 40px 0;">
                    <i class="fa-solid fa-history" style="font-size: 28px; margin-bottom: 10px;"></i>
                    <p style="font-size: 13px;">No recent assignments recorded.</p>
                </div>
            <?php else: ?>
                <?php foreach ($activities as $act): ?>
                    <div class="activity-item">
                        <?php if ($act->status === 'Active'): ?>
                            <div class="activity-icon assign">
                                <i class="fa-solid fa-user-tag"></i>
                            </div>
                            <div class="activity-details">
                                <h4>Asset Assigned</h4>
                                <p><?php e($act->asset_name); ?> was allocated to <strong><?php e($act->first_name . ' ' . $act->last_name); ?></strong></p>
                            </div>
                            <div class="activity-time">
                                <?php echo date('M d', strtotime($act->assigned_date)); ?>
                            </div>
                        <?php else: ?>
                            <div class="activity-icon return">
                                <i class="fa-solid fa-rotate-left"></i>
                            </div>
                            <div class="activity-details">
                                <h4>Asset Returned</h4>
                                <p><?php e($act->asset_name); ?> was returned by <strong><?php e($act->first_name . ' ' . $act->last_name); ?></strong></p>
                            </div>
                            <div class="activity-time">
                                <?php echo date('M d', strtotime($act->return_date)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 3. System Management Shortcuts Quick Links Bar -->
<div class="card" style="margin-top: 24px;">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px;">Quick Administrative Shortcuts</h3>
    <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a href="assets.php?action=add" class="btn btn-primary" style="font-size: 13px;">
            <i class="fa-solid fa-plus"></i> Register New Asset
        </a>
        <a href="assignments.php" class="btn btn-secondary" style="font-size: 13px;">
            <i class="fa-solid fa-link"></i> Manage Assignments
        </a>
        <a href="employees.php?action=add" class="btn btn-secondary" style="font-size: 13px;">
            <i class="fa-solid fa-user-plus"></i> Add New Employee
        </a>
        <a href="reports.php" class="btn btn-secondary" style="font-size: 13px;">
            <i class="fa-solid fa-file-invoice"></i> Generate Reports
        </a>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
