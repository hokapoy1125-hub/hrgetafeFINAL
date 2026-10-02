<?php
session_start();
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

require_login();
check_role(1);

$admin = get_employee_info($conn, $_SESSION['employee_id']);
$message = '';
$error = '';
$edit_employee = null;
$selected_employee_id = intval($_GET['employee_id'] ?? 0);

// upload folder
$upload_root = __DIR__ . '/../uploads/employee_documents';
if (!is_dir($upload_root)) {
    mkdir($upload_root, 0777, true);
}

// Handle document download / archive toggle
if (isset($_GET['download_document'])) {
    $document_id = intval($_GET['download_document']);
    $stmt = $conn->prepare("SELECT * FROM employee_documents WHERE document_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $document_id);
        $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($doc && file_exists($doc['file_path'])) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($doc['document_name']) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($doc['file_path']));
            readfile($doc['file_path']);
            exit;
        } else {
            $error = 'Document file not found.';
        }
    }
}

if (isset($_GET['toggle_document'])) {
    $document_id = intval($_GET['toggle_document']);
    $stmt = $conn->prepare("SELECT is_active FROM employee_documents WHERE document_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $document_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $doc = $res->fetch_assoc();
        $stmt->close();

        if ($doc) {
            $new_status = ($doc['is_active'] == 1) ? 0 : 1;
            $update = $conn->prepare("UPDATE employee_documents SET is_active = ? WHERE document_id = ?");
            if ($update) {
                $update->bind_param('ii', $new_status, $document_id);
                $update->execute();
                $update->close();
                $message = 'Document status updated.';
            }
        }
    }
}

// Get all employees
$employees_result = $conn->query("SELECT * FROM employees ORDER BY employee_code");
$employees = $employees_result ? $employees_result->fetch_all(MYSQLI_ASSOC) : [];

