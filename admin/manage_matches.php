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

// ---------- HANDLE ADMIN CANCEL ACTION ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_id'], $_POST['action']) && $_POST['action'] === 'cancel') {
    $matchId = (int) $_POST['match_id'];

    $stmt = $conn->prepare("
        SELECT m.listing_id, m.demand_id, m.status, p.crop_type,
               uf.full_name AS farmer_name, uf.email AS farmer_email,
               ub.full_name AS buyer_name, ub.email AS buyer_email
        FROM matches m
        JOIN produce_listings p ON p.listing_id = m.listing_id
        JOIN farmer_details f ON f.farmer_id = p.farmer_id
        JOIN users uf ON uf.user_id = f.user_id
        JOIN demands d ON d.demand_id = m.demand_id
        JOIN buyer_details b ON b.buyer_id = d.buyer_id
        JOIN users ub ON ub.user_id = b.user_id
        WHERE m.match_id = ?
    ");
    $stmt->bind_param('i', $matchId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($info) {
        // Cancel the match
        $stmt = $conn->prepare("UPDATE matches SET status = 'rejected' WHERE match_id = ?");
        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $stmt->close();

        // Reopen the listing and demand so they can be matched again
        $stmt = $conn->prepare("UPDATE produce_listings SET status = 'available' WHERE listing_id = ?");
        $stmt->bind_param('i', $info['listing_id']);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE demands SET status = 'open' WHERE demand_id = ?");
        $stmt->bind_param('i', $info['demand_id']);
        $stmt->execute();
        $stmt->close();

        // Notify both parties
        $subject = "AgriMatch: Match Cancelled by Admin — {$info['crop_type']}";
        $body = "
            <h3>Hello,</h3>
            <p>An administrator has cancelled the <strong>{$info['crop_type']}</strong> match you were part of on AgriMatch.</p>
            <p>The listing/demand involved has been reopened and is available for new matches.</p>
            <p>If you have questions, please contact the AgriMatch admin team.</p>
        ";
        try {
            $m1 = buildMailer($info['farmer_email'], $info['farmer_name'], $subject, $body);
            $m1->send();
        } catch (Exception $e) {
            error_log('Admin cancel email to farmer failed: ' . $e->getMessage());
        }
        try {
            $m2 = buildMailer($info['buyer_email'], $info['buyer_name'], $subject, $body);
            $m2->send();
        } catch (Exception $e) {
            error_log('Admin cancel email to buyer failed: ' . $e->getMessage());
        }

        $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Match cancelled. Both parties have been notified and the listing/demand reopened.'];
    }

    header('Location: /AgriMatch/admin/manage_matches.php');
    exit;
}

// ---------- OPTIONAL STATUS FILTER ----------
$statusFilter = $_GET['status'] ?? '';
$where = $statusFilter !== '' ? "WHERE m.status = ?" : '';

$sql = "
    SELECT m.match_id, m.status, m.match_date,
           p.crop_type, p.quantity AS listing_quantity, p.unit,
           d.min_quantity, d.preferred_location,
           uf.full_name AS farmer_name, uf.phone AS farmer_phone, uf.email AS farmer_email,
           ub.full_name AS buyer_name, ub.phone AS buyer_phone, ub.email AS buyer_email
    FROM matches m
    JOIN produce_listings p ON p.listing_id = m.listing_id
    JOIN farmer_details f ON f.farmer_id = p.farmer_id
    JOIN users uf ON uf.user_id = f.user_id
    JOIN demands d ON d.demand_id = m.demand_id
    JOIN buyer_details b ON b.buyer_id = d.buyer_id
    JOIN users ub ON ub.user_id = b.user_id
    $where
    ORDER BY 
        CASE m.status WHEN 'proposed' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,
        m.match_date DESC
";
$stmt = $conn->prepare($sql);
if ($statusFilter !== '') {
    $stmt->bind_param('s', $statusFilter);
}
$stmt->execute();
$result = $stmt->get_result();

$matches = [];
while ($row = $result->fetch_assoc()) {
    $matches[] = $row;
}
$stmt->close();

// Summary counts
$totalMatches    = $conn->query("SELECT COUNT(*) c FROM matches")->fetch_assoc()['c'];
$proposedMatches = $conn->query("SELECT COUNT(*) c FROM matches WHERE status = 'proposed'")->fetch_assoc()['c'];
$acceptedMatches = $conn->query("SELECT COUNT(*) c FROM matches WHERE status = 'accepted'")->fetch_assoc()['c'];
$rejectedMatches = $conn->query("SELECT COUNT(*) c FROM matches WHERE status = 'rejected'")->fetch_assoc()['c'];

$unitLabels = [
    'kg' => 'kg', 'tonnes' => 'tonnes', 'bags_50kg' => '50kg bags',
    'bags_90kg' => '90kg bags', 'crates' => 'crates'
];

$pageTitle = 'Manage Matches';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-link-45deg"></i> Manage Matches</h2>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-collection-fill text-success fs-1"></i>
                <h6 class="mt-2">Total Matches</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $totalMatches; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-hourglass-split text-warning fs-1"></i>
                <h6 class="mt-2">Proposed</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $proposedMatches; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-check-circle-fill text-success fs-1"></i>
                <h6 class="mt-2">Accepted</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $acceptedMatches; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-x-circle-fill text-danger fs-1"></i>
                <h6 class="mt-2">Rejected</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $rejectedMatches; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Filter by Status</label>
                <select class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['proposed','accepted','rejected'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>>
                            <?php echo ucfirst($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-success"><i class="bi bi-funnel"></i> Apply</button>
                <a href="/AgriMatch/admin/manage_matches.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Matches Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Crop</th>
                    <th>Farmer</th>
                    <th>Buyer</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($matches)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No matches found.</td></tr>
                <?php else: ?>
                    <?php foreach ($matches as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($m['crop_type']); ?></td>
                            <td><?php echo htmlspecialchars($m['farmer_name']); ?></td>
                            <td><?php echo htmlspecialchars($m['buyer_name']); ?></td>
                            <td><?php echo number_format($m['listing_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['unit']] ?? $m['unit']); ?></td>
                            <td>
                                <span class="badge bg-<?php
                                    echo $m['status'] === 'accepted' ? 'success' : ($m['status'] === 'proposed' ? 'warning' : 'danger');
                                ?>"><?php echo ucfirst($m['status']); ?></span>
                            </td>
                            <td><?php echo date('d M Y', strtotime($m['match_date'])); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#matchModal<?php echo $m['match_id']; ?>">
                                    <i class="bi bi-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php foreach ($matches as $m): ?>
    <div class="modal fade" id="matchModal<?php echo $m['match_id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><?php echo htmlspecialchars($m['crop_type']); ?> Match</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-success">Farmer</h6>
                            <p class="mb-1"><?php echo htmlspecialchars($m['farmer_name']); ?></p>
                            <p class="mb-1"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($m['farmer_phone']); ?></p>
                            <p class="mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($m['farmer_email']); ?></p>
                            <p class="mb-1"><i class="bi bi-box-seam"></i> <?php echo number_format($m['listing_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['unit']] ?? $m['unit']); ?> available</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-success">Buyer</h6>
                            <p class="mb-1"><?php echo htmlspecialchars($m['buyer_name']); ?></p>
                            <p class="mb-1"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($m['buyer_phone']); ?></p>
                            <p class="mb-1"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($m['buyer_email']); ?></p>
                            <p class="mb-1"><i class="bi bi-clipboard-check"></i> Min. <?php echo number_format($m['min_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['unit']] ?? $m['unit']); ?> needed</p>
                        </div>
                    </div>
                    <hr>
                    <p><strong>Preferred Location:</strong> <?php echo htmlspecialchars($m['preferred_location']); ?></p>
                    <p><strong>Status:</strong>
                        <span class="badge bg-<?php
                            echo $m['status'] === 'accepted' ? 'success' : ($m['status'] === 'proposed' ? 'warning' : 'danger');
                        ?>"><?php echo ucfirst($m['status']); ?></span>
                    </p>
                </div>
                <div class="modal-footer">
                    <?php if ($m['status'] !== 'rejected'): ?>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Cancel this match? Both parties will be notified and the listing/demand reopened.');">
                            <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-x-circle"></i> Cancel Match
                            </button>
                        </form>
                    <?php endif; ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>