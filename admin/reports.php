<?php
// Reports Analytics & Exports - admin/reports.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// Enforce login
require_login();

// 1. Check if exporting to Excel/CSV (Triggered before header inclusions)
$export = sanitize($_GET['export'] ?? '');
$type = sanitize($_GET['type'] ?? '');

if (!empty($export) && $export === 'excel' && !empty($type)) {
    // Perform raw CSV output stream
    $filename = str_replace(' ', '_', strtolower($type)) . "_report_" . date('Ymd_His') . ".csv";
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for proper Excel encoding of special characters
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

    try {
        if ($type === 'asset') {
            // Write CSV headers
            fputcsv($output, ['Asset Tag ID', 'Asset Name', 'Category', 'Brand/Manufacturer', 'Serial Number', 'Purchase Cost ($)', 'Purchase Date', 'Warranty Date', 'Status', 'Assigned Staff']);
            
            $stmt = $pdo->query("SELECT a.*, c.name as category_name, emp.first_name, emp.last_name, emp.employee_id
                                 FROM assets a 
                                 JOIN categories c ON a.category_id = c.id
                                 LEFT JOIN assignments asg ON a.id = asg.asset_id AND asg.status = 'Active'
                                 LEFT JOIN employees emp ON asg.employee_id = emp.id
                                 ORDER BY a.id DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $assigned_staff = $row['first_name'] ? ($row['first_name'] . ' ' . $row['last_name'] . ' (' . $row['employee_id'] . ')') : 'None';
                fputcsv($output, [
                    $row['asset_tag'],
                    $row['name'],
                    $row['category_name'],
                    $row['brand'],
                    $row['serial_number'],
                    $row['price'],
                    $row['purchase_date'],
                    $row['warranty_date'],
                    $row['status'],
                    $assigned_staff
                ]);
            }
        } elseif ($type === 'employee') {
            fputcsv($output, ['Employee ID', 'Full Name', 'Corporate Email', 'Department', 'Employment Status', 'Current Asset Allocations Count']);
            
            $stmt = $pdo->query("SELECT e.*, 
                                        (SELECT COUNT(*) FROM assignments asg WHERE asg.employee_id = e.id AND asg.status = 'Active') as allocation_count 
                                 FROM employees e 
                                 ORDER BY e.first_name ASC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['employee_id'],
                    $row['first_name'] . ' ' . $row['last_name'],
                    $row['email'],
                    $row['department'],
                    $row['status'],
                    $row['allocation_count']
                ]);
            }
        } elseif ($type === 'damaged') {
            fputcsv($output, ['Asset Tag ID', 'Asset Name', 'Category', 'Brand', 'Serial Number', 'Purchase Cost ($)', 'Purchase Date', 'Warranty Expiry']);
            
            $stmt = $pdo->query("SELECT a.*, c.name as category_name 
                                 FROM assets a 
                                 JOIN categories c ON a.category_id = c.id 
                                 WHERE a.status = 'Damaged' 
                                 ORDER BY a.id DESC");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                fputcsv($output, [
                    $row['asset_tag'],
                    $row['name'],
                    $row['category_name'],
                    $row['brand'],
                    $row['serial_number'],
                    $row['price'],
                    $row['purchase_date'],
                    $row['warranty_date']
                ]);
            }
        }
    } catch (PDOException $e) {}
    
    fclose($output);
    exit;
}

