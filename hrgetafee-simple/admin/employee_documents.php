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
        }
    }
    $error = 'File not found.';
}

$documents = [];
$stmt = $conn->prepare("SELECT ed.*, e.employee_code, e.first_name, e.last_name FROM employee_documents ed JOIN employees e ON e.employee_id = ed.employee_id ORDER BY ed.uploaded_at DESC");
if ($stmt) {
    $stmt->execute();
    $documents = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Files - HRGetafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f5f7fb; }
        .card { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        th { background: #f8fafc; }
        .btn { display:inline-block; padding:9px 12px; border-radius:8px; text-decoration:none; color:#fff; background:#667eea; }
        .tag { display:inline-block; padding:4px 8px; border-radius:999px; font-size:12px; font-weight:700; }
        .tag-active { background:#dcfce7; color:#166534; }
        .tag-inactive { background:#fef3c7; color:#92400e; }
    </style>
</head>
<body>
    <div class="navbar">
        <h2>Employee Files</h2>
        <div class="navbar-user">
            <span>Welcome, <strong><?php echo htmlspecialchars($admin['first_name']); ?></strong></span>
            <a href="../logout.php">Logout</a>
        </div>
    </div>

    <div class="container">
        <a href="dashboard.php" class="btn" style="margin-bottom:20px;">← Back to Dashboard</a>

        <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>

        <div class="card">
            <h3>All Uploaded Documents</h3>
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Code</th>
                        <th>Document</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Uploaded</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($documents)): ?>
                        <tr><td colspan="7" style="padding:16px;">No documents uploaded.</td></tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($doc['employee_code']); ?></td>
                                <td><?php echo htmlspecialchars($doc['document_name']); ?></td>
                                <td><?php echo htmlspecialchars($doc['document_type']); ?></td>
                                <td>
                                    <span class="tag <?php echo ($doc['is_active'] == 1) ? 'tag-active' : 'tag-inactive'; ?>">
                                        <?php echo ($doc['is_active'] == 1) ? 'Active' : 'Archived'; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(date('M d, Y', strtotime($doc['uploaded_at']))); ?></td>
                                <td><a href="employee_documents.php?download_document=<?php echo (int)$doc['document_id']; ?>" class="btn">Download</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>