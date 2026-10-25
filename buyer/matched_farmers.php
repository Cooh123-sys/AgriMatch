<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/match_engine.php';
require_once __DIR__ . '/../includes/mailer.php';

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

// ---------- HANDLE "REQUEST MATCH" (single farmer or whole suggested bundle) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demand_id'])) {
    $demandId = (int) $_POST['demand_id'];

    $stmt = $conn->prepare("SELECT demand_id FROM demands WHERE demand_id = ? AND buyer_id = ?");
    $stmt->bind_param('ii', $demandId, $buyerId);
    $stmt->execute();
    $ownsDemand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($ownsDemand) {
        // Recompute server-side so only genuine candidates can be requested
        $coverage = getDemandCoverage($conn, $demandId);
        $toRequest = [];

        if (($_POST['action'] ?? '') === 'request_bundle') {
            foreach ($coverage['listings'] as $l) {
                if ($l['in_bundle'] && $l['match_status'] === null) {
                    $toRequest[] = $l;
                }
            }
        } elseif (isset($_POST['listing_id'])) {
            $listingId = (int) $_POST['listing_id'];
            foreach ($coverage['listings'] as $l) {
                if ((int) $l['listing_id'] === $listingId && $l['match_status'] === null) {
                    $toRequest[] = $l;
                    break;
                }
            }
        }

        $sent = 0;
        foreach ($toRequest as $l) {
            $stmt = $conn->prepare("
                INSERT INTO matches (listing_id, demand_id, status)
                VALUES (?, ?, 'proposed')
                ON DUPLICATE KEY UPDATE status = status
            ");
            $stmt->bind_param('ii', $l['listing_id'], $demandId);
            $stmt->execute();
            $stmt->close();

            sendMatchEmail($l['farmer_email'], $l['farmer_name'], 'farmer', 'requested', $l['crop_type']);
            $sent++;
        }

        $_SESSION['flash'] = $sent > 0
            ? ['type' => 'success', 'msg' => "Match request sent to {$sent} farmer(s). Contact details appear once they accept."]
            : ['type' => 'warning', 'msg' => 'No new requests were sent.'];
    }

    header('Location: /AgriMatch/buyer/matched_farmers.php');
    exit;
}

