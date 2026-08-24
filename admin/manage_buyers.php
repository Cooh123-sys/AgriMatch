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

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ? AND role = 'buyer'");
    $stmt->bind_param('si', $action, $userId);
    $stmt->execute();
    $stmt->close();

    // Fetch buyer's email + company name to notify them
    $stmt = $conn->prepare("SELECT full_name, email FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $buyerInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($buyerInfo) {
        sendStatusEmail($buyerInfo['email'], $buyerInfo['full_name'], 'buyer', $action);
    }

    $_SESSION['flash'] = [
        'type' => $action === 'verified' ? 'success' : 'warning',
        'msg'  => "Buyer account has been {$action}. Notification email sent."
    ];
    header('Location: /AgriMatch/admin/manage_buyers.php');
    exit;
}

// ---------- FETCH ALL BUYERS ----------
$buyers = $conn->query("
    SELECT u.user_id, u.full_name AS company_name, u.email, u.phone, u.status, u.created_at,
           b.buyer_id, b.physical_address, b.organization_type, b.business_certificate
    FROM users u
    JOIN buyer_details b ON b.user_id = u.user_id
    WHERE u.role = 'buyer'
    ORDER BY 
        CASE u.status WHEN 'pending' THEN 0 WHEN 'verified' THEN 1 ELSE 2 END,
        u.created_at DESC
");

$orgLabels = [
    'school' => 'School',
    'hotel' => 'Hotel',
    'manufacturing_company' => 'Manufacturing Company',
    'hospital' => 'Hospital',
    'retailer' => 'Retailer',
    'wholesaler' => 'Wholesaler',
    'exporter' => 'Exporter',
    'other' => 'Other'
];

$pageTitle = 'Manage Buyers';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-building-fill"></i> Manage Buyers</h2>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Company Name</th>
                    <th>Org Type</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($buyers->num_rows === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No buyers registered yet.</td></tr>
                <?php else: ?>
                    <?php while ($b = $buyers->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($b['company_name']); ?></td>
                            <td><?php echo htmlspecialchars($orgLabels[$b['organization_type']] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge bg-<?php
                                    echo $b['status'] === 'verified' ? 'success' : ($b['status'] === 'pending' ? 'warning' : 'danger');
                                ?>">
                                    <?php echo ucfirst($b['status']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d M Y', strtotime($b['created_at'])); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#buyerModal<?php echo $b['user_id']; ?>">
                                    <i class="bi bi-eye"></i> View
                                </button>
                            </td>
                        </tr>

                        <!-- Detail Modal -->
                        <div class="modal fade" id="buyerModal<?php echo $b['user_id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header bg-success text-white">
                                        <h5 class="modal-title"><?php echo htmlspecialchars($b['company_name']); ?> — Buyer Details</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p><strong>Email:</strong> <?php echo htmlspecialchars($b['email']); ?></p>
                                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($b['phone']); ?></p>
                                        <p><strong>Physical Address:</strong> <?php echo htmlspecialchars($b['physical_address']); ?></p>
                                        <p><strong>Organisation Type:</strong> <?php echo htmlspecialchars($orgLabels[$b['organization_type']] ?? 'N/A'); ?></p>
                                        <p><strong>Current Status:</strong>
                                            <span class="badge bg-<?php
                                                echo $b['status'] === 'verified' ? 'success' : ($b['status'] === 'pending' ? 'warning' : 'danger');
                                            ?>"><?php echo ucfirst($b['status']); ?></span>
                                        </p>
                                        <hr>
                                        <h6>Submitted Documents</h6>
                                        <ul>
                                            <li>
                                                Business Certificate:
                                                <?php if ($b['business_certificate']): ?>
                                                    <a href="/AgriMatch/<?php echo htmlspecialchars($b['business_certificate']); ?>" target="_blank">View Document</a>
                                                <?php else: ?>
                                                    <span class="text-muted">Not submitted</span>
                                                <?php endif; ?>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="modal-footer">
                                        <?php if ($b['status'] !== 'verified'): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="user_id" value="<?php echo $b['user_id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="bi bi-check-circle"></i> Approve
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($b['status'] !== 'rejected'): ?>
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Reject this buyer? An email will be sent to notify them.');">
                                                <input type="hidden" name="user_id" value="<?php echo $b['user_id']; ?>">
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