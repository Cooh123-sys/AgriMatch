<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/db.php';

// Guard: must be logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];
$role   = $_SESSION['role'];

$pageTitle = ucfirst($role) . ' Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<h2 class="mb-4"><i class="bi bi-speedometer2"></i> <?php echo ucfirst($role); ?> Dashboard</h2>

<?php
// =====================================================
// FARMER DASHBOARD
// =====================================================
if ($role === 'farmer'):

    $stmt = $conn->prepare("
        SELECT u.full_name, u.email, u.phone, u.status, u.created_at,
               f.farmer_id, f.location, f.id_document, f.map_document
        FROM users u
        JOIN farmer_details f ON f.user_id = u.user_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $farmer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $crops = [];
    if ($farmer) {
        $stmt = $conn->prepare("SELECT crop_type FROM farmer_crops WHERE farmer_id = ?");
        $stmt->bind_param('i', $farmer['farmer_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $crops[] = $row['crop_type'];
        }
        $stmt->close();
    }
?>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle-fill text-success fs-1"></i>
                    <h5 class="mt-2">Account Status</h5>
                    <span class="badge bg-<?php
                        echo $farmer['status'] === 'verified' ? 'success' : ($farmer['status'] === 'pending' ? 'warning' : 'danger');
                    ?> fs-6">
                        <?php echo ucfirst($farmer['status']); ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-basket-fill text-success fs-1"></i>
                    <h5 class="mt-2">Crops Registered</h5>
                    <p class="fs-4 fw-bold mb-0"><?php echo count($crops); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-calendar-check-fill text-success fs-1"></i>
                    <h5 class="mt-2">Member Since</h5>
                    <p class="fs-6 mb-0"><?php echo date('d M Y', strtotime($farmer['created_at'])); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-person-lines-fill"></i> My Profile
                </div>
                <div class="card-body">
                    <p><strong>Full Name:</strong> <?php echo htmlspecialchars($farmer['full_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($farmer['email']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($farmer['phone']); ?></p>
                    <p><strong>Location:</strong> <?php echo htmlspecialchars($farmer['location']); ?></p>
                    <a href="/AgriMatch/farmer/profile.php" class="btn btn-outline-success btn-sm">Edit Profile</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-flower2"></i> My Crop Types
                </div>
                <div class="card-body">
                    <?php if (empty($crops)): ?>
                        <p class="text-muted mb-0">No crop types registered yet.</p>
                    <?php else: ?>
                        <?php foreach ($crops as $crop): ?>
                            <span class="badge bg-success me-1 mb-1"><?php echo htmlspecialchars($crop); ?></span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($farmer['status'] !== 'pending'): ?>
        <div class="mt-4">
            <a href="/AgriMatch/farmer/add_produce.php" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Post New Produce
            </a>
            <a href="/AgriMatch/farmer/my_produce.php" class="btn btn-outline-success">
                <i class="bi bi-list-ul"></i> View My Produce
            </a>
        </div>
    <?php endif; ?>

<?php
// =====================================================
// BUYER DASHBOARD
// =====================================================
elseif ($role === 'buyer'):

    $stmt = $conn->prepare("
        SELECT u.full_name AS company_name, u.email, u.phone, u.status, u.created_at,
               b.buyer_id, b.physical_address, b.organization_type, b.business_certificate
        FROM users u
        JOIN buyer_details b ON b.user_id = u.user_id
        WHERE u.user_id = ?
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $buyer = $stmt->get_result()->fetch_assoc();
    $stmt->close();

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
?>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle-fill text-success fs-1"></i>
                    <h5 class="mt-2">Account Status</h5>
                    <span class="badge bg-<?php
                        echo $buyer['status'] === 'verified' ? 'success' : ($buyer['status'] === 'pending' ? 'warning' : 'danger');
                    ?> fs-6">
                        <?php echo ucfirst($buyer['status']); ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-building-fill text-success fs-1"></i>
                    <h5 class="mt-2">Organisation Type</h5>
                    <p class="fs-6 fw-bold mb-0"><?php echo htmlspecialchars($orgLabels[$buyer['organization_type']] ?? 'N/A'); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <i class="bi bi-calendar-check-fill text-success fs-1"></i>
                    <h5 class="mt-2">Member Since</h5>
                    <p class="fs-6 mb-0"><?php echo date('d M Y', strtotime($buyer['created_at'])); ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-12">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-person-lines-fill"></i> Company Profile
                </div>
                <div class="card-body">
                    <p><strong>Company Name:</strong> <?php echo htmlspecialchars($buyer['company_name']); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($buyer['email']); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($buyer['phone']); ?></p>
                    <p><strong>Physical Address:</strong> <?php echo htmlspecialchars($buyer['physical_address']); ?></p>
                    <a href="/AgriMatch/buyer/profile.php" class="btn btn-outline-success btn-sm">Edit Profile</a>
                </div>
            </div>
        </div>
    </div>

    <?php if ($buyer['status'] !== 'pending'): ?>
        <div class="mt-4">
            <a href="/AgriMatch/buyer/post_demand.php" class="btn btn-success">
                <i class="bi bi-plus-circle"></i> Post New Demand
            </a>
            <a href="/AgriMatch/buyer/my_demands.php" class="btn btn-outline-success">
                <i class="bi bi-list-ul"></i> View My Demands
            </a>
        </div>
    <?php endif; ?>

<?php
// =====================================================
// ADMIN DASHBOARD
// =====================================================
elseif ($role === 'admin'):

    $totalFarmers    = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'farmer'")->fetch_assoc()['c'];
    $totalBuyers     = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'buyer'")->fetch_assoc()['c'];
    $pendingFarmers  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'farmer' AND status = 'pending'")->fetch_assoc()['c'];
    $pendingBuyers   = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'buyer' AND status = 'pending'")->fetch_assoc()['c'];
    $verifiedFarmers = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'farmer' AND status = 'verified'")->fetch_assoc()['c'];
    $verifiedBuyers  = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role = 'buyer' AND status = 'verified'")->fetch_assoc()['c'];

    $recent = $conn->query("
        SELECT full_name, role, status, created_at
        FROM users
        WHERE role IN ('farmer','buyer')
        ORDER BY created_at DESC
        LIMIT 5
    ");
?>

    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <i class="bi bi-people-fill text-success fs-1"></i>
                    <h6 class="mt-2">Total Farmers</h6>
                    <p class="fs-3 fw-bold mb-0"><?php echo $totalFarmers; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <i class="bi bi-building-fill text-success fs-1"></i>
                    <h6 class="mt-2">Total Buyers</h6>
                    <p class="fs-3 fw-bold mb-0"><?php echo $totalBuyers; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <i class="bi bi-hourglass-split text-warning fs-1"></i>
                    <h6 class="mt-2">Pending Farmers</h6>
                    <p class="fs-3 fw-bold mb-0"><?php echo $pendingFarmers; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <i class="bi bi-hourglass-split text-warning fs-1"></i>
                    <h6 class="mt-2">Pending Buyers</h6>
                    <p class="fs-3 fw-bold mb-0"><?php echo $pendingBuyers; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <i class="bi bi-check-circle-fill text-success fs-1"></i>
                    <h6 class="mt-2">Verified Farmers</h6>
                    <p class="fs-3 fw-bold mb-0"><?php echo $verifiedFarmers; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm text-center h-100">
                <div class="card-body">
                    <i class="bi bi-check-circle-fill text-success fs-1"></i>
                    <h6 class="mt-2">Verified Buyers</h6>
                    <p class="fs-3 fw-bold mb-0"><?php echo $verifiedBuyers; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-success text-white">
            <i class="bi bi-clock-history"></i> Recent Registrations
        </div>
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Registered On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recent->num_rows === 0): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No registrations yet.</td></tr>
                    <?php else: ?>
                        <?php while ($row = $recent->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo ucfirst($row['role']); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo $row['status'] === 'verified' ? 'success' : ($row['status'] === 'pending' ? 'warning' : 'danger');
                                    ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y, H:i', strtotime($row['created_at'])); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex gap-2">
        <a href="/AgriMatch/admin/manage_farmers.php" class="btn btn-success">
            <i class="bi bi-people"></i> Manage Farmers
        </a>
        <a href="/AgriMatch/admin/manage_buyers.php" class="btn btn-success">
            <i class="bi bi-building"></i> Manage Buyers
        </a>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>