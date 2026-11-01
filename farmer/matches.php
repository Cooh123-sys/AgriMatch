<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../matching/match_engine.php';
require_once __DIR__ . '/../includes/mailer.php';

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

// ---------- HANDLE ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction  = $_POST['action'] ?? '';
    $redirectTab = 'find';

    if ($postAction === 'request') {
        // Farmer requests a buyer's demand
        $res = createMatchRequest(
            $conn,
            (int) ($_POST['listing_id'] ?? 0),
            (int) ($_POST['demand_id'] ?? 0),
            'farmer',
            $farmerId
        );
        if ($res['ok']) {
            notifyMatchRequested($res['listing'], $res['demand'], 'farmer');
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Match request sent to the buyer. Contact details are shared once they accept.'];
        } else {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => $res['error']];
        }

    } elseif (in_array($postAction, ['accept', 'reject'], true)) {
        // Farmer answers a request that a BUYER started
        $res = respondToMatch($conn, (int) ($_POST['match_id'] ?? 0), $postAction, 'farmer', $farmerId);
        if ($res['ok']) {
            notifyMatchResponse($res);
            if ($postAction === 'accept') {
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "Match accepted! The buyer's contact details are now visible."];
                $redirectTab = 'accepted';
            } else {
                $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Match request declined.'];
                $redirectTab = 'requests';
            }
        } else {
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => $res['error']];
            $redirectTab = 'requests';
        }
    }

    header('Location: /AgriMatch/farmer/matches.php?tab=' . $redirectTab);
    exit;
}

// ---------- LOAD DATA ----------
$tab = $_GET['tab'] ?? 'find';
if (!in_array($tab, ['find', 'requests', 'accepted'], true)) $tab = 'find';

// This farmer's available listings, each with the demands it could serve
$stmt = $conn->prepare("SELECT * FROM produce_listings WHERE farmer_id = ? AND status = 'available' ORDER BY date_posted DESC");
$stmt->bind_param('i', $farmerId);
$stmt->execute();
$res = $stmt->get_result();
$myListings = [];
$candidateCount = 0;
while ($row = $res->fetch_assoc()) {
    $row['candidates'] = getListingCandidates($conn, $row);
    $candidateCount += count($row['candidates']);
    $myListings[] = $row;
}
$stmt->close();

$incoming = getIncomingRequestsForFarmer($conn, $farmerId);
$accepted = getAcceptedMatchesForFarmer($conn, $farmerId);

$unitLabels = [
    'kg' => 'kg', 'tonnes' => 'tonnes', 'bags_50kg' => '50kg bags',
    'bags_90kg' => '90kg bags', 'crates' => 'crates'
];
$qualityLabels = [
    'any' => 'Any grade', 'grade_a' => 'Grade A only',
    'grade_b' => 'Grade B or better', 'grade_c' => 'Grade C or better'
];
$frequencyLabels = [
    'one_time' => 'One-time', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'ongoing' => 'Ongoing'
];
$orgLabels = [
    'school' => 'School', 'hotel' => 'Hotel', 'manufacturing_company' => 'Manufacturing Company',
    'hospital' => 'Hospital', 'retailer' => 'Retailer', 'wholesaler' => 'Wholesaler',
    'exporter' => 'Exporter', 'other' => 'Other'
];

