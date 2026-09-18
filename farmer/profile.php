<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// ---------- FETCH CURRENT PROFILE ----------
$stmt = $conn->prepare("
    SELECT u.full_name, u.email, u.phone, u.status,
           f.farmer_id, f.location, f.id_document, f.map_document
    FROM users u
    JOIN farmer_details f ON f.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$farmer = $stmt->get_result()->fetch_assoc();
$stmt->close();

$farmerId = $farmer['farmer_id'];

// Current crop types
$myCrops = [];
$stmt = $conn->prepare("SELECT crop_type FROM farmer_crops WHERE farmer_id = ?");
$stmt->bind_param('i', $farmerId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $myCrops[] = $row['crop_type'];
}
$stmt->close();

$errors = [];
$success = false;

// ---------- FILE UPLOAD HELPER (same pattern as register.php) ----------
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
    $location    = trim($_POST['location'] ?? '');
    $crops       = $_POST['crop_types'] ?? [];
    $otherCrop   = trim($_POST['other_crop'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($phone))    $errors[] = 'Phone number is required.';
    if (empty($location)) $errors[] = 'Location is required.';
    if (empty($crops) && empty($otherCrop)) $errors[] = 'Select at least one crop type.';

    if ($newPassword !== '' && strlen($newPassword) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }
    if ($newPassword !== '' && $newPassword !== $confirmPass) {
        $errors[] = 'New passwords do not match.';
    }

    // Optional document re-uploads
    $newIdDoc  = uploadDoc('id_document', 'farmer_docs', $farmer['full_name'], $errors);
    $newMapDoc = uploadDoc('map_document', 'farmer_docs', $farmer['full_name'], $errors);
    $docsReuploaded = ($newIdDoc !== null || $newMapDoc !== null);

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // Update phone (email stays fixed as it's the login identifier)
            $stmt = $conn->prepare("UPDATE users SET phone = ? WHERE user_id = ?");
            $stmt->bind_param('si', $phone, $userId);
            $stmt->execute();
            $stmt->close();

            // If new password provided, hash and update
            if ($newPassword !== '') {
                $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt->bind_param('si', $hashed, $userId);
                $stmt->execute();
                $stmt->close();
            }

            // Update location + documents (keep old doc path if no new file uploaded)
            $idDocToSave  = $newIdDoc  ?? $farmer['id_document'];
            $mapDocToSave = $newMapDoc ?? $farmer['map_document'];

            $stmt = $conn->prepare("UPDATE farmer_details SET location = ?, id_document = ?, map_document = ? WHERE farmer_id = ?");
            $stmt->bind_param('sssi', $location, $idDocToSave, $mapDocToSave, $farmerId);
            $stmt->execute();
            $stmt->close();

            // Replace crop types
            $stmt = $conn->prepare("DELETE FROM farmer_crops WHERE farmer_id = ?");
            $stmt->bind_param('i', $farmerId);
            $stmt->execute();
            $stmt->close();

            $allCrops = $crops;
            if ($otherCrop !== '') $allCrops[] = $otherCrop;

            $stmt = $conn->prepare("INSERT INTO farmer_crops (farmer_id, crop_type) VALUES (?, ?)");
            foreach ($allCrops as $c) {
                $c = trim($c);
                if ($c === '') continue;
                $stmt->bind_param('is', $farmerId, $c);
                $stmt->execute();
            }
            $stmt->close();

            // If documents were re-uploaded, reset status to pending for re-verification
            if ($docsReuploaded && $farmer['status'] !== 'pending') {
                $stmt = $conn->prepare("UPDATE users SET status = 'pending' WHERE user_id = ?");
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $stmt->close();

                sendStatusEmail($farmer['email'], $farmer['full_name'], 'farmer', 'pending');
            }

            $conn->commit();
            $success = true;

            $_SESSION['name']   = $farmer['full_name']; // unchanged but keeps session fresh
            $_SESSION['status'] = $docsReuploaded ? 'pending' : $farmer['status'];

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => $docsReuploaded
                    ? 'Profile updated. Since you re-uploaded documents, your account is pending re-verification.'
                    : 'Profile updated successfully.'
            ];
            header('Location: /AgriMatch/farmer/profile.php');
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
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($farmer['full_name']); ?>" disabled>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($farmer['email']); ?>" disabled>
                            <small class="text-muted">Email cannot be changed — contact admin if this is incorrect.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="phone"
                                   value="<?php echo htmlspecialchars($farmer['phone']); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Account Status</label><br>
                            <span class="badge bg-<?php
                                echo $farmer['status'] === 'verified' ? 'success' : ($farmer['status'] === 'pending' ? 'warning' : 'danger');
                            ?> fs-6"><?php echo ucfirst($farmer['status']); ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="location"
                               value="<?php echo htmlspecialchars($farmer['location']); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Crop Types</label>
                        <div class="row">
                            <?php
                            $cropOptions = ['Maize', 'Groundnuts', 'Beans', 'Soya', 'Rice', 'Tobacco', 'Vegetables', 'Fruits'];
                            foreach ($cropOptions as $crop):
                            ?>
                                <div class="col-6 col-md-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="crop_types[]"
                                               value="<?php echo $crop; ?>" id="crop_<?php echo $crop; ?>"
                                               <?php echo in_array($crop, $myCrops) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="crop_<?php echo $crop; ?>"><?php echo $crop; ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php
                        $otherExisting = array_diff($myCrops, $cropOptions);
                        ?>
                        <label class="form-label mt-2">Other crop</label>
                        <input type="text" class="form-control" name="other_crop"
                               value="<?php echo htmlspecialchars(implode(', ', $otherExisting)); ?>">
                    </div>

                    <hr>
                    <h6 class="text-success fw-bold mb-3">Verification Documents</h6>
                    <p class="text-muted small">Only upload a new file if you need to replace an existing document. Re-uploading will send your account back for admin re-verification.</p>

                    <div class="mb-3">
                        <label class="form-label">Current National ID</label><br>
                        <?php if ($farmer['id_document']): ?>
                            <a href="/AgriMatch/<?php echo htmlspecialchars($farmer['id_document']); ?>" target="_blank">View current file</a>
                        <?php else: ?>
                            <span class="text-muted">None on file</span>
                        <?php endif; ?>
                        <input type="file" class="form-control mt-2" name="id_document" accept=".pdf,.jpg,.jpeg,.png">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Current Map to Home</label><br>
                        <?php if ($farmer['map_document']): ?>
                            <a href="/AgriMatch/<?php echo htmlspecialchars($farmer['map_document']); ?>" target="_blank">View current file</a>
                        <?php else: ?>
                            <span class="text-muted">None on file</span>
                        <?php endif; ?>
                        <input type="file" class="form-control mt-2" name="map_document" accept=".pdf,.jpg,.jpeg,.png">
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