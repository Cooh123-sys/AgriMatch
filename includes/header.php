<!-- <nav class="navbar navbar-expand-lg navbar-dark bg-success">
    <div class="container-fluid">
        <a class="navbar-brand" href="/comfort_agrimatch/index.php">AgriMatch</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">

                <?php if ($loggedIn && $role === 'farmer'): ?>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/farmer/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/farmer/add_produce.php">Add Produce</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/farmer/my_produce.php">My Produce</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/farmer/matches.php">My Matches</a></li>

                <?php elseif ($loggedIn && $role === 'buyer'): ?>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/buyer/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/buyer/post_demand.php">Post Demand</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/buyer/my_demands.php">My Demands</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/buyer/matched_farmers.php">Matched Farmers</a></li>

                <?php elseif ($loggedIn && $role === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="#">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Farmers</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Buyers</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Produce</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Matches</a></li>
                    <li class="nav-item"><a class="nav-link" href="#">Reports</a></li>
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
                        <a class="nav-link" href="/comfort_agrimatch/auth/logout.php">Logout</a>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/auth/login.php">Login</a></li>
                    <li class="nav-item"><a class="nav-link" href="/comfort_agrimatch/auth/register.php">Register</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container-fluid mt-3">
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
    <?php endif; ?> -->