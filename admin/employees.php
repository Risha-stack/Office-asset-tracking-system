<?php
// Employee Management Portal - admin/employees.php
require_once __DIR__ . '/../includes/header.php';

// 1. Handle Create Employee
if (isset($_POST['action_create'])) {
    $employee_id = sanitize($_POST['employee_id']);
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $email = sanitize($_POST['email']);
    $department = sanitize($_POST['department']);
    $status = sanitize($_POST['status'] ?? 'Active');

    if (empty($employee_id) || empty($first_name) || empty($last_name) || empty($email) || empty($department)) {
        $_SESSION['error'] = "Please provide all required employee fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
    } else {
        try {
            // Check unique constraints for employee_id and email
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE employee_id = ? OR email = ?");
            $stmt->execute([$employee_id, $email]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = "Employee ID or Email is already registered.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO employees (employee_id, first_name, last_name, email, department, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employee_id, $first_name, $last_name, $email, $department, $status]);
                
                $_SESSION['success'] = "Employee <strong>" . htmlspecialchars($first_name . ' ' . $last_name) . "</strong> added successfully!";
                header("Location: employees.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Insert failed: " . $e->getMessage();
        }
    }
}

// 2. Handle Update Employee
if (isset($_POST['action_update'])) {
    $id = (int)$_POST['emp_id'];
    $employee_id = sanitize($_POST['employee_id']);
    $first_name = sanitize($_POST['first_name']);
    $last_name = sanitize($_POST['last_name']);
    $email = sanitize($_POST['email']);
    $department = sanitize($_POST['department']);
    $status = sanitize($_POST['status']);

    if (empty($id) || empty($employee_id) || empty($first_name) || empty($last_name) || empty($email) || empty($department)) {
        $_SESSION['error'] = "Please fill in all details for editing.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['error'] = "Please enter a valid email address.";
    } else {
        try {
            // Check unique constraints avoiding the active edited employee ID
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE (employee_id = ? OR email = ?) AND id != ?");
            $stmt->execute([$employee_id, $email, $id]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = "Employee ID or Email conflicts with an existing staff record.";
            } else {
                $stmt = $pdo->prepare("UPDATE employees SET employee_id = ?, first_name = ?, last_name = ?, email = ?, department = ?, status = ? WHERE id = ?");
                $stmt->execute([$employee_id, $first_name, $last_name, $email, $department, $status, $id]);
                
                $_SESSION['success'] = "Employee details modified successfully.";
                header("Location: employees.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Update failure: " . $e->getMessage();
        }
    }
}

// 3. Handle Delete Employee (Admin-Only)
if (isset($_GET['delete_id'])) {
    require_admin(); // Secure role check
    $id = (int)$_GET['delete_id'];
    
    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $emp_name = $stmt->fetch();

        if ($emp_name) {
            // Deletion cascade handled by foreign key rules in database
            $stmt = $pdo->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Employee <strong>" . htmlspecialchars($emp_name->first_name . ' ' . $emp_name->last_name) . "</strong> has been permanently removed.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Constraint failure: Cannot delete an employee who has active asset allocations. Re-claim assets first.";
    }
    
    header("Location: employees.php");
    exit;
}

// 4. Listing, Search, Filter queries
$search = sanitize($_GET['search'] ?? '');
$filter_dept = sanitize($_GET['department'] ?? '');
$filter_status = sanitize($_GET['status'] ?? '');

$query_str = "SELECT e.*, 
                     (SELECT COUNT(*) FROM assignments asg WHERE asg.employee_id = e.id AND asg.status = 'Active') as active_assignments_count
              FROM employees e 
              WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query_str .= " AND (e.first_name LIKE ? OR e.last_name LIKE ? OR e.employee_id LIKE ? OR e.email LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($filter_dept)) {
    $query_str .= " AND e.department = ?";
    $params[] = $filter_dept;
}

if (!empty($filter_status)) {
    $query_str .= " AND e.status = ?";
    $params[] = $filter_status;
}

$query_str .= " ORDER BY e.id DESC";

$employees_list = [];
try {
    $stmt = $pdo->prepare($query_str);
    $stmt->execute($params);
    $employees_list = $stmt->fetchAll();
} catch (PDOException $e) {
    $_SESSION['error'] = "Failed fetching employee records: " . $e->getMessage();
}

// Extract distinct departments for dropdown selection filter
$departments = [];
try {
    $departments = $pdo->query("SELECT DISTINCT department FROM employees ORDER BY department ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// 5. Handle Editing fetch request
$edit_emp = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_emp = $stmt->fetch();
    } catch (PDOException $e) {}
}

// 6. Handle view history tracking modal request
$history_emp = null;
$assignment_history = [];
if (isset($_GET['history_id'])) {
    $hist_id = (int)$_GET['history_id'];
    try {
        $stmt = $pdo->prepare("SELECT first_name, last_name, employee_id FROM employees WHERE id = ?");
        $stmt->execute([$hist_id]);
        $history_emp = $stmt->fetch();

        if ($history_emp) {
            $stmt = $pdo->prepare("SELECT asg.*, ast.name as asset_name, ast.asset_tag, ast.brand 
                                 FROM assignments asg 
                                 JOIN assets ast ON asg.asset_id = ast.id 
                                 WHERE asg.employee_id = ? 
                                 ORDER BY asg.id DESC");
            $stmt->execute([$hist_id]);
            $assignment_history = $stmt->fetchAll();
        }
    } catch (PDOException $e) {}
}
?>

<!-- Action Bar (Search & Filter Controls) -->
<div class="card">
    <div class="actions-bar">
        <form action="employees.php" method="GET" class="search-box">
            <input type="text" name="search" class="form-control" placeholder="Search by name, ID, email..." value="<?php echo htmlspecialchars($search); ?>">
            <i class="fa-solid fa-magnifying-glass"></i>
            <?php if (!empty($filter_dept)): ?>
                <input type="hidden" name="department" value="<?php echo $filter_dept; ?>">
            <?php endif; ?>
            <?php if (!empty($filter_status)): ?>
                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
            <?php endif; ?>
        </form>

        <div class="filters-box">
            <form action="employees.php" method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <?php endif; ?>
                
                <select name="department" class="form-control" onchange="this.form.submit()" style="width: auto;">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $filter_dept === $dept ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($dept); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-control" onchange="this.form.submit()" style="width: auto;">
                    <option value="">All Statuses</option>
                    <option value="Active" <?php echo $filter_status === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $filter_status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </form>

            <button class="btn btn-primary" data-toggle="modal" data-target="#addEmployeeModal">
                <i class="fa-solid fa-user-plus"></i> Add Employee
            </button>
        </div>
    </div>
</div>

<!-- Employees Table Card -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Emp ID</th>
                    <th>Employee Name</th>
                    <th>Email Address</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Active Allocation</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($employees_list)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fa-solid fa-users-slash" style="font-size: 32px; margin-bottom: 12px; display: block;"></i>
                            No employees listed matching criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($employees_list as $emp): ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: bold; color: var(--text-primary);">
                                <?php e($emp->employee_id); ?>
                            </td>
                            <td>
                                <strong><?php e($emp->first_name . ' ' . $emp->last_name); ?></strong>
                            </td>
                            <td><?php e($emp->email); ?></td>
                            <td><?php e($emp->department); ?></td>
                            <td>
                                <?php if ($emp->status === 'Active'): ?>
                                    <span class="badge-pill status-available"><i class="fa-solid fa-circle-check"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge-pill status-damaged"><i class="fa-solid fa-circle-xmark"></i> Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 700; color: <?php echo $emp->active_assignments_count > 0 ? 'var(--primary)' : 'var(--text-muted)'; ?>">
                                    <?php echo $emp->active_assignments_count; ?> asset(s)
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <a href="employees.php?history_id=<?php echo $emp->id; ?>" class="btn btn-secondary btn-icon" title="View Asset Assignment History" style="color: var(--primary);">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                    </a>
                                    <a href="employees.php?edit_id=<?php echo $emp->id; ?>" class="btn btn-secondary btn-icon" title="Edit Employee">
                                        <i class="fa-solid fa-user-pen"></i>
                                    </a>
                                    <?php if ($_SESSION['role'] === 'Admin'): ?>
                                        <a href="employees.php?delete_id=<?php echo $emp->id; ?>" class="btn btn-secondary btn-icon" style="color: var(--danger);" title="Remove Employee" onclick="return confirm('Are you sure you want to permanently delete employee: <?php echo htmlspecialchars($emp->first_name . ' ' . $emp->last_name); ?>? This removes their association cascading.');">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODALS SYSTEM ================= -->

<!-- 1. ADD EMPLOYEE MODAL -->
<div class="modal <?php echo (isset($_GET['action']) && $_GET['action'] === 'add') ? 'active' : ''; ?>" id="addEmployeeModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Employee Record</h3>
            <button class="modal-close" data-dismiss="modal">&times;</button>
        </div>
        <form action="employees.php" method="POST">
            <div class="modal-body">
                <div class="form-group">
                    <label for="add_emp_id">Employee ID *</label>
                    <input type="text" name="employee_id" id="add_emp_id" class="form-control" placeholder="e.g. EMP088" required>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="add_first">First Name *</label>
                        <input type="text" name="first_name" id="add_first" class="form-control" placeholder="e.g. Jane" required>
                    </div>
                    <div class="form-group">
                        <label for="add_last">Last Name *</label>
                        <input type="text" name="last_name" id="add_last" class="form-control" placeholder="e.g. Miller" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="add_email">Corporate Email *</label>
                    <input type="email" name="email" id="add_email" class="form-control" placeholder="jane.miller@corporate.com" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 0;">
                    <div class="form-group">
                        <label for="add_dept">Department *</label>
                        <input type="text" name="department" id="add_dept" class="form-control" placeholder="e.g. Engineering" required>
                    </div>
                    <div class="form-group">
                        <label for="add_status">Status</label>
                        <select name="status" id="add_status" class="form-control">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" name="action_create" class="btn btn-primary">Save Employee</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. EDIT EMPLOYEE MODAL -->
<?php if ($edit_emp): ?>
<div class="modal active" id="editEmployeeModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Modify Employee Details</h3>
            <a href="employees.php" class="modal-close" style="text-decoration: none; color: inherit;">&times;</a>
        </div>
        <form action="employees.php" method="POST">
            <input type="hidden" name="emp_id" value="<?php echo $edit_emp->id; ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label for="edit_emp_id">Employee ID *</label>
                    <input type="text" name="employee_id" id="edit_emp_id" class="form-control" value="<?php e($edit_emp->employee_id); ?>" required>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="edit_first">First Name *</label>
                        <input type="text" name="first_name" id="edit_first" class="form-control" value="<?php e($edit_emp->first_name); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_last">Last Name *</label>
                        <input type="text" name="last_name" id="edit_last" class="form-control" value="<?php e($edit_emp->last_name); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit_email">Corporate Email *</label>
                    <input type="email" name="email" id="edit_email" class="form-control" value="<?php e($edit_emp->email); ?>" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 0;">
                    <div class="form-group">
                        <label for="edit_dept">Department *</label>
                        <input type="text" name="department" id="edit_dept" class="form-control" value="<?php e($edit_emp->department); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select name="status" id="edit_status" class="form-control">
                            <option value="Active" <?php echo $edit_emp->status === 'Active' ? 'selected' : ''; ?>>Active</option>
                            <option value="Inactive" <?php echo $edit_emp->status === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="employees.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="action_update" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 3. ASSIGNMENT HISTORY TRACKING MODAL -->
<?php if ($history_emp): ?>
<div class="modal active" id="historyModal">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h3>Allocation History: <?php e($history_emp->first_name . ' ' . $history_emp->last_name); ?></h3>
            <a href="employees.php" class="modal-close" style="text-decoration: none; color: inherit;">&times;</a>
        </div>
        <div class="modal-body" style="padding-top: 15px;">
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;">
                Employee Reference ID: <strong style="color: var(--text-primary); font-family: monospace;"><?php e($history_emp->employee_id); ?></strong>
            </p>
            
            <div class="table-responsive">
                <table class="custom-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th>Asset Tag</th>
                            <th>Description</th>
                            <th>Allocated On</th>
                            <th>Returned On</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assignment_history)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">
                                    <i class="fa-solid fa-history" style="font-size: 24px; margin-bottom: 8px; display: block;"></i>
                                    No asset assignment records found for this employee.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($assignment_history as $hist): ?>
                                <tr>
                                    <td style="font-family: monospace; font-weight: bold;"><?php e($hist->asset_tag); ?></td>
                                    <td>
                                        <div><strong><?php e($hist->asset_name); ?></strong></div>
                                        <div style="font-size: 11px; color: var(--text-muted);"><?php e($hist->brand); ?></div>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($hist->assigned_date)); ?></td>
                                    <td>
                                        <?php echo $hist->return_date ? date('M d, Y', strtotime($hist->return_date)) : '<span style="color: var(--success); font-weight: 500;">Currently Held</span>'; ?>
                                    </td>
                                    <td>
                                        <?php if ($hist->status === 'Active'): ?>
                                            <span class="badge-pill status-assigned" style="padding: 2px 8px; font-size: 10px;">Active</span>
                                        <?php else: ?>
                                            <span class="badge-pill status-available" style="padding: 2px 8px; font-size: 10px; background: rgba(0,0,0,0.05); color: var(--text-muted);">Returned</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <a href="employees.php" class="btn btn-primary">Close Viewer</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
