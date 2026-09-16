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

// Fetch buyer record (need buyer_id, physical_address, and status)
$stmt = $conn->prepare("
    SELECT u.full_name AS company_name, u.status, b.buyer_id, b.physical_address
    FROM users u
    JOIN buyer_details b ON b.user_id = u.user_id
    WHERE u.user_id = ?
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$buyer = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Block unverified buyers from posting
if ($buyer['status'] !== 'verified') {
    $_SESSION['flash'] = [
        'type' => 'warning',
        'msg'  => 'Your account must be verified by an admin before you can post a demand.'
    ];
    header('Location: /AgriMatch/dashboard.php');
    exit;
}

$errors = [];
$old = $_POST;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cropType         = trim($_POST['crop_type'] ?? '');
    $minQuantity      = trim($_POST['min_quantity'] ?? '');
    $unit             = $_POST['unit'] ?? '';
    $preferredQuality = $_POST['preferred_quality'] ?? 'any';
    $maxPrice         = trim($_POST['max_price_per_unit'] ?? '');
    $preferredLocation= trim($_POST['preferred_location'] ?? '');
    $neededBy         = $_POST['needed_by'] ?? '';
    $frequency        = $_POST['frequency'] ?? 'one_time';
    $description      = trim($_POST['description'] ?? '');

    $validUnits      = ['kg','tonnes','bags_50kg','bags_90kg','crates'];
    $validQualities  = ['grade_a','grade_b','grade_c','any'];
    $validFrequencies= ['one_time','weekly','monthly','ongoing'];

    // ---------- VALIDATION ----------
    if ($cropType === '') {
        $errors[] = 'Crop type is required.';
    }
    if ($minQuantity === '' || !is_numeric($minQuantity) || $minQuantity <= 0) {
        $errors[] = 'Enter a valid minimum quantity greater than zero.';
    }
    if (!in_array($unit, $validUnits)) {
        $errors[] = 'Select a valid unit of measurement.';
    }
    if (!in_array($preferredQuality, $validQualities)) {
        $errors[] = 'Select a valid quality preference.';
    }
    if ($maxPrice !== '' && (!is_numeric($maxPrice) || $maxPrice < 0)) {
        $errors[] = 'Maximum price must be a valid number.';
    }
    if ($preferredLocation === '') {
        $errors[] = 'Preferred sourcing location is required.';
    }
    if ($neededBy !== '' && $neededBy < date('Y-m-d')) {
        $errors[] = 'Needed-by date cannot be in the past.';
    }
    if (!in_array($frequency, $validFrequencies)) {
        $errors[] = 'Select a valid supply frequency.';
    }

    // ---------- INSERT ----------
    if (empty($errors)) {
        $maxPriceVal    = $maxPrice !== '' ? $maxPrice : null;
        $neededByVal    = $neededBy !== '' ? $neededBy : null;
        $descriptionVal = $description !== '' ? $description : null;

        $stmt = $conn->prepare("
            INSERT INTO demands
                (buyer_id, crop_type, min_quantity, unit, preferred_quality, max_price_per_unit,
                 preferred_location, needed_by, frequency, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param(
            'isdsssssss',
            $buyer['buyer_id'],
            $cropType,
            $minQuantity,
            $unit,
            $preferredQuality,
            $maxPriceVal,
            $preferredLocation,
            $neededByVal,
            $frequency,
            $descriptionVal
        );

        if ($stmt->execute()) {
            $stmt->close();
            $_SESSION['flash'] = [
                'type' => 'success',
                'msg'  => 'Demand posted successfully! Matching farmers will be identified based on your requirements.'
            ];
            header('Location: /AgriMatch/buyer/my_demands.php');
            exit;
        } else {
            $errors[] = 'Failed to save demand. Please try again.';
            $stmt->close();
        }
    }
}

$pageTitle = 'Post Demand';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm mt-3 mb-5">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-clipboard-check-fill"></i> Post New Demand</h5>
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

                <form method="POST">

                    <!-- ---------- REQUIREMENT DETAILS ---------- -->
                    <h6 class="text-success fw-bold mb-3">Requirement Details</h6>

                    <div class="mb-3">
                        <label class="form-label">Crop Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="crop_type" required>
                            <option value="">-- Select crop --</option>
                            <?php
                            $standardCrops = ['Maize','Groundnuts','Beans','Soya','Rice','Tobacco','Vegetables','Fruits','Cassava','Sweet Potatoes'];
                            $selectedCrop = $old['crop_type'] ?? '';
                            foreach ($standardCrops as $c):
                            ?>
                                <option value="<?php echo htmlspecialchars($c); ?>"
                                    <?php echo $selectedCrop === $c ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Minimum Quantity Needed <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" class="form-control" name="min_quantity"
                                   placeholder="e.g. 1000"
                                   value="<?php echo htmlspecialchars($old['min_quantity'] ?? ''); ?>" required>
                            <small class="text-muted">Only farmers who can supply at least this quantity will be matched.</small>
                        </div>

                        <div class="col-md-6 mb-3">
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
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Preferred Quality Grade</label>
                            <select class="form-select" name="preferred_quality">
                                <?php
                                $qualities = [
                                    'any' => 'Any Grade',
                                    'grade_a' => 'Grade A (Premium) Only',
                                    'grade_b' => 'Grade B (Standard) or Better',
                                    'grade_c' => 'Grade C (Basic) or Better'
                                ];
                                $selectedQuality = $old['preferred_quality'] ?? 'any';
                                foreach ($qualities as $val => $label):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $selectedQuality === $val ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Maximum Price per Unit (MWK) <small class="text-muted">(optional)</small></label>
                            <input type="number" step="0.01" min="0" class="form-control" name="max_price_per_unit"
                                   placeholder="e.g. 1500"
                                   value="<?php echo htmlspecialchars($old['max_price_per_unit'] ?? ''); ?>">
                            <small class="text-muted">Leave blank if open to negotiation.</small>
                        </div>
                    </div>

                    <hr>

                    <!-- ---------- SOURCING PREFERENCES ---------- -->
                    <h6 class="text-success fw-bold mb-3">Sourcing Preferences</h6>

                    <div class="mb-3">
                        <label class="form-label">Preferred Sourcing Location <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="preferred_location"
                               placeholder="e.g. Lunzu, Blantyre Rural"
                               value="<?php echo htmlspecialchars($old['preferred_location'] ?? $buyer['physical_address']); ?>" required>
                        <small class="text-muted">Only farmers within this area will be matched (Level 3 of our matching system).</small>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Needed By <small class="text-muted">(optional)</small></label>
                            <input type="date" class="form-control" name="needed_by"
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo htmlspecialchars($old['needed_by'] ?? ''); ?>">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Supply Frequency</label>
                            <select class="form-select" name="frequency">
                                <?php
                                $frequencies = [
                                    'one_time' => 'One-Time Purchase',
                                    'weekly' => 'Weekly Supply',
                                    'monthly' => 'Monthly Supply',
                                    'ongoing' => 'Ongoing / Continuous'
                                ];
                                $selectedFreq = $old['frequency'] ?? 'one_time';
                                foreach ($frequencies as $val => $label):
                                ?>
                                    <option value="<?php echo $val; ?>" <?php echo $selectedFreq === $val ? 'selected' : ''; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <!-- ---------- ADDITIONAL NOTES ---------- -->
                    <h6 class="text-success fw-bold mb-3">Additional Information</h6>

                    <div class="mb-4">
                        <label class="form-label">Description / Special Requirements <small class="text-muted">(optional)</small></label>
                        <textarea class="form-control" name="description" rows="3"
                                  placeholder="e.g. Requires certified organic produce, delivery to our warehouse preferred."><?php echo htmlspecialchars($old['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Post Demand
                        </button>
                        <a href="/AgriMatch/dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>