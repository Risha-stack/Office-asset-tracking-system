<?php
// Asset Assignments Hub - admin/assignments.php
require_once __DIR__ . '/../includes/header.php';

// 1. Handle Assigning Asset Operation
if (isset($_POST['action_assign'])) {
    $asset_id = (int)$_POST['asset_id'];
    $employee_id = (int)$_POST['employee_id'];
    $assigned_date = sanitize($_POST['assigned_date']);
    $notes = sanitize($_POST['notes']);

    if (empty($asset_id) || empty($employee_id) || empty($assigned_date)) {
        $_SESSION['error'] = "Please select both an asset, an employee, and the assignment date.";
    } else {
        try {
            // Verify asset is indeed available
            $stmt = $pdo->prepare("SELECT status, name FROM assets WHERE id = ?");
            $stmt->execute([$asset_id]);
            $asset = $stmt->fetch();

            if (!$asset || $asset->status !== 'Available') {
                $_SESSION['error'] = "The selected asset is not available for assignment.";
            } else {
                // Perform allocation in a Database Transaction
                $pdo->beginTransaction();

                // 1. Insert assignment
                $stmt = $pdo->prepare("INSERT INTO assignments (asset_id, employee_id, assigned_date, status, notes) VALUES (?, ?, ?, 'Active', ?)");
                $stmt->execute([$asset_id, $employee_id, $assigned_date, $notes]);

                // 2. Update asset status
                $stmt = $pdo->prepare("UPDATE assets SET status = 'Assigned' WHERE id = ?");
                $stmt->execute([$asset_id]);

                $pdo->commit();
                $_SESSION['success'] = "Asset <strong>" . htmlspecialchars($asset->name) . "</strong> has been successfully assigned!";
                header("Location: assignments.php");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Allocation transaction failed: " . $e->getMessage();
        }
    }
}

// 2. Handle Asset Return Process (With custom condition state choice)
if (isset($_POST['action_return'])) {
    $assignment_id = (int)$_POST['assignment_id'];
    $return_date = sanitize($_POST['return_date']);
    $return_status = sanitize($_POST['return_status']); // Available, Damaged, Maintenance
    $notes = sanitize($_POST['notes']);

    if (empty($assignment_id) || empty($return_date) || empty($return_status)) {
        $_SESSION['error'] = "All details are required to complete the asset return.";
    } else {
        try {
            // Retrieve assignment & asset details
            $stmt = $pdo->prepare("SELECT asset_id FROM assignments WHERE id = ? AND status = 'Active'");
            $stmt->execute([$assignment_id]);
            $asset_id = $stmt->fetchColumn();

            if (!$asset_id) {
                $_SESSION['error'] = "Active assignment record not found.";
            } else {
                $pdo->beginTransaction();

                // 1. Close assignment record
                $stmt = $pdo->prepare("UPDATE assignments SET return_date = ?, status = 'Returned', notes = CONCAT(notes, ' | Return Note: ', ?) WHERE id = ?");
                $stmt->execute([$return_date, $notes, $assignment_id]);

                // 2. Re-evaluate asset status in inventory
                $stmt = $pdo->prepare("UPDATE assets SET status = ? WHERE id = ?");
                $stmt->execute([$return_status, $asset_id]);

                $pdo->commit();
                $_SESSION['success'] = "Asset returned successfully. Status updated to: <strong>" . htmlspecialchars($return_status) . "</strong>.";
                header("Location: assignments.php");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Return transaction failed: " . $e->getMessage();
        }
    }
}

// 3. Fetch list of available assets and active staff
$available_assets = [];
$active_employees = [];
try {
    $available_assets = $pdo->query("SELECT id, name, asset_tag, brand FROM assets WHERE status = 'Available' ORDER BY name ASC")->fetchAll();
    $active_employees = $pdo->query("SELECT id, first_name, last_name, employee_id FROM employees WHERE status = 'Active' ORDER BY first_name ASC")->fetchAll();
} catch (PDOException $e) {}

// 4. Fetch list of active assignments (Active allocations)
$active_assignments = [];
try {
    $active_assignments = $pdo->query("SELECT asg.*, ast.name as asset_name, ast.asset_tag, ast.brand, 
                                              emp.first_name, emp.last_name, emp.employee_id
                                       FROM assignments asg
                                       JOIN assets ast ON asg.asset_id = ast.id
                                       JOIN employees emp ON asg.employee_id = emp.id
                                       WHERE asg.status = 'Active'
                                       ORDER BY asg.id DESC")->fetchAll();
} catch (PDOException $e) {}

// 5. Handle return request fetch
$return_request = null;
if (isset($_GET['return_id'])) {
    $ret_id = (int)$_GET['return_id'];
    try {
        $stmt = $pdo->prepare("SELECT asg.id, ast.name as asset_name, ast.asset_tag, 
                                     emp.first_name, emp.last_name 
                              FROM assignments asg 
                              JOIN assets ast ON asg.asset_id = ast.id 
                              JOIN employees emp ON asg.employee_id = emp.id 
                              WHERE asg.id = ? AND asg.status = 'Active'");
        $stmt->execute([$ret_id]);
        $return_request = $stmt->fetch();
    } catch (PDOException $e) {}
}
?>

<div style="display: grid; grid-template-columns: 1fr 2fr; gap: 24px; align-items: start;">
    
    <!-- 1. Allocation Form Card (Left Column) -->
    <div class="card">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 20px; color: var(--primary);">
            <i class="fa-solid fa-link"></i> Allocate Asset
        </h3>

        <form action="assignments.php" method="POST">
            <div class="form-group">
                <label for="assign_asset">Select Available Asset *</label>
                <select name="asset_id" id="assign_asset" class="form-control" required>
                    <option value="">-- Choose Asset --</option>
                    <?php foreach ($available_assets as $ast): ?>
                        <option value="<?php echo $ast->id; ?>">
                            <?php e($ast->name . ' (Tag: ' . $ast->asset_tag . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($available_assets)): ?>
                    <small style="color: var(--warning); margin-top: 4px; display: block;">
                        <i class="fa-solid fa-triangle-exclamation"></i> No available assets in stock. 
                        <a href="assets.php?action=add" style="text-decoration: underline; font-weight: 600;">Add one</a>
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="assign_emp">Select Active Employee *</label>
                <select name="employee_id" id="assign_emp" class="form-control" required>
                    <option value="">-- Choose Employee --</option>
                    <?php foreach ($active_employees as $emp): ?>
                        <option value="<?php echo $emp->id; ?>">
                            <?php e($emp->first_name . ' ' . $emp->last_name . ' (' . $emp->employee_id . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($active_employees)): ?>
                    <small style="color: var(--warning); margin-top: 4px; display: block;">
                        <i class="fa-solid fa-triangle-exclamation"></i> No active employees available.
                    </small>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="assigned_date">Allocation Date *</label>
                <input type="date" name="assigned_date" id="assigned_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>

            <div class="form-group">
                <label for="notes">Allocation Remarks / Notes</label>
                <textarea name="notes" id="notes" class="form-control" placeholder="e.g. Assigned to engineering lead. Device in perfect condition."></textarea>
            </div>

            <button type="submit" name="action_assign" class="btn btn-primary" style="width: 100%; margin-top: 10px;" <?php echo (empty($available_assets) || empty($active_employees)) ? 'disabled' : ''; ?>>
                Complete Allocation <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
    </div>

    <!-- 2. Active Allocations Table Card (Right Column) -->
    <div class="card" style="margin-bottom: 0;">
        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 20px;">
            <i class="fa-solid fa-clock-rotate-left"></i> Current Active Allocations
        </h3>

        <div class="table-responsive">
            <table class="custom-table" style="font-size: 13px;">
                <thead>
                    <tr>
                        <th>Asset Details</th>
                        <th>Assigned Staff</th>
                        <th>Allocation Date</th>
                        <th>Remarks</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($active_assignments)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fa-solid fa-hourglass" style="font-size: 28px; margin-bottom: 10px; display: block;"></i>
                                No active allocations recorded. All assets are currently in inventory.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($active_assignments as $asg): ?>
                            <tr>
                                <td>
                                    <div><strong><?php e($asg->asset_name); ?></strong></div>
                                    <div style="font-size: 11px; font-family: monospace; color: var(--text-muted);">Tag: <?php e($asg->asset_tag); ?></div>
                                </td>
                                <td>
                                    <div><strong><?php e($asg->first_name . ' ' . $asg->last_name); ?></strong></div>
                                    <div style="font-size: 11px; font-family: monospace; color: var(--text-muted);">ID: <?php e($asg->employee_id); ?></div>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($asg->assigned_date)); ?></td>
                                <td>
                                    <span style="font-size: 12px; max-width: 150px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars($asg->notes); ?>">
                                        <?php echo !empty($asg->notes) ? e($asg->notes) : '<em style="color: var(--text-muted);">None</em>'; ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <a href="assignments.php?return_id=<?php echo $asg->id; ?>" class="btn btn-secondary btn-icon" style="color: var(--success);" title="Log Asset Return">
                                        <i class="fa-solid fa-rotate-left"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODALS SYSTEM ================= -->

<!-- 1. RETURN PROCESSING MODAL -->
<?php if ($return_request): ?>
<div class="modal active" id="returnModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Process Asset Return</h3>
            <a href="assignments.php" class="modal-close" style="text-decoration: none; color: inherit;">&times;</a>
        </div>
        <form action="assignments.php" method="POST">
            <input type="hidden" name="assignment_id" value="<?php echo $return_request->id; ?>">
            <div class="modal-body">
                <p style="font-size: 14px; margin-bottom: 20px;">
                    Asset Name: <strong><?php e($return_request->asset_name); ?></strong> (Tag: <?php e($return_request->asset_tag); ?>)<br>
                    Assigned Employee: <strong><?php e($return_request->first_name . ' ' . $return_request->last_name); ?></strong>
                </p>
                <hr style="border: 0; height: 1px; background: var(--border-glass); margin-bottom: 20px;">

                <div class="form-group">
                    <label for="return_date">Return Date *</label>
                    <input type="date" name="return_date" id="return_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="form-group">
                    <label for="return_status">Re-evaluate Asset Status *</label>
                    <select name="return_status" id="return_status" class="form-control" required>
                        <option value="Available">Available (Re-allocatable to stock)</option>
                        <option value="Maintenance">Maintenance (Needs repair/servicing)</option>
                        <option value="Damaged">Damaged (Defect/broken state)</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="return_notes">Return Condition Remarks / Notes</label>
                    <textarea name="notes" id="return_notes" class="form-control" placeholder="e.g. Returned safely. Screen has slight scratch."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <a href="assignments.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="action_return" class="btn btn-primary">Process Return</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
