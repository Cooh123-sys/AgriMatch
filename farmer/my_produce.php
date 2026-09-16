<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Guard: only logged-in farmers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Get this farmer's farmer_id
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

// ---------- HANDLE STATUS UPDATE / DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['listing_id'], $_POST['action'])) {
    $listingId = (int) $_POST['listing_id'];
    $action    = $_POST['action'];

    // Always verify the listing belongs to this farmer before touching it
    $stmt = $conn->prepare("SELECT listing_id, photo FROM produce_listings WHERE listing_id = ? AND farmer_id = ?");
    $stmt->bind_param('ii', $listingId, $farmerId);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($listing) {
        if ($action === 'mark_sold') {
            $stmt = $conn->prepare("UPDATE produce_listings SET status = 'sold' WHERE listing_id = ?");
            $stmt->bind_param('i', $listingId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Listing marked as sold.'];

        } elseif ($action === 'withdraw') {
            $stmt = $conn->prepare("UPDATE produce_listings SET status = 'withdrawn' WHERE listing_id = ?");
            $stmt->bind_param('i', $listingId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Listing withdrawn from the marketplace.'];

        } elseif ($action === 'relist') {
            $stmt = $conn->prepare("UPDATE produce_listings SET status = 'available' WHERE listing_id = ?");
            $stmt->bind_param('i', $listingId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Listing is available again.'];

        } elseif ($action === 'delete') {
            // Remove the photo file from disk if one exists
            if (!empty($listing['photo'])) {
                $filePath = __DIR__ . '/../' . $listing['photo'];
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }
            $stmt = $conn->prepare("DELETE FROM produce_listings WHERE listing_id = ?");
            $stmt->bind_param('i', $listingId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Listing deleted permanently.'];
        }
    }

    header('Location: /AgriMatch/farmer/my_produce.php');
    exit;
}

// ---------- FETCH ALL LISTINGS FOR THIS FARMER ----------
$stmt = $conn->prepare("
    SELECT listing_id, crop_type, variety, quantity, unit, price_per_unit, quality_grade,
           harvest_date, available_from, available_until, location, description, photo,
           status, date_posted
    FROM produce_listings
    WHERE farmer_id = ?
    ORDER BY 
        CASE status WHEN 'available' THEN 0 WHEN 'matched' THEN 1 WHEN 'sold' THEN 2 ELSE 3 END,
        date_posted DESC
");
$stmt->bind_param('i', $farmerId);
$stmt->execute();
$result = $stmt->get_result();

$listings = [];
while ($row = $result->fetch_assoc()) {
    $listings[] = $row;
}
$stmt->close();

$unitLabels = [
    'kg' => 'kg',
    'tonnes' => 'tonnes',
    'bags_50kg' => '50kg bags',
    'bags_90kg' => '90kg bags',
    'crates' => 'crates'
];

$gradeLabels = [
    'grade_a' => 'Grade A',
    'grade_b' => 'Grade B',
    'grade_c' => 'Grade C',
    'ungraded' => 'Ungraded'
];

$pageTitle = 'My Produce';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-basket-fill"></i> My Produce Listings</h2>
    <a href="/AgriMatch/farmer/add_produce.php" class="btn btn-success">
        <i class="bi bi-plus-circle"></i> Post New Produce
    </a>
</div>

<?php if (empty($listings)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-basket text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-3">You haven't posted any produce yet.</p>
            <a href="/AgriMatch/farmer/add_produce.php" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Post Your First Produce
            </a>
        </div>
    </div>
<?php else: ?>

    <div class="row g-4">
        <?php foreach ($listings as $l): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm h-100">
                    <?php if ($l['photo']): ?>
                        <img src="/AgriMatch/<?php echo htmlspecialchars($l['photo']); ?>"
                             class="card-img-top" style="height: 180px; object-fit: cover;"
                             alt="<?php echo htmlspecialchars($l['crop_type']); ?>">
                    <?php else: ?>
                        <div class="bg-light d-flex align-items-center justify-content-center" style="height: 180px;">
                            <i class="bi bi-image text-muted" style="font-size: 2.5rem;"></i>
                        </div>
                    <?php endif; ?>

                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0">
                                <?php echo htmlspecialchars($l['crop_type']); ?>
                                <?php if ($l['variety']): ?>
                                    <small class="text-muted">(<?php echo htmlspecialchars($l['variety']); ?>)</small>
                                <?php endif; ?>
                            </h5>
                            <span class="badge bg-<?php
                                echo match($l['status']) {
                                    'available' => 'success',
                                    'matched'   => 'info',
                                    'sold'      => 'secondary',
                                    'withdrawn' => 'danger',
                                    default     => 'secondary'
                                };
                            ?>">
                                <?php echo ucfirst($l['status']); ?>
                            </span>
                        </div>

                        <p class="mb-1">
                            <i class="bi bi-box-seam"></i>
                            <strong><?php echo number_format($l['quantity'], 2); ?></strong>
                            <?php echo htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?>
                        </p>

                        <p class="mb-1">
                            <i class="bi bi-award"></i> <?php echo htmlspecialchars($gradeLabels[$l['quality_grade']] ?? $l['quality_grade']); ?>
                        </p>

                        <?php if ($l['price_per_unit']): ?>
                            <p class="mb-1">
                                <i class="bi bi-cash"></i>
                                MWK <?php echo number_format($l['price_per_unit'], 2); ?> per <?php echo htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?>
                            </p>
                        <?php endif; ?>

                        <p class="mb-1"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($l['location']); ?></p>

                        <p class="mb-2 text-muted small">
                            <i class="bi bi-calendar-check"></i>
                            Available: <?php echo date('d M Y', strtotime($l['available_from'])); ?>
                            <?php if ($l['available_until']): ?>
                                &ndash; <?php echo date('d M Y', strtotime($l['available_until'])); ?>
                            <?php endif; ?>
                        </p>

                        <?php if ($l['description']): ?>
                            <p class="mb-3 small"><?php echo nl2br(htmlspecialchars($l['description'])); ?></p>
                        <?php endif; ?>

                        <div class="mt-auto d-flex flex-wrap gap-2">
                            <?php if ($l['status'] === 'available'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                                    <input type="hidden" name="action" value="mark_sold">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-check-circle"></i> Mark Sold
                                    </button>
                                </form>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Withdraw this listing from the marketplace?');">
                                    <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                                    <input type="hidden" name="action" value="withdraw">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-slash-circle"></i> Withdraw
                                    </button>
                                </form>
                            <?php elseif ($l['status'] === 'withdrawn'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                                    <input type="hidden" name="action" value="relist">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-arrow-counterclockwise"></i> Relist
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this listing permanently? This cannot be undone.');">
                                <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>