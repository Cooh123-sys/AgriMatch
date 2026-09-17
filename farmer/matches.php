<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT farmer_id FROM farmer_details WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$farmerRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$farmerRow) {
    header('Location: /AgriMatch/dashboard.php');
    exit;
}
$farmerId = $farmerRow['farmer_id'];

// ---------- HANDLE ACCEPT / REJECT ACTION ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['match_id'], $_POST['action'])) {
    $matchId = (int) $_POST['match_id'];
    $action  = $_POST['action'];

    // Confirm this match belongs to one of this farmer's listings
    $stmt = $conn->prepare("
        SELECT m.match_id, m.listing_id, m.demand_id
        FROM matches m
        JOIN produce_listings p ON p.listing_id = m.listing_id
        WHERE m.match_id = ? AND p.farmer_id = ?
    ");
    $stmt->bind_param('ii', $matchId, $farmerId);
    $stmt->execute();
    $match = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($match) {
        if ($action === 'accept') {
            $stmt = $conn->prepare("UPDATE matches SET status = 'accepted' WHERE match_id = ?");
            $stmt->bind_param('i', $matchId);
            $stmt->execute();
            $stmt->close();

            // Mark the listing and demand as matched so they stop appearing as "open"/"available"
            $stmt = $conn->prepare("UPDATE produce_listings SET status = 'matched' WHERE listing_id = ?");
            $stmt->bind_param('i', $match['listing_id']);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE demands SET status = 'matched' WHERE demand_id = ?");
            $stmt->bind_param('i', $match['demand_id']);
            $stmt->execute();
            $stmt->close();

            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Match accepted! The buyer can now see your contact details.'];

        } elseif ($action === 'reject') {
            $stmt = $conn->prepare("UPDATE matches SET status = 'rejected' WHERE match_id = ?");
            $stmt->bind_param('i', $matchId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Match request declined.'];
        }
    }

    header('Location: /AgriMatch/farmer/matches.php');
    exit;
}

// ---------- FETCH ALL MATCH PROPOSALS FOR THIS FARMER'S LISTINGS ----------
$stmt = $conn->prepare("
    SELECT m.match_id, m.status, m.match_date,
           p.crop_type, p.quantity AS listing_quantity, p.unit,
           d.min_quantity, d.preferred_location,
           u.full_name AS buyer_name, u.phone AS buyer_phone, u.email AS buyer_email
    FROM matches m
    JOIN produce_listings p ON p.listing_id = m.listing_id
    JOIN demands d ON d.demand_id = m.demand_id
    JOIN buyer_details b ON b.buyer_id = d.buyer_id
    JOIN users u ON u.user_id = b.user_id
    WHERE p.farmer_id = ?
    ORDER BY 
        CASE m.status WHEN 'proposed' THEN 0 WHEN 'accepted' THEN 1 ELSE 2 END,
        m.match_date DESC
");
$stmt->bind_param('i', $farmerId);
$stmt->execute();
$result = $stmt->get_result();
$matches = [];
while ($row = $result->fetch_assoc()) {
    $matches[] = $row;
}
$stmt->close();

$unitLabels = [
    'kg' => 'kg', 'tonnes' => 'tonnes', 'bags_50kg' => '50kg bags',
    'bags_90kg' => '90kg bags', 'crates' => 'crates'
];

$pageTitle = 'My Matches';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-link-45deg"></i> My Matches</h2>

<?php if (empty($matches)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-inboxes text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-0">No match requests yet. Buyers will appear here once they request to buy your produce.</p>
        </div>
    </div>
<?php else: ?>

    <div class="row g-4">
        <?php foreach ($matches as $m): ?>
            <div class="col-md-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($m['crop_type']); ?></h5>
                            <span class="badge bg-<?php
                                echo $m['status'] === 'accepted' ? 'success' : ($m['status'] === 'proposed' ? 'warning' : 'danger');
                            ?>"><?php echo ucfirst($m['status']); ?></span>
                        </div>

                        <p class="mb-1"><i class="bi bi-basket"></i> Your listing: <?php echo number_format($m['listing_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['unit']] ?? $m['unit']); ?></p>
                        <p class="mb-1"><i class="bi bi-clipboard-check"></i> Buyer needs: <?php echo number_format($m['min_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['unit']] ?? $m['unit']); ?> min.</p>
                        <p class="mb-2"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($m['preferred_location']); ?></p>

                        <?php if ($m['status'] === 'accepted'): ?>
                            <div class="alert alert-success py-2 px-3 mb-0 small">
                                <i class="bi bi-check-circle"></i> <strong>Buyer:</strong> <?php echo htmlspecialchars($m['buyer_name']); ?><br>
                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($m['buyer_phone']); ?><br>
                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($m['buyer_email']); ?>
                            </div>
                        <?php elseif ($m['status'] === 'proposed'): ?>
                            <div class="d-flex gap-2">
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                    <input type="hidden" name="action" value="accept">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-check-circle"></i> Accept
                                    </button>
                                </form>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Reject this match request?');">
                                    <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-circle"></i> Reject
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-0"><i class="bi bi-x-circle"></i> You declined this request.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>