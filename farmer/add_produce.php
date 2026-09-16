<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Guard: only verified farmers can post produce
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'farmer') {
    header('Location: /AgriMatch/auth/login.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Fetch farmer record (need farmer_id, location, and status)
$stmt = $conn->prepare("
    SELECT u.full_name, u.status, f.farmer_id, f.location
    FROM users u
    JOIN farmer_details f ON f.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$farmer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Block unverified farmers from posting
if ($farmer['status'] !== 'verified') {
    $_SESSION['flash'] = [
        'type' => 'warning',
        'msg'  => 'Your account must be verified by an admin before you can post produce.'
    ];
    header('Location: /AgriMatch/dashboard.php');
    exit;
}

// Farmer's registered crop types (used to populate the crop dropdown)
$myCrops = [];
$stmt = $conn->prepare("SELECT crop_type FROM farmer_crops WHERE farmer_id = ?");
$stmt->bind_param('i', $farmer['farmer_id']);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $myCrops[] = $row['crop_type'];
}
$stmt->close();

$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cropType      = trim($_POST['crop_type'] ?? '');
    $variety       = trim($_POST['variety'] ?? '');
    $quantity      = trim($_POST['quantity'] ?? '');
    $unit          = $_POST['unit'] ?? '';
    $pricePerUnit  = trim($_POST['price_per_unit'] ?? '');
    $qualityGrade  = $_POST['quality_grade'] ?? 'ungraded';
    $harvestDate   = $_POST['harvest_date'] ?? '';
    $availableFrom = $_POST['available_from'] ?? '';
    $availableUntil= $_POST['available_until'] ?? '';
    $location      = trim($_POST['location'] ?? '');
    $description   = trim($_POST['description'] ?? '');

    $validUnits  = ['kg','tonnes','bags_50kg','bags_90kg','crates'];
    $validGrades = ['grade_a','grade_b','grade_c','ungraded'];

    // ---------- VALIDATION ----------
    if ($cropType === '') {
        $errors[] = 'Crop type is required.';
    }
    if ($quantity === '' || !is_numeric($quantity) || $quantity <= 0) {
        $errors[] = 'Enter a valid quantity greater than zero.';
    }
    if (!in_array($unit, $validUnits)) {
        $errors[] = 'Select a valid unit of measurement.';
    }
    if ($pricePerUnit !== '' && (!is_numeric($pricePerUnit) || $pricePerUnit < 0)) {
        $errors[] = 'Price per unit must be a valid number.';
    }
    if (!in_array($qualityGrade, $validGrades)) {
        $errors[] = 'Select a valid quality grade.';
    }
    if ($availableFrom === '') {
        $errors[] = 'Available-from date is required.';
    }
    if ($availableUntil !== '' && $availableFrom !== '' && $availableUntil < $availableFrom) {
        $errors[] = 'Available-until date cannot be before the available-from date.';
    }
    if ($harvestDate !== '' && $harvestDate > date('Y-m-d')) {
        $errors[] = 'Harvest date cannot be in the future.';
    }
    if ($location === '') {
        $errors[] = 'Pickup location is required.';
    }

    // ---------- OPTIONAL PHOTO UPLOAD ----------
    $photoPath = null;
    if (!empty($_FILES['photo']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed)) {
            $errors[] = 'Produce photo must be a JPG or PNG image.';
        } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Produce photo must be under 5MB.';
        } else {
            $safeName = preg_replace('/[^A-Za-z0-9]+/', '_', $farmer['full_name']);
            $safeName = trim($safeName, '_') ?: 'farmer';
            $safeCrop = preg_replace('/[^A-Za-z0-9]+/', '_', $cropType);
            $newName  = $safeName . '_' . $safeCrop . '_' . substr(uniqid(), -5) . '.' . $ext;
            $destPath = __DIR__ . '/../assets/produce_photos/' . $newName;

            if (move_uploaded_file($_FILES['photo']['tmp_name'], $destPath)) {
                $photoPath = 'assets/produce_photos/' . $newName;
            } else {
                $errors[] = 'Failed to upload produce photo.';
            }
        }
    }

    // ---------- INSERT ----------
    if (empty($errors)) {
        $stmt = $conn->prepare("
            INSERT INTO produce_listings
                (farmer_id, crop_type, variety, quantity, unit, price_per_unit, quality_grade,
                 harvest_date, available_from, available_until, location, description, photo, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'available')
        ");

        // Convert empty optional fields to NULL
        $varietyVal     = $variety !== '' ? $variety : null;
        $priceVal       = $pricePerUnit !== '' ? $pricePerUnit : null;
        $harvestVal     = $harvestDate !== '' ? $harvestDate : null;
        $untilVal       = $availableUntil !== '' ? $availableUntil : null;
        $descriptionVal = $description !== '' ? $description : null;

        $stmt->bind_param(
            'issdsdsssssss',
            $farmer['farmer_id'],
            $cropType,
            $varietyVal,
            $quantity,
            $unit,
            $priceVal,
            $qualityGrade,
            $harvestVal,
            $availableFrom,
            $untilVal,
            $location,
            $descriptionVal,
            $photoPath
        );

        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => 'Produce posted successfully! Buyers can now be matched with your listing.'
            ];
            header('Location: /AgriMatch/farmer/my_produce.php');
            exit;
        } else {
            $errors[] = 'Failed to save produce listing. Please try again.';
            $stmt->close();
        }
    }
}