$pageTitle = 'My Matches';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .nav-pills .nav-link.active { background-color: #198754; }
    .nav-pills .nav-link { color: #198754; }
    .nav-pills .nav-link.active .badge { background-color: #fff !important; color: #198754 !important; }
</style>

<h2 class="mb-4"><i class="bi bi-link-45deg"></i> My Matches</h2>

<ul class="nav nav-pills mb-4">
    <li class="nav-item">
        <button class="nav-link <?php echo $tab === 'find' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tabFind" type="button">
            <i class="bi bi-search"></i> Find Buyers <span class="badge bg-success"><?php echo $candidateCount; ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $tab === 'requests' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tabRequests" type="button">
            <i class="bi bi-inbox"></i> Buyer Requests <span class="badge bg-warning text-dark"><?php echo count($incoming); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link <?php echo $tab === 'accepted' ? 'active' : ''; ?>" data-bs-toggle="pill" data-bs-target="#tabAccepted" type="button">
            <i class="bi bi-check-circle"></i> Accepted <span class="badge bg-success"><?php echo count($accepted); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ==================== TAB 1: FIND BUYERS ==================== -->
    <div class="tab-pane fade <?php echo $tab === 'find' ? 'show active' : ''; ?>" id="tabFind">

        <?php if (empty($myListings)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-basket text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3 mb-3">You have no available produce listed. Post produce to see buyers who need it.</p>
                    <a href="/AgriMatch/farmer/add_produce.php" class="btn btn-success"><i class="bi bi-plus-circle"></i> Post Produce</a>
                </div>
            </div>
        <?php else: ?>

            <?php foreach ($myListings as $L): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-success text-white">
                        <i class="bi bi-basket-fill"></i>
                        <strong><?php echo htmlspecialchars($L['crop_type']); ?></strong>
                        <?php if ($L['variety']): ?>(<?php echo htmlspecialchars($L['variety']); ?>)<?php endif; ?>
                        &mdash; your listing: <?php echo number_format($L['quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$L['unit']] ?? $L['unit']); ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($L['candidates'])): ?>
                            <p class="text-muted mb-0">No open buyer demands for this crop right now. New demands will appear here automatically.</p>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($L['candidates'] as $c): ?>
                                    <?php
                                    $ms = $c['match_status'];
                                    $by = $c['requested_by'];
                                    $dUnit = $unitLabels[$c['unit']] ?? $c['unit'];
                                    ?>
                                    <div class="col-md-6">
                                        <div class="border rounded p-3 h-100">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($c['buyer_name']); ?></h6>
                                                <?php if ($ms === 'proposed'): ?>
                                                    <span class="badge bg-warning text-dark"><?php echo $by === 'buyer' ? 'Buyer requested' : 'Request sent'; ?></span>
                                                <?php elseif ($ms === 'rejected'): ?>
                                                    <span class="badge bg-danger">Declined</span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="mb-1 small text-muted"><i class="bi bi-building"></i> <?php echo htmlspecialchars($orgLabels[$c['buyer_org_type']] ?? 'Organisation'); ?></p>

                                            <p class="mb-1 small">
                                                <i class="bi bi-clipboard-check"></i> Needs
                                                <strong><?php echo number_format($c['required_qty'], 2) . ' ' . htmlspecialchars($dUnit); ?></strong>
                                                of <?php echo htmlspecialchars($c['crop_type']); ?>
                                            </p>

                                            <div class="progress mb-1" style="height: 8px;">
                                                <div class="progress-bar bg-success" style="width: <?php echo $c['accepted_pct']; ?>%"></div>
                                            </div>
                                            <p class="mb-1 small text-muted">
                                                Confirmed <?php echo number_format($c['accepted_qty'], 2); ?> &middot;
                                                still needed <strong><?php echo number_format($c['remaining_qty'], 2) . ' ' . htmlspecialchars($dUnit); ?></strong>
                                            </p>
                                            <p class="mb-2 small">
                                                <i class="bi bi-diagram-3"></i> Your listing can supply
                                                <strong><?php echo number_format($c['covers_pct'], 0); ?>%</strong> of the remaining need.
                                            </p>

                                            <p class="mb-1 small"><i class="bi bi-award"></i> <?php echo htmlspecialchars($qualityLabels[$c['preferred_quality']] ?? $c['preferred_quality']); ?></p>
                                            <?php if ($c['max_price_per_unit']): ?>
                                                <p class="mb-1 small"><i class="bi bi-cash"></i> Up to MWK <?php echo number_format($c['max_price_per_unit'], 2); ?> per <?php echo htmlspecialchars($dUnit); ?></p>
                                            <?php endif; ?>
                                            <p class="mb-1 small"><i class="bi bi-geo-alt"></i> Delivery: <?php echo htmlspecialchars($c['preferred_location']); ?></p>
                                            <p class="mb-1 small"><i class="bi bi-arrow-repeat"></i> <?php echo htmlspecialchars($frequencyLabels[$c['frequency']] ?? $c['frequency']); ?>
                                                <?php if ($c['needed_by']): ?> &middot; needed by <?php echo date('d M Y', strtotime($c['needed_by'])); ?><?php endif; ?>
                                            </p>
                                            <?php if ($c['description']): ?>
                                                <p class="mb-2 small"><?php echo nl2br(htmlspecialchars($c['description'])); ?></p>
                                            <?php endif; ?>

                                            <p class="small text-muted mb-2"><i class="bi bi-lock"></i> Contact details are shared once the match is accepted.</p>

                                            <?php if ($ms === null): ?>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="request">
                                                    <input type="hidden" name="listing_id" value="<?php echo $L['listing_id']; ?>">
                                                    <input type="hidden" name="demand_id" value="<?php echo $c['demand_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-send"></i> Request Match</button>
                                                </form>
                                            <?php elseif ($ms === 'proposed' && $by === 'farmer'): ?>
                                                <p class="text-muted small mb-0"><i class="bi bi-hourglass-split"></i> Waiting for the buyer to respond...</p>
                                            <?php elseif ($ms === 'proposed' && $by === 'buyer'): ?>
                                                <p class="small mb-0"><i class="bi bi-inbox"></i> This buyer already requested you &mdash; respond in the <strong>Buyer Requests</strong> tab.</p>
                                            <?php else: ?>
                                                <p class="text-muted small mb-0"><i class="bi bi-x-circle"></i> This request was declined.</p>
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
    </div>

    <!-- ==================== TAB 2: BUYER REQUESTS ==================== -->
    <div class="tab-pane fade <?php echo $tab === 'requests' ? 'show active' : ''; ?>" id="tabRequests">

        <?php if (empty($incoming)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-inboxes text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3 mb-0">No pending buyer requests right now.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($incoming as $m): ?>
                    <?php $dUnit = $unitLabels[$m['demand_unit']] ?? $m['demand_unit']; ?>
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="card-title mb-0"><?php echo htmlspecialchars($m['buyer_name']); ?></h5>
                                    <span class="badge bg-warning text-dark">Awaiting you</span>
                                </div>
                                <p class="mb-1 small text-muted"><i class="bi bi-building"></i> <?php echo htmlspecialchars($orgLabels[$m['buyer_org_type']] ?? 'Organisation'); ?></p>
                                <p class="mb-1"><i class="bi bi-basket"></i> Wants your <strong><?php echo htmlspecialchars($m['crop_type']); ?></strong>
                                    (<?php echo number_format($m['listing_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['listing_unit']] ?? $m['listing_unit']); ?>)</p>
                                <p class="mb-1 small"><i class="bi bi-clipboard-check"></i> Total needed: <?php echo number_format($m['min_quantity'], 2) . ' ' . htmlspecialchars($dUnit); ?></p>
                                <p class="mb-1 small"><i class="bi bi-award"></i> <?php echo htmlspecialchars($qualityLabels[$m['preferred_quality']] ?? $m['preferred_quality']); ?></p>
                                <?php if ($m['max_price_per_unit']): ?>
                                    <p class="mb-1 small"><i class="bi bi-cash"></i> Up to MWK <?php echo number_format($m['max_price_per_unit'], 2); ?> per <?php echo htmlspecialchars($dUnit); ?></p>
                                <?php endif; ?>
                                <p class="mb-1 small"><i class="bi bi-geo-alt"></i> Delivery: <?php echo htmlspecialchars($m['preferred_location']); ?></p>
                                <p class="mb-2 small"><i class="bi bi-arrow-repeat"></i> <?php echo htmlspecialchars($frequencyLabels[$m['frequency']] ?? $m['frequency']); ?>
                                    <?php if ($m['needed_by']): ?> &middot; needed by <?php echo date('d M Y', strtotime($m['needed_by'])); ?><?php endif; ?></p>
                                <?php if ($m['demand_description']): ?>
                                    <p class="mb-2 small"><?php echo nl2br(htmlspecialchars($m['demand_description'])); ?></p>
                                <?php endif; ?>

                                <p class="small text-muted mb-3"><i class="bi bi-lock"></i> Contact details are shared once you accept.</p>

                                <div class="d-flex gap-2">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="accept">
                                        <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-circle"></i> Accept</button>
                                    </form>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Decline this buyer\'s request?');">
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="match_id" value="<?php echo $m['match_id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Decline</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ==================== TAB 3: ACCEPTED (contact details visible) ==================== -->
    <div class="tab-pane fade <?php echo $tab === 'accepted' ? 'show active' : ''; ?>" id="tabAccepted">

        <?php if (empty($accepted)): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5">
                    <i class="bi bi-check2-circle text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3 mb-0">No accepted matches yet.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($accepted as $m): ?>
                    <?php $dUnit = $unitLabels[$m['demand_unit']] ?? $m['demand_unit']; ?>
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100 border-success">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 class="card-title mb-0"><?php echo htmlspecialchars($m['crop_type']); ?></h5>
                                    <span class="badge bg-success">Accepted</span>
                                </div>
                                <p class="mb-1 small"><i class="bi bi-basket"></i> Your produce: <?php echo number_format($m['listing_quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$m['listing_unit']] ?? $m['listing_unit']); ?></p>
                                <p class="mb-1 small"><i class="bi bi-clipboard-check"></i> Buyer's total need: <?php echo number_format($m['min_quantity'], 2) . ' ' . htmlspecialchars($dUnit); ?></p>
                                <p class="mb-2 small"><i class="bi bi-geo-alt"></i> Delivery: <?php echo htmlspecialchars($m['preferred_location']); ?>
                                    <?php if ($m['needed_by']): ?> &middot; needed by <?php echo date('d M Y', strtotime($m['needed_by'])); ?><?php endif; ?></p>

                                <div class="alert alert-success py-2 px-3 mb-0 small">
                                    <i class="bi bi-person-check"></i> <strong>Buyer:</strong> <?php echo htmlspecialchars($m['buyer_name']); ?>
                                    (<?php echo htmlspecialchars($orgLabels[$m['buyer_org_type']] ?? 'Organisation'); ?>)<br>
                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($m['buyer_phone']); ?><br>
                                    <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($m['buyer_email']); ?><br>
                                    <i class="bi bi-pin-map"></i> <?php echo htmlspecialchars($m['buyer_address']); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>