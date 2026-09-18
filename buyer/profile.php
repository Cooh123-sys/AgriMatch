<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'buyer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// ---------- FETCH CURRENT PROFILE ----------
$stmt = $conn->prepare("
    SELECT u.full_name AS company_name, u.email, u.phone, u.status,
           b.buyer_id, b.physical_address, b.organization_type, b.business_certificate
    FROM users u
    JOIN buyer_details b ON b.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$buyer = $stmt->get_result()->fetch_assoc();
$stmt->close();

$buyerId = $buyer['buyer_id'];

$orgTypes = [
    'school' => 'School',
    'hotel' => 'Hotel',
    'manufacturing_company' => 'Manufacturing Company',
    'hospital' => 'Hospital',
    'retailer' => 'Retailer',
    'wholesaler' => 'Wholesaler',
    'exporter' => 'Exporter',
    'other' => 'Other'
];

$errors = [];

// ---------- FILE UPLOAD HELPER ----------
function uploadDoc($fileInputName, $destFolder, $personName, &$errors) {
    if (empty($_FILES[$fileInputName]['name'])) return null;

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024;

    $ext = strtolower(pathinfo($_FILES[$fileInputName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) {
        $errors[] = ucfirst(str_replace('_', ' ', $fileInputName)) . ': only PDF, JPG, PNG allowed.';
        return null;
    }
    if ($_FILES[$fileInputName]['size'] > $maxSize) {
        $errors[] = ucfirst(str_replace('_', ' ', $fileInputName)) . ': file must be under 5MB.';
        return null;
    }

    $safeName = preg_replace('/[^A-Za-z0-9]+/', '_', trim($personName));
    $safeName = trim($safeName, '_') ?: 'user';
    $docLabel = str_replace('_', '-', $fileInputName);
    $suffix   = substr(uniqid(), -5);
    $newName  = $safeName . '_' . $docLabel . '_' . $suffix . '.' . $ext;
    $destPath = __DIR__ . '/../assets/' . $destFolder . '/' . $newName;

    if (!move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $destPath)) {
        $errors[] = 'Failed to upload ' . str_replace('_', ' ', $fileInputName) . '.';
        return null;
    }
    return 'assets/' . $destFolder . '/' . $newName;
}

// ---------- HANDLE FORM SUBMISSION ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $phone       = trim($_POST['phone'] ?? '');
    $address     = trim($_POST['physical_address'] ?? '');
    $orgType     = $_POST['organization_type'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    $validOrgTypes = array_keys($orgTypes);

    if (empty($phone))   $errors[] = 'Phone number is required.';
    if (empty($address)) $errors[] = 'Physical address is required.';
    if (!in_array($orgType, $validOrgTypes)) $errors[] = 'Select a valid organisation type.';

    if ($newPassword !== '' && strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }
    if ($newPassword !== '' && $newPassword !== $confirmPass) {
        $errors[] = 'New passwords do not match.';
    }

    // Optional certificate re-upload
    $newCert = uploadDoc('business_certificate', 'buyer_docs', $buyer['company_name'], $errors);
    $docsReuploaded = ($newCert !== null);

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE users SET phone = ? WHERE user_id = ?");
            $stmt->bind_param('si', $phone, $userId);
            $stmt->execute();
            $stmt->close();

            if ($newPassword !== '') {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt->bind_param('si', $hashed, $userId);
                $stmt->execute();
                $stmt->close();
            }

            $certToSave = $newCert ?? $buyer['business_certificate'];

            $stmt = $conn->prepare("UPDATE buyer_details SET physical_address = ?, organization_type = ?, business_certificate = ? WHERE buyer_id = ?");
            $stmt->bind_param('sssi', $address, $orgType, $certToSave, $buyerId);
            $stmt->execute();
            $stmt->close();

            if ($docsReuploaded && $buyer['status'] !== 'pending') {
                $stmt = $conn->prepare("UPDATE users SET status = 'pending' WHERE user_id = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();

                sendStatusEmail($buyer['email'], $buyer['company_name'], 'buyer', 'pending');
            }

            $conn->commit();

            $_SESSION['status'] = $docsReuploaded ? 'pending' : $buyer['status'];

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => $docsReuploaded
                    ? 'Profile updated. Since you re-uploaded your certificate, your account is pending re-verification.'
                    : 'Profile updated successfully.'
            ];
            header('Location: /AgriMatch/buyer/profile.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = 'Failed to update profile. Please try again.';
        }
    }
}

$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm mt-3 mb-5">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-person-lines-fill"></i> My Profile</h5>
            </div>
            <div class="card-body p-4">

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">

                    <h6 class="text-success fw-bold mb-3">Account Details</h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Company Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($buyer['company_name']); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($buyer['email']); ?>" disabled>
                            <small class="text-muted">Email cannot be changed — contact admin if this is incorrect.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?php echo htmlspecialchars($buyer['phone']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account Status</label><br>
                            <span class="badge bg-<?php
                                echo $buyer['status'] === 'verified' ? 'success' : ($buyer['status'] === 'pending' ? 'warning' : 'danger');
                            ?> fs-6"><?php echo ucfirst($buyer['status']); ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Physical Address <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="physical_address"
                               value="<?php echo htmlspecialchars($buyer['physical_address']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Organisation Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="organization_type" required>
                            <?php foreach ($orgTypes as $val => $label): ?>
                                <option value="<?php echo $val; ?>" <?php echo $buyer['organization_type'] === $val ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <hr>
                    <h6 class="text-success fw-bold mb-3">Verification Document</h6>
                    <p class="text-muted small">Only upload a new file if you need to replace your certificate. Re-uploading will send your account back for admin re-verification.</p>

                    <div class="mb-4">
                        <label class="form-label">Current Business Certificate</label><br>
                        <?php if ($buyer['business_certificate']): ?>
                            <a href="/AgriMatch/<?php echo htmlspecialchars($buyer['business_certificate']); ?>" target="_blank">View current file</a>
                        <?php else: ?>
                            <span class="text-muted">None on file</span>
                        <?php endif; ?>
                        <input type="file" class="form-control mt-2" name="business_certificate" accept=".pdf,.jpg,.jpeg,.png">
                    </div>

                    <hr>
                    <h6 class="text-success fw-bold mb-3">Change Password <small class="text-muted fw-normal">(optional)</small></h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">New Password</label>
                            <input type="password" class="form-control" name="new_password">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-save"></i> Save Changes
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>