// ---------- FETCH THIS BUYER'S OPEN/MATCHED DEMANDS ----------
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
        <?php
        $cov    = getDemandCoverage($conn, $d['demand_id']);
        $uLabel = $unitLabels[$d['unit']] ?? $d['unit'];
        $accepted = getAcceptedMatchForDemand($conn, $d['demand_id']);
        $extraPct = max(0, $cov['potential_pct'] - $cov['accepted_pct']);
        $hasRequestableBundle = false;
        foreach ($cov['listings'] as $l) {
            if ($l['in_bundle'] && $l['match_status'] === null) { $hasRequestableBundle = true; break; }
        }
        ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-clipboard-check"></i>
                    <strong><?php echo htmlspecialchars($d['crop_type']); ?></strong>
                    &mdash; Needs <?php echo number_format($d['min_quantity'], 2) . ' ' . htmlspecialchars($uLabel); ?>,
                    near <?php echo htmlspecialchars($d['preferred_location']); ?>
                </span>
                <span class="badge bg-light text-dark"><?php echo ucfirst($d['status']); ?></span>
            </div>
            <div class="card-body">

                <!-- ===== COVERAGE SUMMARY ===== -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between small mb-1">
                        <span>
                            Confirmed: <strong><?php echo number_format($cov['accepted_qty'], 2); ?></strong> /
                            <?php echo number_format($cov['required'], 2) . ' ' . htmlspecialchars($uLabel); ?>
                        </span>
                        <span>
                            Available from matching farmers:
                            <strong><?php echo number_format($cov['pool_qty'], 2) . ' ' . htmlspecialchars($uLabel); ?></strong>
                        </span>
                    </div>
                    <div class="progress" style="height: 12px;">
                        <div class="progress-bar bg-success" style="width: <?php echo $cov['accepted_pct']; ?>%"></div>
                        <div class="progress-bar bg-info" style="width: <?php echo $extraPct; ?>%"></div>
                    </div>
                    <small class="text-muted">Green = confirmed by accepted farmers &middot; Blue = additional supply available in your area</small>

                    <?php if ($cov['fully_accepted']): ?>
                        <div class="alert alert-success py-2 px-3 mt-2 mb-0 small">
                            <i class="bi bi-check-circle-fill"></i> Your full requirement is covered by accepted farmers.
                        </div>
                    <?php elseif ($cov['fulfillable']): ?>
                        <div class="alert alert-info py-2 px-3 mt-2 mb-0 small">
                            <i class="bi bi-diagram-3"></i> Combined supply from <strong><?php echo $cov['bundle_count']; ?> farmer(s)</strong>
                            can cover your remaining need.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
                            <i class="bi bi-exclamation-triangle"></i> Combined supply currently falls short by
                            <strong><?php echo number_format($cov['shortfall'], 2) . ' ' . htmlspecialchars($uLabel); ?></strong>.
                            You can still request what is available; new produce is matched automatically as it is posted.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- ===== ACCEPTED FARMERS ===== -->
                <?php if (!empty($accepted)): ?>
                    <h6 class="text-success"><i class="bi bi-check-circle"></i> Accepted Farmers</h6>
                    <div class="row g-3 mb-4">
                        <?php foreach ($accepted as $am): ?>
                            <div class="col-md-6">
                                <div class="border border-success rounded p-3 h-100">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($am['crop_type']); ?>
                                            <?php if ($am['variety']): ?> <small class="text-muted">(<?php echo htmlspecialchars($am['variety']); ?>)</small><?php endif; ?>
                                        </h6>
                                        <span class="badge bg-success">Accepted</span>
                                    </div>
                                    <p class="mb-1 small">
                                        <i class="bi bi-box-seam"></i> <?php echo number_format($am['quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$am['unit']] ?? $am['unit']); ?>
                                        &nbsp;|&nbsp;
                                        <i class="bi bi-award"></i> <?php echo htmlspecialchars($gradeLabels[$am['quality_grade']] ?? $am['quality_grade']); ?>
                                    </p>
                                    <p class="mb-1 small"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($am['location']); ?></p>
                                    <?php if ($am['price_per_unit']): ?>
                                        <p class="mb-2 small"><i class="bi bi-cash"></i> MWK <?php echo number_format($am['price_per_unit'], 2); ?> per <?php echo htmlspecialchars($unitLabels[$am['unit']] ?? $am['unit']); ?></p>
                                    <?php endif; ?>
                                    <div class="alert alert-success py-2 px-3 mb-0 small">
                                        <i class="bi bi-person-check"></i> <strong>Farmer:</strong> <?php echo htmlspecialchars($am['farmer_name']); ?><br>
                                        <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($am['farmer_phone']); ?><br>
                                        <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($am['farmer_email']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ===== MATCHING FARMERS (candidates) ===== -->
                <?php if (!empty($cov['listings']) && !$cov['fully_accepted']): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0"><i class="bi bi-people"></i> Matching Farmers in Your Area</h6>
                        <?php if ($hasRequestableBundle): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="demand_id" value="<?php echo $d['demand_id']; ?>">
                                <input type="hidden" name="action" value="request_bundle">
                                <button type="submit" class="btn btn-sm btn-success">
                                    <i class="bi bi-send-check"></i> Request All Suggested Farmers
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <div class="row g-3">
                        <?php foreach ($cov['listings'] as $l): ?>
                            <?php $ms = $l['match_status']; ?>
                            <div class="col-md-6">
                                <div class="border rounded p-3 h-100">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-1"><?php echo htmlspecialchars($l['crop_type']); ?>
                                            <?php if ($l['variety']): ?> <small class="text-muted">(<?php echo htmlspecialchars($l['variety']); ?>)</small><?php endif; ?>
                                        </h6>
                                        <span>
                                            <?php if ($l['in_bundle']): ?>
                                                <span class="badge bg-info">Suggested</span>
                                            <?php endif; ?>
                                            <?php if ($ms): ?>
                                                <span class="badge bg-<?php echo $ms === 'proposed' ? 'warning' : 'danger'; ?>"><?php echo ucfirst($ms); ?></span>
                                            <?php endif; ?>
                                        </span>
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

                                    <?php if ($ms === 'proposed'): ?>
                                        <p class="text-muted small mb-0"><i class="bi bi-hourglass-split"></i> Waiting for farmer response...</p>
                                    <?php elseif ($ms === 'rejected'): ?>
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

                <?php elseif (empty($cov['listings']) && empty($accepted)): ?>
                    <p class="text-muted mb-0">No matching farmers found yet for this demand. Check back later as new produce is posted.</p>
                <?php endif; ?>

            </div>
        </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>