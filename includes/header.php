<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

$loggedIn = isset($_SESSION['user_id']);
$role     = $_SESSION['role'] ?? null;
$name     = $_SESSION['name'] ?? '';
$status   = $_SESSION['status'] ?? null; // 'pending' | 'verified' | 'rejected'
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? htmlspecialchars($pageTitle) . ' - AgriMatch' : 'AgriMatch'; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="/AgriMatch/assets/css/style.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-success">
    <div class="container-fluid">
        <a class="navbar-brand" href="/AgriMatch/index.php">AgriMatch</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <?php if ($loggedIn && $role === 'farmer'): ?>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/farmer/add_produce.php">Add Produce</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/farmer/my_produce.php">My Produce</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/farmer/matches.php">My Matches</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/farmer/profile.php">Profile</a></li>

                <?php elseif ($loggedIn && $role === 'buyer'): ?>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/buyer/post_demand.php">Post Demand</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/buyer/my_demands.php">My Demands</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/buyer/matched_farmers.php">Matched Farmers</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/buyer/profile.php">Profile</a></li>

                <?php elseif ($loggedIn && $role === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/admin/manage_farmers.php">Farmers</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/admin/manage_buyers.php">Buyers</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/admin/manage_produce.php">Produce</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/admin/manage_matches.php">Matches</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/admin/reports.php">Reports</a></li>
                <?php endif; ?>

            </ul>

            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <?php if ($loggedIn): ?>
                    <li class="nav-item">
                        <span class="nav-link disabled text-white-50">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($name); ?>
                            (<?php echo htmlspecialchars(ucfirst($role)); ?>)
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/AgriMatch/auth/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/auth/login.php">Login</a></li>
                    <li class="nav-item"><a class="nav-link" href="/AgriMatch/auth/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-3">
    <?php
    // Pending verification banner for farmers/buyers whose documents haven't been approved yet
    if ($loggedIn && $role !== 'admin' && $status === 'pending'):
    ?>
        <div class="alert alert-warning" role="alert">
            <i class="bi bi-hourglass-split"></i>
            Your account is <strong>pending verification</strong>. An admin will review your submitted
            documents before you can <?php echo $role === 'farmer' ? 'post produce' : 'post demand'; ?>.
        </div>
    <?php endif; ?>

    <?php
    // Simple flash message support (set $_SESSION['flash'] = ['type'=>'success','msg'=>'...'] anywhere)
    if (isset($_SESSION['flash'])):
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
    ?>
        <div class="alert alert-<?php echo htmlspecialchars($flash['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($flash['msg']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>