<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Guard: only logged-in buyers allowed
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Get this buyer's buyer_id
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

// ---------- HANDLE STATUS UPDATE / DELETE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demand_id'], $_POST['action'])) {
    $demandId = (int) $_POST['demand_id'];
    $action   = $_POST['action'];

    // Always verify the demand belongs to this buyer before touching it
    $stmt = $conn->prepare("SELECT demand_id FROM demands WHERE demand_id = ? AND buyer_id = ?");
    $stmt->bind_param('ii', $demandId, $buyerId);
    $stmt->execute();
    $demand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($demand) {
        if ($action === 'mark_fulfilled') {
            $stmt = $conn->prepare("UPDATE demands SET status = 'fulfilled' WHERE demand_id = ?");
            $stmt->bind_param('i', $demandId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Demand marked as fulfilled.'];

        } elseif ($action === 'close') {
            $stmt = $conn->prepare("UPDATE demands SET status = 'closed' WHERE demand_id = ?");
            $stmt->bind_param('i', $demandId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Demand closed.'];

        } elseif ($action === 'reopen') {
            $stmt = $conn->prepare("UPDATE demands SET status = 'open' WHERE demand_id = ?");
            $stmt->bind_param('i', $demandId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Demand reopened.'];

        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM demands WHERE demand_id = ?");
            $stmt->bind_param('i', $demandId);
            $stmt->execute();
            $stmt->close();
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Demand deleted permanently.'];
        }
    }

    header('Location: /AgriMatch/buyer/my_demands.php');
    exit;
}

// ---------- FETCH ALL DEMANDS FOR THIS BUYER ----------
$stmt = $conn->prepare("
    SELECT demand_id, crop_type, min_quantity, unit, preferred_quality, max_price_per_unit,
           preferred_location, needed_by, frequency, description, status, date_posted
    FROM demands
    WHERE buyer_id = ?
    ORDER BY 
        CASE status WHEN 'open' THEN 0 WHEN 'matched' THEN 1 WHEN 'fulfilled' THEN 2 ELSE 3 END,
        date_posted DESC
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
    'kg' => 'kg',
    'tonnes' => 'tonnes',
    'bags_50kg' => '50kg bags',
    'bags_90kg' => '90kg bags',
    'crates' => 'crates'
];

$qualityLabels = [
    'any' => 'Any Grade',
    'grade_a' => 'Grade A Only',
    'grade_b' => 'Grade B or Better',
    'grade_c' => 'Grade C or Better'
];

$frequencyLabels = [
    'one_time' => 'One-Time',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'ongoing' => 'Ongoing'
];

$pageTitle = 'My Demands';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="bi bi-clipboard-check-fill"></i> My Demands</h2>
    <a href="/AgriMatch/buyer/post_demand.php" class="btn btn-success">
        <i class="bi bi-plus-circle"></i> Post New Demand
    </a>
</div>

<?php if (empty($demands)): ?>
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-clipboard-x text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-3">You haven't posted any demands yet.</p>
            <a href="/AgriMatch/buyer/post_demand.php" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Post Your First Demand
            </a>
        </div>
    </div>
<?php else: ?>

    <div class="row g-4">
        <?php foreach ($demands as $d): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($d['crop_type']); ?></h5>
                            <span class="badge bg-<?php
                                echo match($d['status']) {
                                    'open'      => 'success',
                                    'matched'   => 'info',
                                    'fulfilled' => 'secondary',
                                    'closed'    => 'danger',
                                    default     => 'secondary'
                                };
                            ?>">
                                <?php echo ucfirst($d['status']); ?>
                            </span>
                        </div>

                        <p class="mb-1">
                            <i class="bi bi-box-seam"></i>
                            Min. <strong><?php echo number_format($d['min_quantity'], 2); ?></strong>
                            <?php echo htmlspecialchars($unitLabels[$d['unit']] ?? $d['unit']); ?>
                        </p>

                        <p class="mb-1">
                            <i class="bi bi-award"></i> <?php echo htmlspecialchars($qualityLabels[$d['preferred_quality']] ?? $d['preferred_quality']); ?>
                        </p>

                        <?php if ($d['max_price_per_unit']): ?>
                            <p class="mb-1">
                                <i class="bi bi-cash"></i>
                                Up to MWK <?php echo number_format($d['max_price_per_unit'], 2); ?> per <?php echo htmlspecialchars($unitLabels[$d['unit']] ?? $d['unit']); ?>
                            </p>
                        <?php endif; ?>

                        <p class="mb-1"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($d['preferred_location']); ?></p>

                        <p class="mb-1">
                            <i class="bi bi-arrow-repeat"></i> <?php echo htmlspecialchars($frequencyLabels[$d['frequency']] ?? $d['frequency']); ?>
                        </p>

                        <?php if ($d['needed_by']): ?>
                            <p class="mb-2 text-muted small">
                                <i class="bi bi-calendar-event"></i> Needed by: <?php echo date('d M Y', strtotime($d['needed_by'])); ?>
                            </p>
                        <?php endif; ?>

                        <?php if ($d['description']): ?>
                            <p class="mb-3 small"><?php echo nl2br(htmlspecialchars($d['description'])); ?></p>
                        <?php endif; ?>

                        <div class="mt-auto d-flex flex-wrap gap-2">
                            <?php if ($d['status'] === 'open'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="demand_id" value="<?php echo $d['demand_id']; ?>">
                                    <input type="hidden" name="action" value="mark_fulfilled">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-check-circle"></i> Mark Fulfilled
                                    </button>
                                </form>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Close this demand?');">
                                    <input type="hidden" name="demand_id" value="<?php echo $d['demand_id']; ?>">
                                    <input type="hidden" name="action" value="close">
                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                        <i class="bi bi-slash-circle"></i> Close
                                    </button>
                                </form>
                            <?php elseif ($d['status'] === 'closed'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="demand_id" value="<?php echo $d['demand_id']; ?>">
                                    <input type="hidden" name="action" value="reopen">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reopen
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this demand permanently? This cannot be undone.');">
                                <input type="hidden" name="demand_id" value="<?php echo $d['demand_id']; ?>">
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