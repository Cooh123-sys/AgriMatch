<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/match_engine.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT buyer_id FROM buyer_details WHERE user_id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$buyerRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$buyerRow) {
    header('Location: /AgriMatch/dashboard.php');
    exit;
}
$buyerId = $buyerRow['buyer_id'];

// ---------- HANDLE "REQUEST MATCH" ACTION ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['listing_id'], $_POST['demand_id'])) {
    $listingId = (int) $_POST['listing_id'];
    $demandId  = (int) $_POST['demand_id'];

    // Confirm this demand belongs to the logged-in buyer
    $stmt = $conn->prepare("SELECT demand_id FROM demands WHERE demand_id = ? AND buyer_id = ?");
    $stmt->bind_param('ii', $demandId, $buyerId);
    $stmt->execute();
    $ownsDemand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($ownsDemand) {
        $stmt = $conn->prepare("
            INSERT INTO matches (listing_id, demand_id, status)
            VALUES (?, ?, 'proposed')
            ON DUPLICATE KEY UPDATE status = status
        ");
        $stmt->bind_param('ii', $listingId, $demandId);
        $stmt->execute();
        $stmt->close();

        $_SESSION['flash'] = [
            'type' => 'success',
            'msg'  => 'Match request sent to the farmer. You will be able to see their contact details once they accept.'
        ];
    }

    header('Location: /AgriMatch/buyer/matched_farmers.php');
    exit;
}

// ---------- FETCH THIS BUYER'S OPEN DEMANDS ----------
$stmt = $conn->prepare("
    SELECT demand_id, crop_type, min_quantity, unit, preferred_location, status
    FROM demands
    WHERE buyer_id = ? AND status IN ('open','matched')
    ORDER BY date_posted DESC
");
$stmt->bind_param('i', $buyerId);
$stmt->execute();
$result = $stmt->get_result();
$demands = [];
while ($row = $result->fetch_assoc()) {
    $demands[] = $row;
}
$stmt->close();

$unitLabels = [
    'kg' => 'kg', 'tonnes' => 'tonnes', 'bags_50kg' => '50kg bags',
    'bags_90kg' => '90kg bags', 'crates' => 'crates'
];
$gradeLabels = [
    'grade_a' => 'Grade A', 'grade_b' => 'Grade B', 'grade_c' => 'Grade C', 'ungraded' => 'Ungraded'
];

$pageTitle = 'Matched Farmers';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-people-fill"></i> Matched Farmers</h2>

<?php if (empty($demands)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-clipboard-x text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-3">You don't have any open demands yet.</p>
            <a href="/AgriMatch/buyer/post_demand.php" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Post a Demand
            </a>
        </div>
    </div>
<?php else: ?>

    <?php foreach ($demands as $d): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-clipboard-check"></i>
                    <strong><?php echo htmlspecialchars($d['crop_type']); ?></strong>
                    &mdash; Min. <?php echo number_format($d['min_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$d['unit']] ?? $d['unit']); ?>,
                    near <?php echo htmlspecialchars($d['preferred_location']); ?>
                </span>
                <span class="badge bg-light text-dark"><?php echo ucfirst($d['status']); ?></span>
            </div>
            <div class="card-body">
                <?php
                $matchedListings = getMatchesForDemand($conn, $d['demand_id']);
                ?>

                <?php if (empty($matchedListings)): ?>
                    <p class="text-muted mb-0">No matching farmers found yet for this demand. Check back later as new produce is posted.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($matchedListings as $l): ?>
                            <?php $matchStatus = getMatchStatus($conn, $l['listing_id'], $d['demand_id']); ?>
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($l['crop_type']); ?>
                                            <?php if ($l['variety']): ?> <small class="text-muted">(<?php echo htmlspecialchars($l['variety']); ?>)</small><?php endif; ?>
                                        </h6>
                                        <?php if ($matchStatus): ?>
                                            <span class="badge bg-<?php
                                                echo $matchStatus === 'accepted' ? 'success' : ($matchStatus === 'proposed' ? 'warning' : 'danger');
                                            ?>"><?php echo ucfirst($matchStatus); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-1 small">
                                        <i class="bi bi-box-seam"></i> <?php echo number_format($l['quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?>
                                        &nbsp;|&nbsp;
                                        <i class="bi bi-award"></i> <?php echo htmlspecialchars($gradeLabels[$l['quality_grade']] ?? $l['quality_grade']); ?>
                                    </p>
                                    <p class="mb-1 small"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($l['location']); ?></p>
                                    <?php if ($l['price_per_unit']): ?>
                                        <p class="mb-2 small"><i class="bi bi-cash"></i> MWK <?php echo number_format($l['price_per_unit'], 2); ?> per <?php echo htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?></p>
                                    <?php endif; ?>

                                    <?php if ($matchStatus === 'accepted'): ?>
                                        <div class="alert alert-success py-2 px-3 mb-0 small">
                                            <i class="bi bi-check-circle"></i> <strong>Farmer:</strong> <?php echo htmlspecialchars($l['farmer_name']); ?><br>
                                            <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($l['farmer_phone']); ?><br>
                                            <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($l['farmer_email']); ?>
                                        </div>
                                    <?php elseif ($matchStatus === 'proposed'): ?>
                                        <p class="text-muted small mb-0"><i class="bi bi-hourglass-split"></i> Waiting for farmer response...</p>
                                    <?php elseif ($matchStatus === 'rejected'): ?>
                                        <p class="text-muted small mb-0"><i class="bi bi-x-circle"></i> Farmer declined this request.</p>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                                            <input type="hidden" name="demand_id" value="<?php echo $d['demand_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-send"></i> Request Match
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>