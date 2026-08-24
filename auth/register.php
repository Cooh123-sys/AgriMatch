<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: /AgriMatch/{$_SESSION['role']}/dashboard.php");
    exit;
}

$errors = [];
$old = $_POST; // repopulate form on error

// ---------- FILE UPLOAD HELPER ----------
// $personName = full name (farmer) or company name (buyer), used to build the saved filename
function uploadFile($fileInputName, $destFolder, $personName, &$errors) {
    if (empty($_FILES[$fileInputName]['name'])) return null;

    $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    $fileTmp  = $_FILES[$fileInputName]['tmp_name'];
    $fileName = $_FILES[$fileInputName]['name'];
    $fileSize = $_FILES[$fileInputName]['size'];
    $ext      = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        $errors[] = ucfirst(str_replace('_', ' ', $fileInputName)) . ': only PDF, JPG, PNG files are allowed.';
        return null;
    }
    if ($fileSize > $maxSize) {
        $errors[] = ucfirst(str_replace('_', ' ', $fileInputName)) . ': file must be under 5MB.';
        return null;
    }

    // Sanitize the person's name for use in a filename (letters, numbers only, spaces -> underscores)
    $safeName = preg_replace('/[^A-Za-z0-9]+/', '_', trim($personName));
    $safeName = trim($safeName, '_');
    if ($safeName === '') $safeName = 'user';

    // Label which document this is (e.g. id-document, map-document, business-certificate)
    $docLabel = str_replace('_', '-', $fileInputName);

    // Short random suffix so re-uploads or same-name users don't overwrite each other
    $suffix = substr(uniqid(), -5);

    $newName  = $safeName . '_' . $docLabel . '_' . $suffix . '.' . $ext;
    $destPath = __DIR__ . '/../assets/' . $destFolder . '/' . $newName;

    if (!move_uploaded_file($fileTmp, $destPath)) {
        $errors[] = 'Failed to upload ' . str_replace('_', ' ', $fileInputName) . '.';
        return null;
    }

    // Path stored in DB (relative, used in <a href> links later)
    return 'assets/' . $destFolder . '/' . $newName;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $role = $_POST['role'] ?? '';

    // ---------- COMMON FIELDS ----------
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!in_array($role, ['farmer', 'buyer'])) {
        $errors[] = 'Please select whether you are a Farmer or a Buyer.';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (empty($phone)) {
        $errors[] = 'Phone number is required.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Check email uniqueness
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $errors[] = 'An account with this email already exists.';
        }
        $stmt->close();
    }

    // ---------- ROLE-SPECIFIC VALIDATION ----------
    $full_name = '';
    $location = '';
    $crops = [];
    $other_crop = '';
    $company_name = '';
    $address = '';
    $org_type = '';

    if ($role === 'farmer') {
        $full_name  = trim($_POST['full_name'] ?? '');
        $location   = trim($_POST['location'] ?? '');
        $crops      = $_POST['crop_types'] ?? [];
        $other_crop = trim($_POST['other_crop'] ?? '');

        if (empty($full_name))  $errors[] = 'Full name is required.';
        if (empty($location))   $errors[] = 'Location is required.';
        if (empty($crops) && empty($other_crop)) {
            $errors[] = 'Select at least one crop type.';
        }
        if (empty($_FILES['id_document']['name'])) {
            $errors[] = 'National ID document is required.';
        }
        if (empty($_FILES['map_document']['name'])) {
            $errors[] = 'Map to home document is required.';
        }

    } elseif ($role === 'buyer') {
        $company_name = trim($_POST['company_name'] ?? '');
        $address      = trim($_POST['physical_address'] ?? '');
        $org_type     = $_POST['organization_type'] ?? '';

        $validOrgTypes = ['school','hotel','manufacturing_company','hospital','retailer','wholesaler','exporter','other'];

        if (empty($company_name)) $errors[] = 'Company name is required.';
        if (empty($address))      $errors[] = 'Physical address is required.';
        if (!in_array($org_type, $validOrgTypes)) $errors[] = 'Select a valid organisation type.';
        if (empty($_FILES['business_certificate']['name'])) {
            $errors[] = 'Business certificate is required.';
        }
    }

    // ---------- PROCESS UPLOADS + INSERT ----------
    if (empty($errors)) {

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $idDocPath = $mapDocPath = $certPath = null;

        if ($role === 'farmer') {
            $idDocPath  = uploadFile('id_document', 'farmer_docs', $full_name, $errors);
            $mapDocPath = uploadFile('map_document', 'farmer_docs', $full_name, $errors);
        } else {
            $certPath = uploadFile('business_certificate', 'buyer_docs', $company_name, $errors);
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                if ($role === 'farmer') {
                    // Insert into users
                    $stmt = $conn->prepare("INSERT INTO users (role, full_name, email, phone, password, status) VALUES ('farmer', ?, ?, ?, ?, 'pending')");
                    $stmt->bind_param('ssss', $full_name, $email, $phone, $hashedPassword);
                    $stmt->execute();
                    $userId = $conn->insert_id;
                    $stmt->close();

                    // Insert into farmer_details
                    $stmt = $conn->prepare("INSERT INTO farmer_details (user_id, location, id_document, map_document) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('isss', $userId, $location, $idDocPath, $mapDocPath);
                    $stmt->execute();
                    $farmerId = $conn->insert_id;
                    $stmt->close();

                    // Insert crop types (selected checkboxes + optional "other")
                    $allCrops = $crops;
                    if (!empty($other_crop)) {
                        $allCrops[] = $other_crop;
                    }
                    $stmt = $conn->prepare("INSERT INTO farmer_crops (farmer_id, crop_type) VALUES (?, ?)");
                    foreach ($allCrops as $cropType) {
                        $cropType = trim($cropType);
                        if ($cropType === '') continue;
                        $stmt->bind_param('is', $farmerId, $cropType);
                        $stmt->execute();
                    }
                    $stmt->close();

                } else {
                    // Insert into users (company_name stored as full_name)
                    $stmt = $conn->prepare("INSERT INTO users (role, full_name, email, phone, password, status) VALUES ('buyer', ?, ?, ?, ?, 'pending')");
                    $stmt->bind_param('ssss', $company_name, $email, $phone, $hashedPassword);
                    $stmt->execute();
                    $userId = $conn->insert_id;
                    $stmt->close();

                    // Insert into buyer_details
                    $stmt = $conn->prepare("INSERT INTO buyer_details (user_id, physical_address, organization_type, business_certificate) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param('isss', $userId, $address, $org_type, $certPath);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();

                $_SESSION['flash'] = [
                    'type' => 'success',
                    'msg'  => 'Registration successful! Your account is pending admin verification. You may log in once approved.'
                ];
                header('Location: /AgriMatch/auth/login.php');
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

$pageTitle = 'Register';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card shadow-sm mt-4 mb-5">
            <div class="card-body p-4">
                <h3 class="text-center mb-4">
                    <i class="bi bi-person-plus"></i> Create an AgriMatch Account
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

                <form method="POST" action="/AgriMatch/auth/register.php" enctype="multipart/form-data">

                    <!-- ROLE SELECTOR -->
                    <label class="form-label fw-bold">I am registering as a:</label>
                    <div class="d-flex gap-4 mb-4">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="roleFarmer" value="farmer"
                                <?php echo (($old['role'] ?? '') === 'farmer') ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="roleFarmer">Farmer</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="role" id="roleBuyer" value="buyer"
                                <?php echo (($old['role'] ?? '') === 'buyer') ? 'checked' : ''; ?> required>
                            <label class="form-check-label" for="roleBuyer">Buyer</label>
                        </div>
                    </div>

                    <!-- ===================== FARMER FIELDS ===================== -->
                    <div id="farmerFields" style="display:none;">
                        <hr>
                        <h5 class="text-success mb-3"><i class="bi bi-flower2"></i> Farmer Details</h5>

                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" name="full_name"
                                   value="<?php echo htmlspecialchars($old['full_name'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Location</label>
                            <input type="text" class="form-control" name="location" placeholder="e.g. Lunzu, Blantyre Rural"
                                   value="<?php echo htmlspecialchars($old['location'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Crop Types (select all that apply)</label>
                            <div class="row">
                                <?php
                                $cropOptions = ['Maize', 'Groundnuts', 'Beans', 'Soya', 'Rice', 'Tobacco', 'Vegetables', 'Fruits'];
                                $selectedCrops = $old['crop_types'] ?? [];
                                foreach ($cropOptions as $crop):
                                ?>
                                    <div class="col-6 col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="crop_types[]"
                                                   value="<?php echo $crop; ?>" id="crop_<?php echo $crop; ?>"
                                                   <?php echo in_array($crop, $selectedCrops) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="crop_<?php echo $crop; ?>"><?php echo $crop; ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <label class="form-label mt-2">Other crop (optional)</label>
                            <input type="text" class="form-control" name="other_crop" placeholder="e.g. Cassava"
                                   value="<?php echo htmlspecialchars($old['other_crop'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">National ID Document</label>
                            <input type="file" class="form-control" name="id_document" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">PDF, JPG or PNG, max 5MB.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Map / Directions to Home</label>
                            <input type="file" class="form-control" name="map_document" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">A photo, sketch, or PDF showing how to locate your farm.</small>
                        </div>
                    </div>

                    <!-- ===================== BUYER FIELDS ===================== -->
                    <div id="buyerFields" style="display:none;">
                        <hr>
                        <h5 class="text-success mb-3"><i class="bi bi-building"></i> Buyer Details</h5>

                        <div class="mb-3">
                            <label class="form-label">Company / Organisation Name</label>
                            <input type="text" class="form-control" name="company_name"
                                   value="<?php echo htmlspecialchars($old['company_name'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Physical Address</label>
                            <input type="text" class="form-control" name="physical_address"
                                   value="<?php echo htmlspecialchars($old['physical_address'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Organisation Type</label>
                            <select class="form-select" name="organization_type">
                                <option value="">-- Select --</option>
                                <?php
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
                                $selectedOrg = $old['organization_type'] ?? '';
                                foreach ($orgTypes as $val => $label):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $selectedOrg === $val ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Business Certificate</label>
                            <input type="file" class="form-control" name="business_certificate" accept=".pdf,.jpg,.jpeg,.png">
                            <small class="text-muted">PDF, JPG or PNG, max 5MB.</small>
                        </div>
                    </div>

                    <!-- ===================== COMMON FIELDS ===================== -->
                    <hr>
                    <div class="mb-3">
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control" name="email"
                               value="<?php echo htmlspecialchars($old['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" class="form-control" name="phone"
                               value="<?php echo htmlspecialchars($old['phone'] ?? ''); ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="password">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" name="confirm_password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success w-100 mt-2">Register</button>
                </form>

                <hr>
                <p class="text-center mb-0">
                    Already have an account? <a href="/AgriMatch/auth/login.php">Login here</a>
                </p>
            </div>
        </div>
    </div>
</div>

<script>
    const roleFarmer = document.getElementById('roleFarmer');
    const roleBuyer = document.getElementById('roleBuyer');
    const farmerFields = document.getElementById('farmerFields');
    const buyerFields = document.getElementById('buyerFields');

    function toggleFields() {
        if (roleFarmer.checked) {
            farmerFields.style.display = 'block';
            buyerFields.style.display = 'none';
        } else if (roleBuyer.checked) {
            buyerFields.style.display = 'block';
            farmerFields.style.display = 'none';
        }
    }

    roleFarmer.addEventListener('change', toggleFields);
    roleBuyer.addEventListener('change', toggleFields);

    // Restore correct panel on page reload after a validation error
    window.addEventListener('DOMContentLoaded', toggleFields);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>