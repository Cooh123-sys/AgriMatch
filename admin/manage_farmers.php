<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

// Guard: only admins allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

// ---------- HANDLE APPROVE / REJECT ACTION ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    $userId = (int) $_POST['user_id'];
    $action = $_POST['action'] === 'approve' ? 'verified' : 'rejected';

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ? AND role = 'farmer'");
    $stmt->bind_param('si', $action, $userId);
    $stmt->execute();
    $stmt->close();

    // Fetch farmer's email + name to notify them
    $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $farmerInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($farmerInfo) {
        sendStatusEmail($farmerInfo['email'], $farmerInfo['full_name'], 'farmer', $action);
    }

    $_SESSION['flash'] = [
        'type' => $action === 'verified' ? 'success' : 'warning',
        'msg'  => "Farmer account has been {$action}. Notification email sent."
    ];
    header('Location: /AgriMatch/admin/manage_farmers.php');
    exit;
}

// ---------- FETCH ALL FARMERS ----------
$farmers = $conn->query("
    SELECT u.user_id, u.full_name, u.email, u.phone, u.status, u.created_at,
           f.farmer_id, f.location, f.id_document, f.map_document
    FROM users u
    JOIN farmer_details f ON f.user_id = u.user_id
    WHERE u.role = 'farmer'
    ORDER BY 
        CASE u.status WHEN 'pending' THEN 0 WHEN 'verified' THEN 1 ELSE 2 END,
        u.created_at DESC
");

// Fetch crops per farmer (small dataset, simple loop is fine for this project)
$cropsByFarmer = [];
$cropResult = $conn->query("SELECT farmer_id, crop_type FROM farmer_crops");
while ($row = $cropResult->fetch_assoc()) {
    $cropsByFarmer[$row['farmer_id']][] = $row['crop_type'];
}

$pageTitle = 'Manage Farmers';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-people-fill"></i> Manage Farmers</h2>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Location</th>
                    <th>Crops</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($farmers->num_rows === 0): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No farmers registered yet.</td></tr>
                <?php else: ?>
                    <?php while ($f = $farmers->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($f['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($f['location']); ?></td>
                            <td>
                                <?php
                                $crops = $cropsByFarmer[$f['farmer_id']] ?? [];
                                echo $crops ? htmlspecialchars(implode(', ', $crops)) : '<span class="text-muted">None</span>';
                                ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php
                                    echo $f['status'] === 'verified' ? 'success' : ($f['status'] === 'pending' ? 'warning' : 'danger');
                                ?>">
                                    <?php echo ucfirst($f['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d M Y', strtotime($f['created_at'])); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#farmerModal<?php echo $f['user_id']; ?>">
                                    <i class="bi bi-eye"></i> View
                                </button>
                            </td>
                        </tr>

                        <!-- Detail Modal -->
                        <div class="modal fade" id="farmerModal<?php echo $f['user_id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-success text-white">
                                        <h5 class="modal-title"><?php echo htmlspecialchars($f['full_name']); ?> — Farmer Details</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($f['email']); ?></p>
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($f['phone']); ?></p>
                                        <p><strong>Location:</strong> <?php echo htmlspecialchars($f['location']); ?></p>
                                        <p><strong>Crop Types:</strong>
                                            <?php echo $crops ? htmlspecialchars(implode(', ', $crops)) : 'None listed'; ?>
                                        </p>
                                        <p><strong>Current Status:</strong>
                                            <span class="badge bg-<?php
                                                echo $f['status'] === 'verified' ? 'success' : ($f['status'] === 'pending' ? 'warning' : 'danger');
                                            ?>"><?php echo ucfirst($f['status']); ?></span>
                                        </p>
                                        <hr>
                                        <h6>Submitted Documents</h6>
                                        <ul>
                                            <li>
                                                National ID:
                                                <?php if ($f['id_document']): ?>
                                                    <a href="/AgriMatch/<?php echo htmlspecialchars($f['id_document']); ?>" target="_blank">View Document</a>
                                                <?php else: ?>
                                                    <span class="text-muted">Not submitted</span>
                                                <?php endif; ?>
                                            </li>
                                            <li>
                                                Map to Home:
                                                <?php if ($f['map_document']): ?>
                                                    <a href="/AgriMatch/<?php echo htmlspecialchars($f['map_document']); ?>" target="_blank">View Document</a>
                                                <?php else: ?>
                                                    <span class="text-muted">Not submitted</span>
                                                <?php endif; ?>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="modal-footer">
                                        <?php if ($f['status'] !== 'verified'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="user_id" value="<?php echo $f['user_id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-check-circle"></i> Approve
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($f['status'] !== 'rejected'): ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Reject this farmer? An email will be sent to notify them.');">
                                                <input type="hidden" name="user_id" value="<?php echo $f['user_id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="btn btn-danger">
                                                    <i class="bi bi-x-circle"></i> Reject
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>