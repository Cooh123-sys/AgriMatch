<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// If already logged in, redirect straight to their dashboard
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'];
    header("Location: /AgriMatch/dashboard.php");
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $errors[] = 'Please enter both email and password.';
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id, role, full_name, password, status FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {

                if ($user['status'] === 'rejected') {
                    $errors[] = 'Your account was rejected during verification. Please contact the administrator.';
                } else {
                    // Successful login
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['role']    = $user['role'];
                    $_SESSION['name']    = $user['full_name'];
                    $_SESSION['status']  = $user['status'];

                    $_SESSION['flash'] = [
                        'type' => 'success',
                        'msg'  => 'Welcome back, ' . $user['full_name'] . '!'
                    ];

                    header("Location: /AgriMatch/dashboard.php");
                    exit;
                }
            } else {
                $errors[] = 'Incorrect email or password.';
            }
        } else {
            $errors[] = 'Incorrect email or password.';
        }
        $stmt->close();
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-5">
        <div class="card shadow-sm mt-4">
            <div class="card-body p-4">
                <h3 class="text-center mb-4">
                    <i class="bi bi-box-arrow-in-right"></i> Login to AgriMatch
                </h3>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/AgriMatch/auth/login.php">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>

                    <button type="submit" class="btn btn-success w-100">Login</button>
                </form>

                <hr>
                <p class="text-center mb-0">
                    Don't have an account?
                    <a href="/AgriMatch/auth/register.php">Register here</a>
                </p>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>