$pageTitle = 'Add Produce';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm mt-3 mb-5">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-basket-fill"></i> Post New Produce</h5>
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

                    <!-- ---------- CROP DETAILS ---------- -->
                    <h6 class="text-success fw-bold mb-3">Crop Details</h6>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Crop Type <span class="text-danger">*</span></label>
                            <select class="form-select" name="crop_type" required>
                                <option value="">-- Select crop --</option>
                                <?php
                                // Show farmer's registered crops first, then the standard list
                                $standardCrops = ['Maize','Groundnuts','Beans','Soya','Rice','Tobacco','Vegetables','Fruits','Cassava','Sweet Potatoes'];
                                $allCrops = array_unique(array_merge($myCrops, $standardCrops));
                                $selectedCrop = $old['crop_type'] ?? '';
                                foreach ($allCrops as $c):
                                ?>
                                    <option value="<?php echo htmlspecialchars($c); ?>"
                                        <?php echo $selectedCrop === $c ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($c); ?>
                                        <?php echo in_array($c, $myCrops) ? ' (registered)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Variety <small class="text-muted">(optional)</small></label>
                            <input type="text" class="form-control" name="variety"
                                   placeholder="e.g. DK8053, Chalimbana"
                                   value="<?php echo htmlspecialchars($old['variety'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quantity <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="quantity"
                                   placeholder="e.g. 500"
                                   value="<?php echo htmlspecialchars($old['quantity'] ?? ''); ?>" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label">Unit <span class="text-danger">*</span></label>
                            <select class="form-select" name="unit" required>
                                <?php
                                $units = [
                                    'kg' => 'Kilograms (kg)',
                                    'tonnes' => 'Tonnes',
                                    'bags_50kg' => '50kg Bags',
                                    'bags_90kg' => '90kg Bags',
                                    'crates' => 'Crates'
                                ];
                                $selectedUnit = $old['unit'] ?? 'kg';
                                foreach ($units as $val => $label):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $selectedUnit === $val ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label">Quality Grade <span class="text-danger">*</span></label>
                            <select class="form-select" name="quality_grade" required>
                                <?php
                                $grades = [
                                    'grade_a' => 'Grade A (Premium)',
                                    'grade_b' => 'Grade B (Standard)',
                                    'grade_c' => 'Grade C (Basic)',
                                    'ungraded' => 'Ungraded'
                                ];
                                $selectedGrade = $old['quality_grade'] ?? 'ungraded';
                                foreach ($grades as $val => $label):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $selectedGrade === $val ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Asking Price per Unit (MWK) <small class="text-muted">(optional)</small></label>
                        <input type="number" step="0.01" min="0" class="form-control" name="price_per_unit"
                               placeholder="e.g. 1200"
                               value="<?php echo htmlspecialchars($old['price_per_unit'] ?? ''); ?>">
                        <small class="text-muted">Leave blank if you'd prefer to negotiate with the buyer.</small>
                    </div>

                    <hr>

                    <!-- ---------- AVAILABILITY ---------- -->
                    <h6 class="text-success fw-bold mb-3">Availability</h6>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Harvest Date <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="harvest_date"
                                   max="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo htmlspecialchars($old['harvest_date'] ?? ''); ?>">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label">Available From <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="available_from"
                                   value="<?php echo htmlspecialchars($old['available_from'] ?? date('Y-m-d')); ?>" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label">Available Until <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="available_until"
                                   value="<?php echo htmlspecialchars($old['available_until'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Pickup Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="location"
                               placeholder="e.g. Lunzu Trading Centre, Blantyre Rural"
                               value="<?php echo htmlspecialchars($old['location'] ?? $farmer['location']); ?>" required>
                        <small class="text-muted">Defaults to your registered location — change it if the produce is stored elsewhere.</small>
                    </div>

                    <hr>

                    <!-- ---------- EXTRAS ---------- -->
                    <h6 class="text-success fw-bold mb-3">Additional Information</h6>

                    <div class="mb-3">
                        <label class="form-label">Description / Notes <small class="text-muted">(optional)</small></label>
                        <textarea class="form-control" name="description" rows="3"
                                  placeholder="e.g. Dried and sorted, stored in clean sacks, ready for immediate collection."><?php echo htmlspecialchars($old['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Produce Photo <small class="text-muted">(optional)</small></label>
                        <input type="file" class="form-control" name="photo" accept=".jpg,.jpeg,.png">
                        <small class="text-muted">JPG or PNG, max 5MB. A clear photo helps buyers assess quality.</small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Post Produce
                        </button>
                        <a href="/AgriMatch/dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>