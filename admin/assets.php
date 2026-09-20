<?php
// Asset Management Portal - admin/assets.php
require_once __DIR__ . '/../includes/header.php';

// 1. Handle Create Asset Operation
if (isset($_POST['action_create'])) {
    // Staff and Admin are both permitted to create
    $asset_tag = sanitize($_POST['asset_tag']);
    $name = sanitize($_POST['name']);
    $category_id = (int)$_POST['category_id'];
    $brand = sanitize($_POST['brand']);
    $serial_number = sanitize($_POST['serial_number']);
    $purchase_date = sanitize($_POST['purchase_date']);
    $warranty_date = sanitize($_POST['warranty_date']);
    $status = sanitize($_POST['status']);
    $price = (float)$_POST['price'];

    // Inputs Validation
    if (empty($asset_tag) || empty($name) || empty($category_id) || empty($brand) || empty($serial_number) || empty($purchase_date) || empty($warranty_date) || empty($status) || $price <= 0) {
        $_SESSION['error'] = "All asset properties are required and price must be greater than zero.";
    } else {
        try {
            // Check unique constraints for asset tag & serial number
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE asset_tag = ? OR serial_number = ?");
            $stmt->execute([$asset_tag, $serial_number]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = "Asset tag or serial number is already registered under another asset.";
            } else {
                $stmt = $pdo->prepare("INSERT INTO assets (asset_tag, name, category_id, brand, serial_number, purchase_date, warranty_date, status, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$asset_tag, $name, $category_id, $brand, $serial_number, $purchase_date, $warranty_date, $status, $price]);
                
                $_SESSION['success'] = "Asset <strong>" . htmlspecialchars($name) . "</strong> registered successfully!";
                header("Location: assets.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Insert error: " . $e->getMessage();
        }
    }
}

// 2. Handle Update Asset Operation
if (isset($_POST['action_update'])) {
    $id = (int)$_POST['asset_id'];
    $asset_tag = sanitize($_POST['asset_tag']);
    $name = sanitize($_POST['name']);
    $category_id = (int)$_POST['category_id'];
    $brand = sanitize($_POST['brand']);
    $serial_number = sanitize($_POST['serial_number']);
    $purchase_date = sanitize($_POST['purchase_date']);
    $warranty_date = sanitize($_POST['warranty_date']);
    $status = sanitize($_POST['status']);
    $price = (float)$_POST['price'];

    if (empty($id) || empty($asset_tag) || empty($name) || empty($category_id) || empty($brand) || empty($serial_number) || empty($purchase_date) || empty($warranty_date) || empty($status) || $price <= 0) {
        $_SESSION['error'] = "All asset properties are required for updating.";
    } else {
        try {
            // Check unique constraints avoiding the active edited asset ID
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE (asset_tag = ? OR serial_number = ?) AND id != ?");
            $stmt->execute([$asset_tag, $serial_number, $id]);
            if ($stmt->fetchColumn() > 0) {
                $_SESSION['error'] = "Asset tag or serial number conflicts with another existing record.";
            } else {
                $stmt = $pdo->prepare("UPDATE assets SET asset_tag = ?, name = ?, category_id = ?, brand = ?, serial_number = ?, purchase_date = ?, warranty_date = ?, status = ?, price = ? WHERE id = ?");
                $stmt->execute([$asset_tag, $name, $category_id, $brand, $serial_number, $purchase_date, $warranty_date, $status, $price, $id]);
                
                $_SESSION['success'] = "Asset specifications successfully modified.";
                header("Location: assets.php");
                exit;
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Update failure: " . $e->getMessage();
        }
    }
}

// 3. Handle Delete Asset Operation (Admin-Only Action)
if (isset($_GET['delete_id'])) {
    require_admin(); // Security credentials verification
    $id = (int)$_GET['delete_id'];
    
    try {
        // Fetch asset name first for success session alert
        $stmt = $pdo->prepare("SELECT name FROM assets WHERE id = ?");
        $stmt->execute([$id]);
        $asset_name = $stmt->fetchColumn();

        if ($asset_name) {
            $stmt = $pdo->prepare("DELETE FROM assets WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Asset <strong>" . htmlspecialchars($asset_name) . "</strong> has been permanently removed from inventory.";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Delete constraint violation: Cannot delete an asset that is currently assigned to an employee. Terminate assignment first.";
    }
    
    header("Location: assets.php");
    exit;
}

// 4. Fetch Categories & Employees for selection drop-downs
$categories = [];
$employees = [];
try {
    $categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
    $employees = $pdo->query("SELECT id, first_name, last_name FROM employees WHERE status = 'Active' ORDER BY first_name ASC")->fetchAll();
} catch (PDOException $e) {}

// 5. Build Listing Queries (Search, Filter, Paginate)
$search = sanitize($_GET['search'] ?? '');
$filter_category = sanitize($_GET['category_id'] ?? '');
$filter_status = sanitize($_GET['status'] ?? '');

$query_str = "SELECT a.*, c.name as category_name, 
                     emp.first_name, emp.last_name, emp.employee_id
              FROM assets a 
              JOIN categories c ON a.category_id = c.id
              LEFT JOIN assignments asg ON a.id = asg.asset_id AND asg.status = 'Active'
              LEFT JOIN employees emp ON asg.employee_id = emp.id
              WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query_str .= " AND (a.name LIKE ? OR a.asset_tag LIKE ? OR a.brand LIKE ? OR a.serial_number LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if (!empty($filter_category)) {
    $query_str .= " AND a.category_id = ?";
    $params[] = (int)$filter_category;
}

if (!empty($filter_status)) {
    $query_str .= " AND a.status = ?";
    $params[] = $filter_status;
}

$query_str .= " ORDER BY a.id DESC";

$assets = [];
try {
    $stmt = $pdo->prepare($query_str);
    $stmt->execute($params);
    $assets = $stmt->fetchAll();
} catch (PDOException $e) {
    $_SESSION['error'] = "Failed fetching assets: " . $e->getMessage();
}

// 6. Handle active editing fetch request
$edit_asset = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_asset = $stmt->fetch();
    } catch (PDOException $e) {}
}
?>

<!-- Action Bar (Search & Filter Controls) -->
<div class="card">
    <div class="actions-bar">
        <!-- Search -->
        <form action="assets.php" method="GET" class="search-box">
            <input type="text" name="search" class="form-control" placeholder="Search by name, tag, SN..." value="<?php echo htmlspecialchars($search); ?>">
            <i class="fa-solid fa-magnifying-glass"></i>
            <?php if (!empty($filter_category)): ?>
                <input type="hidden" name="category_id" value="<?php echo $filter_category; ?>">
            <?php endif; ?>
            <?php if (!empty($filter_status)): ?>
                <input type="hidden" name="status" value="<?php echo $filter_status; ?>">
            <?php endif; ?>
        </form>

        <!-- Filters & Action Trigger Button -->
        <div class="filters-box">
            <form action="assets.php" method="GET" style="display: flex; gap: 10px; flex-wrap: wrap;">
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                <?php endif; ?>
                
                <select name="category_id" class="form-control" onchange="this.form.submit()" style="width: auto;">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat->id; ?>" <?php echo $filter_category == $cat->id ? 'selected' : ''; ?>>
                            <?php e($cat->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-control" onchange="this.form.submit()" style="width: auto;">
                    <option value="">All Statuses</option>
                    <option value="Available" <?php echo $filter_status === 'Available' ? 'selected' : ''; ?>>Available</option>
                    <option value="Assigned" <?php echo $filter_status === 'Assigned' ? 'selected' : ''; ?>>Assigned</option>
                    <option value="Maintenance" <?php echo $filter_status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    <option value="Damaged" <?php echo $filter_status === 'Damaged' ? 'selected' : ''; ?>>Damaged</option>
                </select>
            </form>

            <button class="btn btn-primary" data-toggle="modal" data-target="#addAssetModal">
                <i class="fa-solid fa-plus"></i> Add New Asset
            </button>
        </div>
    </div>
</div>

<!-- Main Table Display Card -->
<div class="card">
    <div class="table-responsive">
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Tag ID</th>
                    <th>Asset Name</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>Serial Number</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assets)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fa-solid fa-laptop-code" style="font-size: 32px; margin-bottom: 12px; display: block;"></i>
                            No assets registered matching criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assets as $ast): ?>
                        <tr>
                            <td style="font-family: monospace; font-weight: bold; color: var(--text-primary);">
                                <?php e($ast->asset_tag); ?>
                            </td>
                            <td>
                                <div><strong><?php e($ast->name); ?></strong></div>
                                <div style="font-size: 11px; color: var(--text-muted);">Purchased: <?php echo date('M Y', strtotime($ast->purchase_date)); ?></div>
                            </td>
                            <td><?php e($ast->category_name); ?></td>
                            <td><?php e($ast->brand); ?></td>
                            <td style="font-family: monospace; font-size: 12px;"><?php e($ast->serial_number); ?></td>
                            <td>
                                <?php if ($ast->status === 'Available'): ?>
                                    <span class="badge-pill status-available"><i class="fa-solid fa-check"></i> Available</span>
                                <?php elseif ($ast->status === 'Assigned'): ?>
                                    <span class="badge-pill status-assigned"><i class="fa-solid fa-user-check"></i> Assigned</span>
                                <?php elseif ($ast->status === 'Maintenance'): ?>
                                    <span class="badge-pill status-maintenance"><i class="fa-solid fa-wrench"></i> Maintenance</span>
                                <?php else: ?>
                                    <span class="badge-pill status-damaged"><i class="fa-solid fa-triangle-exclamation"></i> Damaged</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ast->status === 'Assigned' && $ast->first_name): ?>
                                    <div><strong><?php e($ast->first_name . ' ' . $ast->last_name); ?></strong></div>
                                    <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;"><?php e($ast->employee_id); ?></div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-style: italic;">None</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 6px;">
                                    <a href="assets.php?edit_id=<?php echo $ast->id; ?>" class="btn btn-secondary btn-icon" title="Edit Asset Info">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <?php if ($_SESSION['role'] === 'Admin'): ?>
                                        <a href="assets.php?delete_id=<?php echo $ast->id; ?>" class="btn btn-secondary btn-icon" style="color: var(--danger);" title="Delete Asset" onclick="return confirm('Are you sure you want to permanently delete asset: <?php echo htmlspecialchars($ast->name); ?>? This action is irreversible.');">
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

<!-- 1. ADD NEW ASSET MODAL -->
<div class="modal <?php echo (isset($_GET['action']) && $_GET['action'] === 'add') ? 'active' : ''; ?>" id="addAssetModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Register Corporate Asset</h3>
            <button class="modal-close" data-dismiss="modal">&times;</button>
        </div>
        <form action="assets.php" method="POST">
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="add_asset_tag">Asset Tag ID *</label>
                        <input type="text" name="asset_tag" id="add_asset_tag" class="form-control" placeholder="AST-2026-XXXX" required>
                    </div>
                    <div class="form-group">
                        <label for="add_name">Asset Name *</label>
                        <input type="text" name="name" id="add_name" class="form-control" placeholder="e.g. MacBook Pro 14" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="add_category">Asset Category *</label>
                        <select name="category_id" id="add_category" class="form-control" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat->id; ?>"><?php e($cat->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="add_brand">Brand / Manufacturer *</label>
                        <input type="text" name="brand" id="add_brand" class="form-control" placeholder="e.g. Apple" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="add_serial">Serial Number *</label>
                        <input type="text" name="serial_number" id="add_serial" class="form-control" placeholder="Unique OEM Serial" required>
                    </div>
                    <div class="form-group">
                        <label for="add_price">Purchase Cost (USD) *</label>
                        <input type="number" name="price" id="add_price" class="form-control" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="add_purchase_date">Purchase Date *</label>
                        <input type="date" name="purchase_date" id="add_purchase_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="add_warranty_date">Warranty Expiration *</label>
                        <input type="date" name="warranty_date" id="add_warranty_date" class="form-control" required value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="add_status">Initial Inventory Status *</label>
                    <select name="status" id="add_status" class="form-control" required>
                        <option value="Available">Available</option>
                        <option value="Maintenance">Maintenance</option>
                        <option value="Damaged">Damaged / Defect</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" name="action_create" class="btn btn-primary">Register Asset</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. EDIT EXISTING ASSET MODAL -->
<?php if ($edit_asset): ?>
<div class="modal active" id="editAssetModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Modify Asset Specifications</h3>
            <a href="assets.php" class="modal-close" style="text-decoration: none; color: inherit;">&times;</a>
        </div>
        <form action="assets.php" method="POST">
            <input type="hidden" name="asset_id" value="<?php echo $edit_asset->id; ?>">
            <div class="modal-body">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="edit_asset_tag">Asset Tag ID *</label>
                        <input type="text" name="asset_tag" id="edit_asset_tag" class="form-control" value="<?php e($edit_asset->asset_tag); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_name">Asset Name *</label>
                        <input type="text" name="name" id="edit_name" class="form-control" value="<?php e($edit_asset->name); ?>" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="edit_category">Asset Category *</label>
                        <select name="category_id" id="edit_category" class="form-control" required>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat->id; ?>" <?php echo $edit_asset->category_id == $cat->id ? 'selected' : ''; ?>>
                                    <?php e($cat->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_brand">Brand / Manufacturer *</label>
                        <input type="text" name="brand" id="edit_brand" class="form-control" value="<?php e($edit_asset->brand); ?>" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="edit_serial">Serial Number *</label>
                        <input type="text" name="serial_number" id="edit_serial" class="form-control" value="<?php e($edit_asset->serial_number); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_price">Purchase Cost (USD) *</label>
                        <input type="number" name="price" id="edit_price" class="form-control" value="<?php echo $edit_asset->price; ?>" step="0.01" min="0.01" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label for="edit_purchase_date">Purchase Date *</label>
                        <input type="date" name="purchase_date" id="edit_purchase_date" class="form-control" value="<?php e($edit_asset->purchase_date); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_warranty_date">Warranty Expiration *</label>
                        <input type="date" name="warranty_date" id="edit_warranty_date" class="form-control" value="<?php e($edit_asset->warranty_date); ?>" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label for="edit_status">Asset Inventory Status *</label>
                    <select name="status" id="edit_status" class="form-control" required>
                        <!-- Assigned status cannot be manually set to avoid bypass, assignments are handled under Assignments Hub -->
                        <option value="Available" <?php echo $edit_asset->status === 'Available' ? 'selected' : ''; ?>>Available</option>
                        <option value="Assigned" <?php echo $edit_asset->status === 'Assigned' ? 'selected' : ''; ?> disabled>Assigned (Set through Assignments)</option>
                        <option value="Maintenance" <?php echo $edit_asset->status === 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                        <option value="Damaged" <?php echo $edit_asset->status === 'Damaged' ? 'selected' : ''; ?>>Damaged / Defect</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <a href="assets.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" name="action_update" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