// Check if editing
if (isset($_GET['edit'])) {
    $employee_id = intval($_GET['edit']);
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ? LIMIT 1");
    $stmt->bind_param('i', $employee_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $edit_employee = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (empty($_POST['csrf_token']) || !verifyCSRFToken($_POST['csrf_token'])) {
        $error = 'Invalid or expired form token.';
    } else {
        // add employee
        if ($action === 'add_employee') {
            $first_name = sanitizeInput($_POST['first_name'] ?? '');
            $last_name = sanitizeInput($_POST['last_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $position = sanitizeInput($_POST['position'] ?? '');
            $employment_type = sanitizeInput($_POST['employment_type'] ?? 'Regular');
            $salary = floatval($_POST['salary'] ?? 0);
            $date_hired = sanitizeInput($_POST['date_hired'] ?? date('Y-m-d'));
            $status = sanitizeInput($_POST['status'] ?? 'active');

            if ($first_name === '' || $last_name === '' || $email === '') {
                $error = 'First name, last name, and email are required.';
            } else {
                $year = date('Y');
                $like = "GETAFE-{$year}-%";
                $stmt = $conn->prepare("SELECT employee_code FROM employees WHERE employee_code LIKE ? ORDER BY employee_code DESC LIMIT 1");
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $res = $stmt->get_result();
                $last_emp = $res->fetch_assoc();
                $stmt->close();

                $next_num = 1;
                if ($last_emp && preg_match('/GETAFE-\d+-(\d+)/', $last_emp['employee_code'], $matches)) {
                    $next_num = intval($matches[1]) + 1;
                }

                $employee_code = 'GETAFE-' . $year . '-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO employees (employee_code, first_name, last_name, email, phone, position, employment_type, salary, date_hired, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssssdss', $employee_code, $first_name, $last_name, $email, $phone, $position, $employment_type, $salary, $date_hired, $status);

                if ($stmt->execute()) {
                    $new_employee_id = $stmt->insert_id;
                    $message = 'Employee added successfully.';
                    logSecurityEvent($conn, $_SESSION['user_id'], 'ADD_EMPLOYEE', "Added employee: $employee_code");
                    header('Location: manage_employees.php?employee_id=' . $new_employee_id);
                    exit;
                } else {
                    $error = 'Error adding employee: ' . htmlspecialchars($stmt->error);
                }
                $stmt->close();
            }
        }

        // edit employee
        elseif ($action === 'edit_employee') {
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $first_name = sanitizeInput($_POST['first_name'] ?? '');
            $last_name = sanitizeInput($_POST['last_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $phone = sanitizeInput($_POST['phone'] ?? '');
            $position = sanitizeInput($_POST['position'] ?? '');
            $employment_type = sanitizeInput($_POST['employment_type'] ?? 'Regular');
            $salary = floatval($_POST['salary'] ?? 0);
            $date_hired = sanitizeInput($_POST['date_hired'] ?? date('Y-m-d'));
            $status = sanitizeInput($_POST['status'] ?? 'active');

            if ($employee_id <= 0 || $first_name === '' || $last_name === '') {
                $error = 'Invalid input.';
            } else {
                $stmt = $conn->prepare("UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, position=?, employment_type=?, salary=?, date_hired=?, status=? WHERE employee_id=?");
                $stmt->bind_param('ssssssdssi', $first_name, $last_name, $email, $phone, $position, $employment_type, $salary, $date_hired, $status, $employee_id);
                if ($stmt->execute()) {
                    $message = 'Employee updated successfully.';
                    logSecurityEvent($conn, $_SESSION['user_id'], 'EDIT_EMPLOYEE', "Updated employee_id: $employee_id");
                    header('Location: manage_employees.php?employee_id=' . $employee_id);
                    exit;
                } else {
                    $error = 'Error updating employee: ' . htmlspecialchars($stmt->error);
                }
                $stmt->close();
            }
        }

        // upload employee document
        elseif ($action === 'upload_document') {
            $employee_id = intval($_POST['employee_id'] ?? 0);
            $document_type = sanitizeInput($_POST['document_type'] ?? '');
            $document_name = sanitizeInput($_POST['document_name'] ?? '');
            $notes = sanitizeInput($_POST['notes'] ?? '');
            $file = $_FILES['document_file'] ?? null;

            if ($employee_id <= 0) {
                $error = 'Please select an employee.';
            } elseif ($document_type === '') {
                $error = 'Please select document type.';
            } elseif (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                $error = 'Please upload a valid file.';
            } else {
                $allowed_types = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'xls', 'xlsx', 'txt'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_types, true)) {
                    $error = 'Unsupported file type. Allowed: PDF, DOC, DOCX, JPG, PNG, XLS, TXT.';
                } else {
                    $employee_dir = $upload_root . '/' . $employee_id;
                    if (!is_dir($employee_dir)) {
                        mkdir($employee_dir, 0777, true);
                    }

                    $safe_name = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($file['name']));
                    $new_name = time() . '_' . $safe_name;
                    $file_path = $employee_dir . '/' . $new_name;

                    if (move_uploaded_file($file['tmp_name'], $file_path)) {
                        $stmt = $conn->prepare("INSERT INTO employee_documents (employee_id, document_type, document_name, file_path, file_size, uploaded_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        if ($stmt) {
                            $stmt->bind_param('isssiis', $employee_id, $document_type, $document_name, $file_path, $file['size'], $_SESSION['user_id'], $notes);
                            if ($stmt->execute()) {
                                $message = 'Document uploaded successfully.';
                                header('Location: manage_employees.php?employee_id=' . $employee_id);
                                exit;
                            } else {
                                $error = 'Error saving document record: ' . htmlspecialchars($stmt->error);
                            }
                            $stmt->close();
                        } else {
                            $error = 'Database error: ' . htmlspecialchars($conn->error);
                        }
                    } else {
                        $error = 'File upload failed. Please try again.';
                    }
                }
            }
        }
    }
}

// selected employee details
$selected_employee = null;
if ($selected_employee_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM employees WHERE employee_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $selected_employee_id);
        $stmt->execute();
        $selected_employee = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// load documents for selected employee
$employee_documents = [];
if ($selected_employee_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY uploaded_at DESC");
    if ($stmt) {
        $stmt->bind_param('i', $selected_employee_id);
        $stmt->execute();
        $employee_documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

if (!$selected_employee && isset($_GET['edit'])) {
    $selected_employee = $edit_employee;
    if ($selected_employee) {
        $selected_employee_id = (int)$selected_employee['employee_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f5f7fb; }
        .card-form { background: white; padding: 22px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); }
        .form-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .doc-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
        .doc-item { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; }
        .doc-item.active { border-left: 4px solid #10b981; }
        .doc-item.inactive { border-left: 4px solid #f59e0b; }
        .btn { display: inline-block; padding: 9px 14px; border-radius: 8px; text-decoration: none; border: none; cursor: pointer; font-weight: 600; }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #e5e7eb; color: #1f2937; }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .alert { padding: 12px 14px; border-radius: 8px; margin-bottom: 18px; }
        .alert-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        table { width: 100%; border-collapse: collapse; background: white; }
        th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f8fafc; }
        .tag { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .tag-active { background: #dcfce7; color: #166534; }
        .tag-inactive { background: #fef3c7; color: #92400e; }
        label { display: block; margin-bottom: 6px; font-weight: 600; color: #374151; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
        textarea { min-height: 90px; resize: vertical; }
        .file-input { padding: 8px; }
        @media (max-width: 768px) {
            .form-row, .doc-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>Manage Employee Records</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($admin['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <a href="dashboard.php" class="btn btn-secondary" style="margin-bottom: 18px;">← Back to Dashboard</a>

        <!-- Add / Edit employee form -->
        <div class="card-form">
            <h3><?php echo $edit_employee ? 'Edit Employee' : 'Add New Employee'; ?></h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="<?php echo $edit_employee ? 'edit_employee' : 'add_employee'; ?>">
                <?php if ($edit_employee): ?>
                    <input type="hidden" name="employee_id" value="<?php echo (int)$edit_employee['employee_id']; ?>">
                <?php endif; ?>

                <div class="form-row">
                    <div>
                        <label>First Name *</label>
                        <input type="text" name="first_name" required value="<?php echo htmlspecialchars($edit_employee['first_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Last Name *</label>
                        <input type="text" name="last_name" required value="<?php echo htmlspecialchars($edit_employee['last_name'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Email *</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($edit_employee['email'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Phone</label>
                        <input type="text" name="phone" value="<?php echo htmlspecialchars($edit_employee['phone'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Position</label>
                        <input type="text" name="position" value="<?php echo htmlspecialchars($edit_employee['position'] ?? ''); ?>">
                    </div>
                    <div>
                        <label>Employment Type</label>
                        <select name="employment_type">
                            <option value="Regular" <?php echo (($edit_employee['employment_type'] ?? 'Regular') === 'Regular') ? 'selected' : ''; ?>>Regular</option>
                            <option value="Casual" <?php echo (($edit_employee['employment_type'] ?? '') === 'Casual') ? 'selected' : ''; ?>>Casual</option>
                            <option value="Job Order" <?php echo (($edit_employee['employment_type'] ?? '') === 'Job Order') ? 'selected' : ''; ?>>Job Order</option>
                            <option value="COS" <?php echo (($edit_employee['employment_type'] ?? '') === 'COS') ? 'selected' : ''; ?>>COS</option>
                        </select>
                    </div>
                    <div>
                        <label>Salary (₱)</label>
                        <input type="number" step="0.01" min="0" name="salary" value="<?php echo htmlspecialchars($edit_employee['salary'] ?? '0'); ?>">
                    </div>
                    <div>
                        <label>Date Hired</label>
                        <input type="date" name="date_hired" value="<?php echo htmlspecialchars($edit_employee['date_hired'] ?? date('Y-m-d')); ?>">
                    </div>
                    <div>
                        <label>Status</label>
                        <select name="status">
                            <option value="active" <?php echo (($edit_employee['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo (($edit_employee['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 16px;">
                    <button type="submit" class="btn btn-primary"><?php echo $edit_employee ? 'Update Employee' : 'Add Employee'; ?></button>
                    <?php if ($edit_employee): ?>
                        <a href="manage_employees.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Document Upload -->
        <?php if ($selected_employee_id > 0): ?>
            <div class="card-form">
                <h3>Upload Employee File</h3>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="upload_document">
                    <input type="hidden" name="employee_id" value="<?php echo $selected_employee_id; ?>">

                    <div class="form-row">
                        <div>
                            <label>Document Type</label>
                            <select name="document_type" required>
                                <option value="">-- Select --</option>
                                <option value="credentials">Credentials</option>
                                <option value="201_file">201 File</option>
                                <option value="contract">Contract</option>
                                <option value="cos">COS</option>
                                <option value="diploma">Diploma / TOR</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div>
                            <label>Document Label</label>
                            <input type="text" name="document_name" placeholder="Example: Diploma, Contract, 201 Form" required>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>File</label>
                            <input type="file" name="document_file" class="file-input" required>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <label>Notes</label>
                            <textarea name="notes" placeholder="Optional notes or remarks"></textarea>
                        </div>
                    </div>

                    <div style="margin-top: 16px;">
                        <button type="submit" class="btn btn-primary">Upload Document</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Employee documents list -->
        <?php if ($selected_employee_id > 0 && $selected_employee): ?>
            <div class="card-form">
                <h3>Employee Records / Documents</h3>

                <div style="margin-bottom: 14px; padding: 12px; border-radius: 8px; background: #f8fafc;">
                    <strong>Name:</strong> <?php echo htmlspecialchars($selected_employee['first_name'] . ' ' . $selected_employee['last_name']); ?><br>
                    <strong>Employee Code:</strong> <?php echo htmlspecialchars($selected_employee['employee_code']); ?><br>
                    <strong>Status:</strong> <?php echo htmlspecialchars($selected_employee['status']); ?>
                </div>

                <?php if (empty($employee_documents)): ?>
                    <p style="color: #6b7280;">No documents uploaded yet.</p>
                <?php else: ?>
                    <div class="doc-grid">
                        <?php foreach ($employee_documents as $doc): ?>
                            <div class="doc-item <?php echo ($doc['is_active'] == 1) ? 'active' : 'inactive'; ?>">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                                    <strong><?php echo htmlspecialchars($doc['document_name']); ?></strong>
                                    <span class="tag <?php echo ($doc['is_active'] == 1) ? 'tag-active' : 'tag-inactive'; ?>">
                                        <?php echo ($doc['is_active'] == 1) ? 'Active' : 'Archived'; ?>
                                    </span>
                                </div>
                                <div style="margin: 8px 0; color: #475569; font-size: 13px;">
                                    Type: <?php echo htmlspecialchars($doc['document_type']); ?><br>
                                    Size: <?php echo number_format((float)($doc['file_size'] / 1024), 2); ?> KB<br>
                                    Uploaded: <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($doc['uploaded_at']))); ?>
                                </div>
                                <?php if (!empty($doc['notes'])): ?>
                                    <div style="font-size: 12px; color: #64748b; margin-bottom: 10px;">
                                        Notes: <?php echo htmlspecialchars($doc['notes']); ?>
                                    </div>
                                <?php endif; ?>

                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a href="manage_employees.php?employee_id=<?php echo $selected_employee_id; ?>&download_document=<?php echo (int)$doc['document_id']; ?>" class="btn btn-success">Download</a>
                                    <a href="manage_employees.php?employee_id=<?php echo $selected_employee_id; ?>&toggle_document=<?php echo (int)$doc['document_id']; ?>" class="btn btn-warning">
                                        <?php echo ($doc['is_active'] == 1) ? 'Archive' : 'Activate'; ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Employee List -->
        <div style="background:white;padding:16px;border-radius:12px; box-shadow: 0 2px 10px rgba(0,0,0,0.04);">
            <h3>Employee Records (<?php echo count($employees); ?>)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Position</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="7" style="color:#6b7280; padding: 16px;">No employees found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($emp['employee_code']); ?></td>
                                <td><?php echo htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['email']); ?></td>
                                <td><?php echo htmlspecialchars($emp['position'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($emp['employment_type'] ?? 'Regular'); ?></td>
                                <td>
                                    <span class="tag <?php echo ($emp['status'] === 'active') ? 'tag-active' : 'tag-inactive'; ?>">
                                        <?php echo htmlspecialchars($emp['status']); ?>
                                    </span>
                                </td>
                                <td style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <a href="manage_employees.php?edit=<?php echo (int)$emp['employee_id']; ?>" class="btn btn-secondary">Edit</a>
                                    <a href="manage_employees.php?employee_id=<?php echo (int)$emp['employee_id']; ?>" class="btn btn-primary">Files</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>