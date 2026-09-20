<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

// ---------- USER STATISTICS ----------
$farmerStats = $conn->query("
    SELECT status, COUNT(*) c FROM users WHERE role = 'farmer' GROUP BY status
")->fetch_all(MYSQLI_ASSOC);
$buyerStats = $conn->query("
    SELECT status, COUNT(*) c FROM users WHERE role = 'buyer' GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

function extractCount($stats, $status) {
    foreach ($stats as $row) {
        if ($row['status'] === $status) return (int) $row['c'];
    }
    return 0;
}

$totalFarmers    = array_sum(array_column($farmerStats, 'c'));
$verifiedFarmers = extractCount($farmerStats, 'verified');
$pendingFarmers  = extractCount($farmerStats, 'pending');
$rejectedFarmers = extractCount($farmerStats, 'rejected');

$totalBuyers    = array_sum(array_column($buyerStats, 'c'));
$verifiedBuyers = extractCount($buyerStats, 'verified');
$pendingBuyers  = extractCount($buyerStats, 'pending');
$rejectedBuyers = extractCount($buyerStats, 'rejected');

// ---------- PRODUCE STATISTICS ----------
$listingStats = $conn->query("
    SELECT status, COUNT(*) c FROM produce_listings GROUP BY status
")->fetch_all(MYSQLI_ASSOC);
$totalListings = array_sum(array_column($listingStats, 'c'));

function extractListingCount($stats, $status) {
    foreach ($stats as $row) {
        if ($row['status'] === $status) return (int) $row['c'];
    }
    return 0;
}
$availableListings = extractListingCount($listingStats, 'available');
$matchedListings   = extractListingCount($listingStats, 'matched');
$soldListings      = extractListingCount($listingStats, 'sold');
$withdrawnListings = extractListingCount($listingStats, 'withdrawn');

// Top 5 crops by total quantity posted
$topCropsSupply = $conn->query("
    SELECT crop_type, SUM(quantity) total_qty, COUNT(*) listing_count
    FROM produce_listings
    GROUP BY crop_type
    ORDER BY total_qty DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$maxSupplyQty = $topCropsSupply ? max(array_column($topCropsSupply, 'total_qty')) : 1;

// ---------- DEMAND STATISTICS ----------
$demandStats = $conn->query("
    SELECT status, COUNT(*) c FROM demands GROUP BY status
")->fetch_all(MYSQLI_ASSOC);
$totalDemands = array_sum(array_column($demandStats, 'c'));

function extractDemandCount($stats, $status) {
    foreach ($stats as $row) {
        if ($row['status'] === $status) return (int) $row['c'];
    }
    return 0;
}
$openDemands      = extractDemandCount($demandStats, 'open');
$matchedDemands   = extractDemandCount($demandStats, 'matched');
$fulfilledDemands = extractDemandCount($demandStats, 'fulfilled');
$closedDemands    = extractDemandCount($demandStats, 'closed');

// Top 5 crops by demand quantity
$topCropsDemand = $conn->query("
    SELECT crop_type, SUM(min_quantity) total_qty, COUNT(*) demand_count
    FROM demands
    GROUP BY crop_type
    ORDER BY total_qty DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);
$maxDemandQty = $topCropsDemand ? max(array_column($topCropsDemand, 'total_qty')) : 1;

// ---------- MATCHING PERFORMANCE ----------
$matchStats = $conn->query("
    SELECT status, COUNT(*) c FROM matches GROUP BY status
")->fetch_all(MYSQLI_ASSOC);
$totalMatchAttempts = array_sum(array_column($matchStats, 'c'));

function extractMatchCount($stats, $status) {
    foreach ($stats as $row) {
        if ($row['status'] === $status) return (int) $row['c'];
    }
    return 0;
}
$proposedM = extractMatchCount($matchStats, 'proposed');
$acceptedM = extractMatchCount($matchStats, 'accepted');
$rejectedM = extractMatchCount($matchStats, 'rejected');

$decidedMatches = $acceptedM + $rejectedM;
$acceptanceRate = $decidedMatches > 0 ? round(($acceptedM / $decidedMatches) * 100, 1) : 0;

// ---------- RECENT ACTIVITY ----------
$recentMatches = $conn->query("
    SELECT m.match_date, m.status, p.crop_type,
           uf.full_name AS farmer_name, ub.full_name AS buyer_name
    FROM matches m
    JOIN produce_listings p ON p.listing_id = m.listing_id
    JOIN farmer_details f ON f.farmer_id = p.farmer_id
    JOIN users uf ON uf.user_id = f.user_id
    JOIN demands d ON d.demand_id = m.demand_id
    JOIN buyer_details b ON b.buyer_id = d.buyer_id
    JOIN users ub ON ub.user_id = b.user_id
    ORDER BY m.match_date DESC
    LIMIT 5
");

$pageTitle = 'Reports';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-bar-chart-fill"></i> System Reports</h2>

<!-- USER STATS -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white">
        <i class="bi bi-people-fill"></i> User Statistics
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-md-6">
                <h6>Farmers (<?php echo $totalFarmers; ?> total)</h6>
                <p class="mb-1">Verified: <strong><?php echo $verifiedFarmers; ?></strong></p>
                <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $totalFarmers ? ($verifiedFarmers / $totalFarmers * 100) : 0; ?>%"></div>
                </div>
                <p class="mb-1">Pending: <strong><?php echo $pendingFarmers; ?></strong></p>
                <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-warning" style="width: <?php echo $totalFarmers ? ($pendingFarmers / $totalFarmers * 100) : 0; ?>%"></div>
                </div>
                <p class="mb-1">Rejected: <strong><?php echo $rejectedFarmers; ?></strong></p>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-danger" style="width: <?php echo $totalFarmers ? ($rejectedFarmers / $totalFarmers * 100) : 0; ?>%"></div>
                </div>
            </div>
            <div class="col-md-6">
                <h6>Buyers (<?php echo $totalBuyers; ?> total)</h6>
                <p class="mb-1">Verified: <strong><?php echo $verifiedBuyers; ?></strong></p>
                <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $totalBuyers ? ($verifiedBuyers / $totalBuyers * 100) : 0; ?>%"></div>
                </div>
                <p class="mb-1">Pending: <strong><?php echo $pendingBuyers; ?></strong></p>
                <div class="progress mb-2" style="height: 8px;">
                    <div class="progress-bar bg-warning" style="width: <?php echo $totalBuyers ? ($pendingBuyers / $totalBuyers * 100) : 0; ?>%"></div>
                </div>
                <p class="mb-1">Rejected: <strong><?php echo $rejectedBuyers; ?></strong></p>
                <div class="progress" style="height: 8px;">
                    <div class="progress-bar bg-danger" style="width: <?php echo $totalBuyers ? ($rejectedBuyers / $totalBuyers * 100) : 0; ?>%"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PRODUCE & DEMAND STATS -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-success text-white">
                <i class="bi bi-basket-fill"></i> Produce Listings (<?php echo $totalListings; ?> total)
            </div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tr><td>Available</td><td class="text-end fw-bold"><?php echo $availableListings; ?></td></tr>
                    <tr><td>Matched</td><td class="text-end fw-bold"><?php echo $matchedListings; ?></td></tr>
                    <tr><td>Sold</td><td class="text-end fw-bold"><?php echo $soldListings; ?></td></tr>
                    <tr><td>Withdrawn</td><td class="text-end fw-bold"><?php echo $withdrawnListings; ?></td></tr>
                </table>
                <h6 class="mt-3">Top Crops by Supply</h6>
                <?php if (empty($topCropsSupply)): ?>
                    <p class="text-muted small">No produce data yet.</p>
                <?php else: ?>
                    <?php foreach ($topCropsSupply as $c): ?>
                        <p class="mb-1 small"><?php echo htmlspecialchars($c['crop_type']); ?> &mdash; <?php echo number_format($c['total_qty'], 0); ?> units (<?php echo $c['listing_count']; ?> listings)</p>
                        <div class="progress mb-2" style="height: 6px;">
                            <div class="progress-bar bg-success" style="width: <?php echo ($c['total_qty'] / $maxSupplyQty * 100); ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-success text-white">
                <i class="bi bi-clipboard-check-fill"></i> Demands (<?php echo $totalDemands; ?> total)
            </div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tr><td>Open</td><td class="text-end fw-bold"><?php echo $openDemands; ?></td></tr>
                    <tr><td>Matched</td><td class="text-end fw-bold"><?php echo $matchedDemands; ?></td></tr>
                    <tr><td>Fulfilled</td><td class="text-end fw-bold"><?php echo $fulfilledDemands; ?></td></tr>
                    <tr><td>Closed</td><td class="text-end fw-bold"><?php echo $closedDemands; ?></td></tr>
                </table>
                <h6 class="mt-3">Top Crops by Demand</h6>
                <?php if (empty($topCropsDemand)): ?>
                    <p class="text-muted small">No demand data yet.</p>
                <?php else: ?>
                    <?php foreach ($topCropsDemand as $c): ?>
                        <p class="mb-1 small"><?php echo htmlspecialchars($c['crop_type']); ?> &mdash; <?php echo number_format($c['total_qty'], 0); ?> units needed (<?php echo $c['demand_count']; ?> demands)</p>
                        <div class="progress mb-2" style="height: 6px;">
                            <div class="progress-bar bg-info" style="width: <?php echo ($c['total_qty'] / $maxDemandQty * 100); ?>%"></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- MATCHING PERFORMANCE -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white">
        <i class="bi bi-link-45deg"></i> Matching Performance
    </div>
    <div class="card-body">
        <div class="row g-4 text-center mb-3">
            <div class="col-md-3">
                <h6>Total Attempts</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $totalMatchAttempts; ?></p>
            </div>
            <div class="col-md-3">
                <h6>Proposed</h6>
                <p class="fs-3 fw-bold mb-0 text-warning"><?php echo $proposedM; ?></p>
            </div>
            <div class="col-md-3">
                <h6>Accepted</h6>
                <p class="fs-3 fw-bold mb-0 text-success"><?php echo $acceptedM; ?></p>
            </div>
            <div class="col-md-3">
                <h6>Rejected</h6>
                <p class="fs-3 fw-bold mb-0 text-danger"><?php echo $rejectedM; ?></p>
            </div>
        </div>
        <hr>
        <p class="mb-1">
            <strong>Acceptance Rate</strong> (of decided matches):
            <span class="fs-5 fw-bold text-success"><?php echo $acceptanceRate; ?>%</span>
        </p>
        <div class="progress" style="height: 10px;">
            <div class="progress-bar bg-success" style="width: <?php echo $acceptanceRate; ?>%"></div>
        </div>
        <small class="text-muted">Measures how often a proposed match is accepted rather than declined — a key indicator of matching algorithm relevance (Section 3.8, System Evaluation Plan).</small>
    </div>
</div>

<!-- RECENT MATCH ACTIVITY -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-success text-white">
        <i class="bi bi-clock-history"></i> Recent Match Activity
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Crop</th>
                    <th>Farmer</th>
                    <th>Buyer</th>
                    <th>Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($recentMatches->num_rows === 0): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">No match activity yet.</td></tr>
                <?php else: ?>
                    <?php while ($r = $recentMatches->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['crop_type']); ?></td>
                            <td><?php echo htmlspecialchars($r['farmer_name']); ?></td>
                            <td><?php echo htmlspecialchars($r['buyer_name']); ?></td>
                            <td>
                                <span class="badge bg-<?php
                                    echo $r['status'] === 'accepted' ? 'success' : ($r['status'] === 'proposed' ? 'warning' : 'danger');
                                ?>"><?php echo ucfirst($r['status']); ?></span>
                            </td>
                            <td><?php echo date('d M Y, H:i', strtotime($r['match_date'])); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>