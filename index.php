<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AgriMatch | Connect Farmers and Buyers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <style>
        :root {
            --agri-green: #176b45;
            --agri-cream: #f7f5ed;
            --agri-yellow: #f2bd4b;
        }

        body {
            background: var(--agri-cream);
            color: #17352a;
        }

        .navbar-brand {
            color: var(--agri-green) !important;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .hero {
            background: linear-gradient(135deg, #dfeee2 0%, #f7f5ed 62%, #f8df9d 100%);
            min-height: 62vh;
        }

        .hero-title {
            max-width: 720px;
            font-size: clamp(2.5rem, 6vw, 5rem);
            font-weight: 800;
            line-height: 1;
        }

        .role-card {
            border: 0;
            border-top: 5px solid var(--agri-green);
            box-shadow: 0 12px 30px rgba(23, 53, 42, .1);
        }

        .role-card.buyer {
            border-top-color: #c78425;
        }

        .icon-box {
            background: #e1f0e4;
            color: var(--agri-green);
            font-size: 1.8rem;
            height: 58px;
            width: 58px;
        }

        .buyer .icon-box {
            background: #f8e8bd;
            color: #9a6414;
        }

        .btn-agri {
            background: var(--agri-green);
            color: #fff;
        }

        .btn-agri:hover {
            background: #0f5133;
            color: #fff;
        }

        footer {
            background: #17352a;
        }
    </style>
</head>
<body class="d-flex flex-column min-vh-100">
    <nav class="navbar bg-white border-bottom py-3">
        <div class="container">
            <a class="navbar-brand fs-3" href="index.php">
                <i class="bi bi-leaf-fill me-2"></i>AgriMatch
            </a>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-success" href="auth/login.php">Login</a>
                <a class="btn btn-agri" href="auth/register.php">Register</a>
            </div>
        </div>
    </nav>

    <main class="flex-grow-1">
        <section class="hero d-flex align-items-center">
            <div class="container py-5">
                <span class="badge rounded-pill text-bg-warning mb-3">A better way to trade locally</span>
                <h1 class="hero-title mb-4">Fresh connections for a stronger harvest.</h1>
                <p class="lead mb-5" style="max-width: 600px;">
                    AgriMatch brings smallholder farmers and buyers together, making it easier to find produce,
                    create opportunities, and grow business in rural Blantyre.
                </p>

                <div class="row g-4" id="get-started">
                    <div class="col-md-6">
                        <div class="role-card card h-100 p-4">
                            <div class="icon-box rounded-circle d-flex align-items-center justify-content-center mb-4">
                                <i class="bi bi-flower1"></i>
                            </div>
                            <h2 class="h4">I am a farmer</h2>
                            <p class="text-secondary">List your produce, reach reliable buyers, and discover matching opportunities.</p>
                            <div class="mt-auto pt-3 d-flex gap-2">
                                <a class="btn btn-agri" href="auth/register.php?role=farmer">Register as a farmer</a>
                                <a class="btn btn-outline-success" href="auth/login.php?role=farmer">Login</a>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="role-card buyer card h-100 p-4">
                            <div class="icon-box rounded-circle d-flex align-items-center justify-content-center mb-4">
                                <i class="bi bi-shop"></i>
                            </div>
                            <h2 class="h4">I am a buyer</h2>
                            <p class="text-secondary">Post your demand, find nearby farmers, and source the produce your business needs.</p>
                            <div class="mt-auto pt-3 d-flex gap-2">
                                <a class="btn btn-warning" href="auth/register.php?role=buyer">Register as a buyer</a>
                                <a class="btn btn-outline-dark" href="auth/login.php?role=buyer">Login</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>