// 2. Check if exporting to Print-Friendly HTML PDF layout (Full overlay, no headers/sidebar)
if (!empty($export) && $export === 'print' && !empty($type)) {
    $report_title = "";
    $headers = [];
    $data_rows = [];
    
    try {
        if ($type === 'asset') {
            $report_title = "Corporate Asset Inventory Ledger";
            $headers = ['Tag ID', 'Asset Name', 'Category', 'Brand', 'Cost', 'Purchase Date', 'Status', 'Assigned To'];
            
            $stmt = $pdo->query("SELECT a.*, c.name as category_name, emp.first_name, emp.last_name
                                 FROM assets a 
                                 JOIN categories c ON a.category_id = c.id
                                 LEFT JOIN assignments asg ON a.id = asg.asset_id AND asg.status = 'Active'
                                 LEFT JOIN employees emp ON asg.employee_id = emp.id
                                 ORDER BY a.id DESC");
            $res = $stmt->fetchAll();
            foreach ($res as $row) {
                $assigned = $row->first_name ? ($row->first_name . ' ' . $row->last_name) : 'None';
                $data_rows[] = [$row->asset_tag, $row->name, $row->category_name, $row->brand, '$' . number_format($row->price, 2), $row->purchase_date, $row->status, $assigned];
            }
        } elseif ($type === 'employee') {
            $report_title = "Corporate Staff Allocation Directory";
            $headers = ['Employee ID', 'Full Name', 'Corporate Email', 'Department', 'Status', 'Allocations'];
            
            $stmt = $pdo->query("SELECT e.*, 
                                        (SELECT COUNT(*) FROM assignments asg WHERE asg.employee_id = e.id AND asg.status = 'Active') as allocation_count 
                                 FROM employees e 
                                 ORDER BY e.first_name ASC");
            $res = $stmt->fetchAll();
            foreach ($res as $row) {
                $data_rows[] = [$row->employee_id, $row->first_name . ' ' . $row->last_name, $row->email, $row->department, $row->status, $row->allocation_count . ' asset(s)'];
            }
        } elseif ($type === 'damaged') {
            $report_title = "Damaged & Defect Assets Audit Report";
            $headers = ['Tag ID', 'Asset Name', 'Category', 'Brand', 'Serial Number', 'Purchase Cost', 'Purchase Date'];
            
            $stmt = $pdo->query("SELECT a.*, c.name as category_name 
                                 FROM assets a 
                                 JOIN categories c ON a.category_id = c.id 
                                 WHERE a.status = 'Damaged' 
                                 ORDER BY a.id DESC");
            $res = $stmt->fetchAll();
            foreach ($res as $row) {
                $data_rows[] = [$row->asset_tag, $row->name, $row->category_name, $row->brand, $row->serial_number, '$' . number_format($row->price, 2), $row->purchase_date];
            }
        }
    } catch (PDOException $e) {}

    // Output raw printer friendly screen
    echo "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <title>Print Report - " . htmlspecialchars($report_title) . "</title>
        <style>
            body { font-family: Arial, sans-serif; color: #333; margin: 40px; }
            .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
            .header h1 { margin: 0; font-size: 24px; font-weight: bold; text-transform: uppercase; }
            .header p { margin: 5px 0 0 0; color: #666; font-size: 14px; }
            .meta { display: flex; justify-content: space-between; font-size: 13px; color: #555; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th { border-bottom: 2px solid #333; padding: 10px; font-weight: bold; text-align: left; font-size: 13px; text-transform: uppercase; background: #f9f9f9; }
            td { padding: 10px; border-bottom: 1px solid #ddd; font-size: 12px; }
            .footer { margin-top: 40px; text-align: center; font-size: 11px; color: #888; border-top: 1px solid #ddd; padding-top: 20px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>" . htmlspecialchars($report_title) . "</h1>
            <p>Office Asset Management Tracker System Report Ledger</p>
        </div>
        <div class='meta'>
            <span>Generated By: <strong>" . htmlspecialchars($_SESSION['username']) . "</strong></span>
            <span>Date: <strong>" . date('Y-m-d H:i:s') . "</strong></span>
        </div>
        <table>
            <thead>
                <tr>";
                foreach ($headers as $head) {
                    echo "<th>" . htmlspecialchars($head) . "</th>";
                }
    echo "      </tr>
            </thead>
            <tbody>";
            if (empty($data_rows)) {
                echo "<tr><td colspan='" . count($headers) . "' style='text-align: center; padding: 30px;'>No records found matching audit criteria.</td></tr>";
            } else {
                foreach ($data_rows as $row) {
                    echo "<tr>";
                    foreach ($row as $val) {
                        echo "<td>" . htmlspecialchars($val) . "</td>";
                    }
                    echo "</tr>";
                }
            }
    echo "  </tbody>
        </table>
        <div class='footer'>
            <p>&copy; " . date('Y') . " AssetFlow Systems. All report documents are corporate and classified.</p>
        </div>
        <script>
            window.onload = function() {
                window.print();
                // Automatically redirect back or allow the user to return
            }
        </script>
    </body>
    </html>";
    exit;
}

// 3. Regular view - Load standard headers and sidebar
require_once __DIR__ . '/../includes/header.php';

// Check if displaying inline preview report
$preview_title = "";
$preview_headers = [];
$preview_rows = [];

if (!empty($type)) {
    try {
        if ($type === 'asset') {
            $preview_title = "Asset Inventory Report Preview";
            $preview_headers = ['Tag ID', 'Asset Name', 'Category', 'Brand', 'Cost', 'Status', 'Assigned To'];
            
            $stmt = $pdo->query("SELECT a.*, c.name as category_name, emp.first_name, emp.last_name
                                 FROM assets a 
                                 JOIN categories c ON a.category_id = c.id
                                 LEFT JOIN assignments asg ON a.id = asg.asset_id AND asg.status = 'Active'
                                 LEFT JOIN employees emp ON asg.employee_id = emp.id
                                 ORDER BY a.id DESC LIMIT 10");
            $res = $stmt->fetchAll();
            foreach ($res as $row) {
                $assigned = $row->first_name ? ($row->first_name . ' ' . $row->last_name) : 'None';
                $preview_rows[] = [$row->asset_tag, $row->name, $row->category_name, $row->brand, '$' . number_format($row->price, 2), $row->status, $assigned];
            }
        } elseif ($type === 'employee') {
            $preview_title = "Staff Allocation Directory Preview";
            $preview_headers = ['Employee ID', 'Full Name', 'Corporate Email', 'Department', 'Status', 'Allocations'];
            
            $stmt = $pdo->query("SELECT e.*, 
                                        (SELECT COUNT(*) FROM assignments asg WHERE asg.employee_id = e.id AND asg.status = 'Active') as allocation_count 
                                 FROM employees e 
                                 ORDER BY e.first_name ASC LIMIT 10");
            $res = $stmt->fetchAll();
            foreach ($res as $row) {
                $preview_rows[] = [$row->employee_id, $row->first_name . ' ' . $row->last_name, $row->email, $row->department, $row->status, $row->allocation_count . ' asset(s)'];
            }
        } elseif ($type === 'damaged') {
            $preview_title = "Damaged Asset Audit Preview";
            $preview_headers = ['Tag ID', 'Asset Name', 'Category', 'Brand', 'Serial Number', 'Cost'];
            
            $stmt = $pdo->query("SELECT a.*, c.name as category_name 
                                 FROM assets a 
                                 JOIN categories c ON a.category_id = c.id 
                                 WHERE a.status = 'Damaged' 
                                 ORDER BY a.id DESC LIMIT 10");
            $res = $stmt->fetchAll();
            foreach ($res as $row) {
                $preview_rows[] = [$row->asset_tag, $row->name, $row->category_name, $row->brand, $row->serial_number, '$' . number_format($row->price, 2)];
            }
        }
    } catch (PDOException $e) {}
}
?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px; align-items: start;">
    
    <!-- 1. Report Selector Side Grid (Left Column) -->
    <div style="display: flex; flex-direction: column; gap: 24px;">
        
        <!-- selector card 1 -->
        <div class="card" style="margin-bottom: 0;">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 12px;">Asset Ledger Report</h3>
            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.4; margin-bottom: 20px;">
                Complete inventory list including costs, serial numbers, categories, statuses, and current assigned employees.
            </p>
            <div style="display: flex; gap: 8px;">
                <a href="reports.php?type=asset" class="btn btn-secondary" style="font-size: 12px; flex: 1;">Preview</a>
                <a href="reports.php?type=asset&export=excel" class="btn btn-secondary btn-icon" title="Export Excel / CSV"><i class="fa-solid fa-file-csv"></i></a>
                <a href="reports.php?type=asset&export=print" target="_blank" class="btn btn-primary btn-icon" title="Print Report Ledger / Save PDF"><i class="fa-solid fa-file-pdf"></i></a>
            </div>
        </div>

        <!-- selector card 2 -->
        <div class="card" style="margin-bottom: 0;">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 12px;">Staff Directory Report</h3>
            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.4; margin-bottom: 20px;">
                Comprehensive list of active and inactive staff, department designations, and active asset allocations counts.
            </p>
            <div style="display: flex; gap: 8px;">
                <a href="reports.php?type=employee" class="btn btn-secondary" style="font-size: 12px; flex: 1;">Preview</a>
                <a href="reports.php?type=employee&export=excel" class="btn btn-secondary btn-icon" title="Export CSV"><i class="fa-solid fa-file-csv"></i></a>
                <a href="reports.php?type=employee&export=print" target="_blank" class="btn btn-primary btn-icon" title="Print Ledger / Save PDF"><i class="fa-solid fa-file-pdf"></i></a>
            </div>
        </div>

        <!-- selector card 3 -->
        <div class="card" style="margin-bottom: 0;">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 12px;">Damaged Assets Audit</h3>
            <p style="font-size: 13px; color: var(--text-secondary); line-height: 1.4; margin-bottom: 20px;">
                Identifies all damaged or defect items across inventory, details of serial numbers, costs and dates for quick replacement planning.
            </p>
            <div style="display: flex; gap: 8px;">
                <a href="reports.php?type=damaged" class="btn btn-secondary" style="font-size: 12px; flex: 1;">Preview</a>
                <a href="reports.php?type=damaged&export=excel" class="btn btn-secondary btn-icon" title="Export CSV"><i class="fa-solid fa-file-csv"></i></a>
                <a href="reports.php?type=damaged&export=print" target="_blank" class="btn btn-primary btn-icon" title="Print Audit / Save PDF"><i class="fa-solid fa-file-pdf"></i></a>
            </div>
        </div>
    </div>

    <!-- 2. Preview Panel Card (Right Column) -->
    <div class="card" style="margin-bottom: 0; min-height: 480px;">
        <?php if (empty($type)): ?>
            <div style="text-align: center; color: var(--text-muted); padding: 120px 20px;">
                <i class="fa-solid fa-chart-line" style="font-size: 48px; margin-bottom: 16px; display: block; color: var(--primary);"></i>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-primary); margin-bottom: 6px;">Reports Ledger Viewer</h3>
                <p style="font-size: 13px; max-width: 320px; margin: 0 auto; line-height: 1.5;">
                    Select "Preview" on any category on the left to see the live data ledger rendering here before exporting.
                </p>
            </div>
        <?php else: ?>
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 16px; margin-bottom: 20px;">
                <div>
                    <h3 style="font-size: 16px; font-weight: 700;"><?php echo $preview_title; ?></h3>
                    <small style="color: var(--text-muted);">Live Preview (Displaying top 10 rows)</small>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="reports.php?type=<?php echo $type; ?>&export=excel" class="btn btn-secondary" style="font-size: 12px; padding: 8px 14px;">
                        <i class="fa-solid fa-file-csv"></i> Excel Export
                    </a>
                    <a href="reports.php?type=<?php echo $type; ?>&export=print" target="_blank" class="btn btn-primary" style="font-size: 12px; padding: 8px 14px;">
                        <i class="fa-solid fa-file-pdf"></i> Save / Print PDF
                    </a>
                </div>
            </div>

            <div class="table-responsive">
                <table class="custom-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <?php foreach ($preview_headers as $head): ?>
                                <th><?php echo $head; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($preview_rows)): ?>
                            <tr>
                                <td colspan="<?php echo count($preview_headers); ?>" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                    <i class="fa-solid fa-face-meh" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                                    No records found matching active preview search.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($preview_rows as $row): ?>
                                <tr>
                                    <?php foreach ($row as $cell): ?>
                                        <td><?php e($cell); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
