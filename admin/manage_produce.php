<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Guard: only admins allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

// ---------- HANDLE ADMIN ACTION (withdraw / delete a listing) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['listing_id'], $_POST['action'])) {
    $listingId = (int) $_POST['listing_id'];
    $action    = $_POST['action'];

    $stmt = $conn->prepare("SELECT photo FROM produce_listings WHERE listing_id = ?");
    $stmt->bind_param('i', $listingId);
    $stmt->execute();
    $listing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($listing) {
        if ($action === 'withdraw') {
            $stmt = $conn->prepare("UPDATE produce_listings SET status = 'withdrawn' WHERE listing_id = ?");
            $stmt->bind_param('i', $listingId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Listing withdrawn from the marketplace by admin.'];

        } elseif ($action === 'delete') {
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
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Listing permanently deleted.'];
        }
    }

    header('Location: /AgriMatch/admin/manage_produce.php');
    exit;
}

// ---------- OPTIONAL FILTERS ----------
$statusFilter = $_GET['status'] ?? '';
$cropFilter   = $_GET['crop'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($statusFilter !== '') {
    $where[] = "p.status = ?";
    $params[] = $statusFilter;
    $types   .= 's';
}
if ($cropFilter !== '') {
    $where[] = "p.crop_type = ?";
    $params[] = $cropFilter;
    $types   .= 's';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT p.listing_id, p.crop_type, p.variety, p.quantity, p.unit, p.price_per_unit,
           p.quality_grade, p.available_from, p.available_until, p.location, p.description,
           p.photo, p.status, p.date_posted,
           u.full_name AS farmer_name, u.phone AS farmer_phone, u.email AS farmer_email
    FROM produce_listings p
    JOIN farmer_details f ON f.farmer_id = p.farmer_id
    JOIN users u ON u.user_id = f.user_id
    $whereSql
    ORDER BY 
        CASE p.status WHEN 'available' THEN 0 WHEN 'matched' THEN 1 WHEN 'sold' THEN 2 ELSE 3 END,
        p.date_posted DESC
";

$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$listings = [];
while ($row = $result->fetch_assoc()) {
    $listings[] = $row;
}
$stmt->close();

// For the crop filter dropdown — get distinct crop types currently posted
$cropOptionsResult = $conn->query("SELECT DISTINCT crop_type FROM produce_listings ORDER BY crop_type");
$cropOptions = [];
while ($row = $cropOptionsResult->fetch_assoc()) {
    $cropOptions[] = $row['crop_type'];
}

// Summary counts for the top cards
$totalListings     = $conn->query("SELECT COUNT(*) c FROM produce_listings")->fetch_assoc()['c'];
$availableListings = $conn->query("SELECT COUNT(*) c FROM produce_listings WHERE status = 'available'")->fetch_assoc()['c'];
$soldListings       = $conn->query("SELECT COUNT(*) c FROM produce_listings WHERE status = 'sold'")->fetch_assoc()['c'];
$withdrawnListings  = $conn->query("SELECT COUNT(*) c FROM produce_listings WHERE status = 'withdrawn'")->fetch_assoc()['c'];

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

$pageTitle = 'Manage Produce';
require_once __DIR__ . '/../includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-basket-fill"></i> Manage Produce Listings</h2>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-collection-fill text-success fs-1"></i>
                <h6 class="mt-2">Total Listings</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $totalListings; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-check-circle-fill text-success fs-1"></i>
                <h6 class="mt-2">Available</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $availableListings; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-bag-check-fill text-secondary fs-1"></i>
                <h6 class="mt-2">Sold</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $soldListings; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <i class="bi bi-x-circle-fill text-danger fs-1"></i>
                <h6 class="mt-2">Withdrawn</h6>
                <p class="fs-3 fw-bold mb-0"><?php echo $withdrawnListings; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Filter by Status</label>
                <select class="form-select" name="status">
                    <option value="">All Statuses</option>
                    <?php foreach (['available','matched','sold','withdrawn'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>>
                            <?php echo ucfirst($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Filter by Crop</label>
                <select class="form-select" name="crop">
                    <option value="">All Crops</option>
                    <?php foreach ($cropOptions as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $cropFilter === $c ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-success"><i class="bi bi-funnel"></i> Apply Filters</button>
                <a href="/AgriMatch/admin/manage_produce.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Listings Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th>Crop</th>
                    <th>Farmer</th>
                    <th>Quantity</th>
                    <th>Grade</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Posted</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($listings)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No produce listings found.</td></tr>
                <?php else: ?>
                    <?php foreach ($listings as $l): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($l['crop_type']); ?>
                                <?php if ($l['variety']): ?>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($l['variety']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($l['farmer_name']); ?></td>
                            <td><?php echo number_format($l['quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?></td>
                            <td><?php echo htmlspecialchars($gradeLabels[$l['quality_grade']] ?? $l['quality_grade']); ?></td>
                            <td><?php echo htmlspecialchars($l['location']); ?></td>
                            <td>
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
                            </td>
                            <td><?php echo date('d M Y', strtotime($l['date_posted'])); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal" data-bs-target="#listingModal<?php echo $l['listing_id']; ?>">
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

<?php foreach ($listings as $l): ?>
    <!-- Listing Detail Modal -->
    <div class="modal fade" id="listingModal<?php echo $l['listing_id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">
                        <?php echo htmlspecialchars($l['crop_type']); ?>
                        <?php if ($l['variety']): ?> (<?php echo htmlspecialchars($l['variety']); ?>)<?php endif; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-5">
                            <?php if ($l['photo']): ?>
                                <img src="/AgriMatch/<?php echo htmlspecialchars($l['photo']); ?>"
                                     class="img-fluid rounded mb-3" alt="<?php echo htmlspecialchars($l['crop_type']); ?>">
                            <?php else: ?>
                                <div class="bg-light d-flex align-items-center justify-content-center rounded mb-3" style="height: 180px;">
                                    <i class="bi bi-image text-muted" style="font-size: 2.5rem;"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-7">
                            <p><strong>Farmer:</strong> <?php echo htmlspecialchars($l['farmer_name']); ?></p>
                            <p><strong>Phone:</strong> <?php echo htmlspecialchars($l['farmer_phone']); ?></p>
                            <p><strong>Email:</strong> <?php echo htmlspecialchars($l['farmer_email']); ?></p>
                            <p><strong>Quantity:</strong> <?php echo number_format($l['quantity'], 2) . ' ' . htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?></p>
                            <p><strong>Quality Grade:</strong> <?php echo htmlspecialchars($gradeLabels[$l['quality_grade']] ?? $l['quality_grade']); ?></p>
                            <?php if ($l['price_per_unit']): ?>
                                <p><strong>Price:</strong> MWK <?php echo number_format($l['price_per_unit'], 2); ?> per <?php echo htmlspecialchars($unitLabels[$l['unit']] ?? $l['unit']); ?></p>
                            <?php endif; ?>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($l['location']); ?></p>
                            <p><strong>Available:</strong>
                                <?php echo date('d M Y', strtotime($l['available_from'])); ?>
                                <?php if ($l['available_until']): ?>
                                    &ndash; <?php echo date('d M Y', strtotime($l['available_until'])); ?>
                                <?php endif; ?>
                            </p>
                            <p><strong>Status:</strong>
                                <span class="badge bg-<?php
                                    echo match($l['status']) {
                                        'available' => 'success',
                                        'matched'   => 'info',
                                        'sold'      => 'secondary',
                                        'withdrawn' => 'danger',
                                        default     => 'secondary'
                                    };
                                ?>"><?php echo ucfirst($l['status']); ?></span>
                            </p>
                        </div>
                    </div>
                    <?php if ($l['description']): ?>
                        <hr>
                        <p><strong>Description:</strong></p>
                        <p><?php echo nl2br(htmlspecialchars($l['description'])); ?></p>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <?php if ($l['status'] !== 'withdrawn'): ?>
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Withdraw this listing from the marketplace?');">
                            <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                            <input type="hidden" name="action" value="withdraw">
                            <button type="submit" class="btn btn-warning">
                                <i class="bi bi-slash-circle"></i> Withdraw
                            </button>
                        </form>
                    <?php endif; ?>
                    <form method="POST" class="d-inline"
                          onsubmit="return confirm('Permanently delete this listing? This cannot be undone.');">
                        <input type="hidden" name="listing_id" value="<?php echo $l['listing_id']; ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-trash"></i> Delete
                        </button>
                    </form>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>